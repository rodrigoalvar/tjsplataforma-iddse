<?php
/**
 * Versionado de informes al actualizar PDF/HTML desde recepción API o vinculación manual.
 * Alineado con api/informes/save.php (informes_historial + incremento de version).
 */

/**
 * HTML con botón Ver PDF (mismo patrón que attach-pdf / vincular).
 */
function irvh_buildAdjuntoPdfContenidoHtml(string $pdfRelativePath, string $tituloBoton): string
{
    $safePath = htmlspecialchars($pdfRelativePath, ENT_QUOTES, 'UTF-8');
    $safeTitle = htmlspecialchars($tituloBoton, ENT_QUOTES, 'UTF-8');

    return '<div class="informe-adjunto alert alert-info text-center">'
        . '<p><i class="fas fa-file-pdf fa-3x text-danger mb-3"></i></p>'
        . '<h5><strong>Informe PDF</strong></h5>'
        . '<p>Este informe fue vinculado desde un PDF recibido por API.</p>'
        . '<p class="mb-3">Haga clic para visualizar el documento.</p>'
        . '<button type="button" class="btn btn-primary btn-lg pdf-viewer-btn" data-pdf-path="' . $safePath . '" data-pdf-title="' . $safeTitle . '">'
        . '<i class="fas fa-eye me-2"></i>Ver PDF'
        . '</button></div>';
}

function irvh_normalizePdfPathCompare(?string $path): string
{
    $s = str_replace('\\', '/', trim((string)($path ?? '')));
    if ($s === '') {
        return '';
    }
    if (preg_match('#^https?://[^/]+/(.+)$#i', $s, $m)) {
        $s = $m[1];
    }

    return strtolower(ltrim($s, '/'));
}

function irvh_pathsMismaReferencia(?string $a, ?string $b): bool
{
    $na = irvh_normalizePdfPathCompare($a);
    $nb = irvh_normalizePdfPathCompare($b);

    return $na !== '' && $nb !== '' && $na === $nb;
}

/**
 * Quita bloques adjunto previos y añade el nuevo al final (conserva redacción clínica si existía).
 */
function irvh_mergeContenidoHtmlConNuevoAdjuntoPdf(?string $htmlActual, string $newPdfPath, string $tituloBoton): string
{
    $newPdfPath = trim($newPdfPath);
    $html = (string)($htmlActual ?? '');
    $stripped = preg_replace_callback(
        '#<div\s[^>]*\binforme-adjunto\b[^>]*>.*?</div>#is',
        static function (): string {
            return '';
        },
        $html
    );
    if (!is_string($stripped)) {
        $stripped = $html;
    }
    $stripped = trim($stripped);
    $adj = $newPdfPath !== '' ? irvh_buildAdjuntoPdfContenidoHtml($newPdfPath, $tituloBoton) : '';
    if ($stripped === '') {
        return $adj;
    }
    if ($adj === '') {
        return $stripped;
    }

    return $stripped . "\n" . $adj;
}

function irvh_estadoParaHistorial(?string $estado): string
{
    $e = strtolower(trim((string)($estado ?? '')));
    $allowed = ['borrador', 'transcripto', 'finalizado', 'revisado', 'firmado'];

    return in_array($e, $allowed, true) ? $e : 'borrador';
}

/**
 * Inserta fila en informes_historial (mismo criterio que save.php). No lanza si falla compatibilidad de columnas.
 */
function irvh_insertarHistorialPdfRecibido(
    PDO $db,
    array $informeBefore,
    int $usuarioModificacionId,
    string $motivoCambio
): void {
    $informeId = (int)($informeBefore['id'] ?? 0);
    if ($informeId <= 0) {
        return;
    }

    $estudioIdToSearch = $informeBefore['estudio_id'] ?? $informeBefore['study_instance_uid'] ?? $informeBefore['study_id'] ?? null;
    $medicoData = ['id' => null, 'nombre' => null, 'apellido' => null];
    $medicoInformanteRol = null;
    if ($estudioIdToSearch !== null && $estudioIdToSearch !== '') {
        try {
            $medicoInfoQuery = 'SELECT usuario_id, medico_informante_rol
                               FROM informes
                               WHERE (estudio_id = ? OR study_instance_uid = ? OR study_id = ?)
                               ORDER BY fecha_creacion ASC, id ASC
                               LIMIT 1';
            $medicoInfoStmt = $db->prepare($medicoInfoQuery);
            $medicoInfoStmt->execute([$estudioIdToSearch, $estudioIdToSearch, $estudioIdToSearch]);
            $medicoInfo = $medicoInfoStmt->fetch(PDO::FETCH_ASSOC);
            if ($medicoInfo && !empty($medicoInfo['usuario_id'])) {
                $medicoDataStmt = $db->prepare('SELECT id, nombre, apellido, rol FROM usuarios WHERE id = ?');
                $medicoDataStmt->execute([(int)$medicoInfo['usuario_id']]);
                $row = $medicoDataStmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $medicoData = $row;
                }
                $medicoInformanteRol = $medicoInfo['medico_informante_rol'] ?? ($medicoData['rol'] ?? null);
            }
        } catch (Throwable $e) {
            error_log('[IRVH] medico info: ' . $e->getMessage());
        }
    }

    $rolesHistorial = ['medico_informante', 'transcriptor', 'otro'];
    if ($medicoInformanteRol !== null && $medicoInformanteRol !== '' && !in_array((string)$medicoInformanteRol, $rolesHistorial, true)) {
        $medicoInformanteRol = null;
    }

    $transcriptorData = ['id' => null, 'nombre' => null, 'apellido' => null, 'rol' => null];
    try {
        $transcriptorDataStmt = $db->prepare('SELECT id, nombre, apellido, rol FROM usuarios WHERE id = ?');
        $transcriptorDataStmt->execute([$usuarioModificacionId]);
        $t = $transcriptorDataStmt->fetch(PDO::FETCH_ASSOC);
        if ($t) {
            $transcriptorData = $t;
        }
    } catch (Throwable $e) {
        error_log('[IRVH] transcriptor: ' . $e->getMessage());
    }
    if (!empty($transcriptorData['rol']) && !in_array((string)$transcriptorData['rol'], $rolesHistorial, true)) {
        $transcriptorData['rol'] = null;
    }

    $versionAnt = (int)($informeBefore['version'] ?? 1);
    $htmlAnt = (string)($informeBefore['contenido_html'] ?? '');
    $estadoAnt = irvh_estadoParaHistorial($informeBefore['estado'] ?? null);

    $fullSql = 'INSERT INTO informes_historial
              (informe_id, version_anterior, contenido_html_anterior, estado_anterior,
               usuario_modificacion, motivo_cambio,
               medico_informante_id, medico_informante_nombre, medico_informante_apellido, medico_informante_rol,
               transcriptor_id, transcriptor_nombre, transcriptor_apellido, transcriptor_rol)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    $fullParams = [
        $informeId,
        $versionAnt,
        $htmlAnt,
        $estadoAnt,
        $usuarioModificacionId,
        $motivoCambio,
        $medicoData['id'] ?? null,
        $medicoData['nombre'] ?? null,
        $medicoData['apellido'] ?? null,
        $medicoInformanteRol,
        $transcriptorData['id'] ?? null,
        $transcriptorData['nombre'] ?? null,
        $transcriptorData['apellido'] ?? null,
        $transcriptorData['rol'] ?? null,
    ];

    try {
        $hist = $db->prepare($fullSql);
        $hist->execute($fullParams);
        error_log("[IRVH] Historial OK informe_id={$informeId} v_anterior={$versionAnt}");

        return;
    } catch (Throwable $e) {
        error_log('[IRVH] Historial extendido falló, reintento mínimo: ' . $e->getMessage());
    }

    try {
        $minSql = 'INSERT INTO informes_historial
            (informe_id, version_anterior, contenido_html_anterior, estado_anterior, usuario_modificacion, motivo_cambio)
            VALUES (?, ?, ?, ?, ?, ?)';
        $min = $db->prepare($minSql);
        $min->execute([
            $informeId,
            $versionAnt,
            $htmlAnt,
            $estadoAnt,
            $usuarioModificacionId,
            $motivoCambio,
        ]);
        error_log("[IRVH] Historial mínimo OK informe_id={$informeId}");
    } catch (Throwable $e2) {
        error_log('[IRVH] Historial mínimo falló: ' . $e2->getMessage());
    }
}

/**
 * @return ?array Fila informes o null
 */
function irvh_cargarInformeParaVersion(PDO $db, int $informeId): ?array
{
    $stmt = $db->prepare('SELECT * FROM informes WHERE id = ? LIMIT 1');
    $stmt->execute([$informeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * Decide si conviene registrar nueva versión (cambio real de PDF o de HTML generado).
 */
function irvh_requiereNuevaVersion(array $before, string $nuevoPdf, string $nuevoHtml): bool
{
    $oldPdf = trim((string)($before['pdf_path'] ?? ''));
    $nuevoPdf = trim($nuevoPdf);
    if (!irvh_pathsMismaReferencia($oldPdf, $nuevoPdf)) {
        return true;
    }
    $o = preg_replace('/\s+/', ' ', trim((string)($before['contenido_html'] ?? '')));
    $n = preg_replace('/\s+/', ' ', trim($nuevoHtml));

    return $o !== $n;
}

/**
 * Tras insertar historial, devuelve el número de versión nueva para el UPDATE.
 */
function irvh_aplicarVersionPorPdfRecibido(
    PDO $db,
    int $informeId,
    string $nuevoPdfRelativo,
    string $tituloAdjunto,
    int $usuarioModificacionId,
    string $motivoCambio
): array {
    $before = irvh_cargarInformeParaVersion($db, $informeId);
    if (!$before) {
        return ['version' => 1, 'contenido_html' => irvh_buildAdjuntoPdfContenidoHtml($nuevoPdfRelativo, $tituloAdjunto), 'contenido_texto' => 'Informe PDF recibido por API - ' . $tituloAdjunto, 'registro_historial' => false];
    }

    $nuevoHtml = irvh_mergeContenidoHtmlConNuevoAdjuntoPdf($before['contenido_html'] ?? '', $nuevoPdfRelativo, $tituloAdjunto);
    $nuevoTexto = 'Informe PDF recibido por API - ' . $tituloAdjunto;

    $registro = false;
    if (irvh_requiereNuevaVersion($before, $nuevoPdfRelativo, $nuevoHtml)) {
        irvh_insertarHistorialPdfRecibido($db, $before, $usuarioModificacionId, $motivoCambio);
        $registro = true;
    }

    $v = (int)($before['version'] ?? 1);
    if ($registro) {
        $v++;
    }

    return [
        'version' => max(1, $v),
        'contenido_html' => $nuevoHtml,
        'contenido_texto' => $nuevoTexto,
        'registro_historial' => $registro,
    ];
}
