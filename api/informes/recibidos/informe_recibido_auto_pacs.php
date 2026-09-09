<?php
/**
 * Envío automático a PACS tras vincular un informe recibido (API o manual).
 * Si existen columnas auto_pacs_* (ver database/add_informes_recibidos_auto_pacs_tracking.sql),
 * se persiste estado, error y reintentos en informes_recibidos.
 */

const IR_AUTO_PACS_MAX_TRIES = 8;
const IR_AUTO_PACS_RETRY_BACKOFF_SEC = 90;

function ir_parseBoolConfig(string $val): bool
{
    $v = strtolower(trim($val));
    return in_array($v, ['1', 'si', 'sí', 'yes', 'true'], true);
}

function ir_configAutoEnviarPacsActivo(PDO $db): bool
{
    try {
        $stmt = $db->prepare('SELECT valor FROM configuracion WHERE clave = ? LIMIT 1');
        $stmt->execute(['ir_auto_enviar_pacs_activo']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row && ir_parseBoolConfig((string)($row['valor'] ?? ''));
    } catch (Throwable $e) {
        error_log('[IR_AUTO_PACS] config: ' . $e->getMessage());

        return false;
    }
}

/**
 * Devuelve el set de modalidades excluidas de búsqueda automática (en mayúsculas).
 * Configuradas en clave 'ir_modalidades_excluidas' separadas por coma.
 * Ej: "DMO,US" → ['DMO', 'US']
 * Estas modalidades no tienen estudios en PACS (ej. densitometría, ecografía), por lo que
 * los informes recibidos de esas modalidades se marcan como 'pendiente_sin_pacs'.
 */
function ir_configModalidadesExcluidas(PDO $db): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $stmt = $db->prepare('SELECT valor FROM configuracion WHERE clave = ? LIMIT 1');
        $stmt->execute(['ir_modalidades_excluidas']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $raw = trim((string)($row['valor'] ?? ''));
        if ($raw === '') {
            $cached = [];
            return $cached;
        }
        $cached = array_values(array_filter(array_map(
            fn($m) => strtoupper(trim($m)),
            explode(',', $raw)
        )));
        return $cached;
    } catch (Throwable $e) {
        error_log('[IR_AUTO_PACS] ir_configModalidadesExcluidas: ' . $e->getMessage());
        $cached = [];
        return $cached;
    }
}

/**
 * Indica si una modalidad (del informe recibido) está excluida de búsqueda automática.
 * Normaliza CR↔DX: si excluimos "CR" también se excluye "DX" y viceversa.
 */
function ir_isModalidadExcluida(string $modality, PDO $db): bool
{
    $modality = strtoupper(trim($modality));
    if ($modality === '') {
        return false;
    }
    $excluidas = ir_configModalidadesExcluidas($db);
    if (in_array($modality, $excluidas, true)) {
        return true;
    }
    // Equivalencias comunes
    $equiv = ['CR' => ['DX'], 'DX' => ['CR']];
    foreach ($equiv[$modality] ?? [] as $alt) {
        if (in_array($alt, $excluidas, true)) {
            return true;
        }
    }
    return false;
}

function ir_configFormatoPacs(PDO $db): string
{
    try {
        $stmt = $db->prepare('SELECT valor FROM configuracion WHERE clave = ? LIMIT 1');
        $stmt->execute(['pacs_formato_defecto']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $val = strtolower(trim((string)($row['valor'] ?? 'pdf')));

        return in_array($val, ['pdf', 'jpg'], true) ? $val : 'pdf';
    } catch (Throwable $e) {
        return 'pdf';
    }
}

function ir_informesRecibidosHasAutoPacsColumns(PDO $db): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $stmt = $db->query('SHOW COLUMNS FROM informes_recibidos');
        $cols = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        $cached = is_array($cols) && in_array('auto_pacs_estado', $cols, true);
    } catch (Throwable $e) {
        $cached = false;
    }

    return $cached;
}

/**
 * Verifica si ya existe en PACS un informe con el mismo numero_informe_pdf + patient_id.
 * Devuelve el informe_recibido_id del duplicado (diferente al actual) o null si no existe.
 *
 * Útil para detectar el escenario donde el sistema externo re-envía el mismo informe
 * (mismo número de informe del mismo paciente) que ya fue procesado y subido a PACS.
 */
function ir_checkNumeroInformeDuplicadoEnPacs(PDO $db, int $informeRecibidoId): ?array
{
    if ($informeRecibidoId <= 0) {
        return null;
    }
    try {
        // Obtener numero_informe_pdf y patient_id del IR actual
        $sel = $db->prepare('
            SELECT numero_informe_pdf, patient_id
            FROM informes_recibidos
            WHERE id = ? LIMIT 1
        ');
        $sel->execute([$informeRecibidoId]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $numInforme = trim((string)($row['numero_informe_pdf'] ?? ''));
        $patientId  = trim((string)($row['patient_id'] ?? ''));
        if ($numInforme === '' || $patientId === '') {
            return null;
        }

        // Buscar otro IR del mismo paciente y número que ya esté en PACS
        $dup = $db->prepare('
            SELECT ir.id AS ir_id, ir.accession_number, i.pacs_series_id, i.fecha_enviado_pacs
            FROM informes_recibidos ir
            JOIN informes i ON i.id = ir.informe_id
            WHERE ir.numero_informe_pdf = ?
              AND ir.patient_id = ?
              AND ir.id <> ?
              AND ir.informe_id IS NOT NULL
              AND i.pacs_series_id IS NOT NULL
              AND i.pacs_series_id <> \'\'
            LIMIT 1
        ');
        $dup->execute([$numInforme, $patientId, $informeRecibidoId]);
        $dupRow = $dup->fetch(PDO::FETCH_ASSOC);
        if (!$dupRow) {
            return null;
        }
        return [
            'ir_id'            => (int)$dupRow['ir_id'],
            'accession_number' => $dupRow['accession_number'],
            'pacs_series_id'   => $dupRow['pacs_series_id'],
            'fecha_enviado_pacs' => $dupRow['fecha_enviado_pacs'],
            'numero_informe_pdf' => $numInforme,
            'patient_id'       => $patientId,
        ];
    } catch (Throwable $e) {
        error_log('[IR_AUTO_PACS] checkNumeroInformeDup: ' . $e->getMessage());
        return null;
    }
}

function ir_markAutoPacsPending(PDO $db, int $informeRecibidoId): void
{
    if ($informeRecibidoId <= 0 || !ir_configAutoEnviarPacsActivo($db)) {
        return;
    }
    if (!ir_informesRecibidosHasAutoPacsColumns($db)) {
        return;
    }
    try {
        $st = $db->prepare("
            UPDATE informes_recibidos
            SET auto_pacs_estado = 'pendiente',
                auto_pacs_error = NULL,
                auto_pacs_try_count = 0,
                auto_pacs_last_try_at = NULL
            WHERE id = ?
        ");
        $st->execute([$informeRecibidoId]);
    } catch (Throwable $e) {
        error_log('[IR_AUTO_PACS] mark pending: ' . $e->getMessage());
    }
}

/**
 * @return bool false si ya no debe intentarse (tope de intentos)
 */
function ir_bumpAutoPacsTryOrSkip(PDO $db, int $informeRecibidoId, int $informeId): bool
{
    if (!ir_informesRecibidosHasAutoPacsColumns($db)) {
        return true;
    }
    try {
        // UPDATE atómico: solo "gana" si el estado actual permite un nuevo intento.
        // Elimina la carrera SELECT + UPDATE que provocaba dobles envíos.
        $up = $db->prepare("
            UPDATE informes_recibidos
            SET auto_pacs_try_count = auto_pacs_try_count + 1,
                auto_pacs_last_try_at = NOW(),
                auto_pacs_estado = 'procesando'
            WHERE id = ? AND informe_id = ?
              AND auto_pacs_try_count < ?
              AND (
                  auto_pacs_estado IN ('pendiente', 'error')
                  OR (
                      -- 'procesando' viejo (proceso muerto): elegible tras timeout
                      auto_pacs_estado = 'procesando'
                      AND auto_pacs_last_try_at < DATE_SUB(NOW(), INTERVAL 300 SECOND)
                  )
              )
        ");
        $up->execute([$informeRecibidoId, $informeId, IR_AUTO_PACS_MAX_TRIES]);

        if ($up->rowCount() === 0) {
            // Nadie ganó la carrera: el row ya lo tomó otro proceso, está en 'enviado',
            // o alcanzó max_tries. Verificar si ya se superó el límite y marcarlo.
            $sel = $db->prepare('SELECT auto_pacs_try_count, auto_pacs_estado FROM informes_recibidos WHERE id = ? AND informe_id = ? LIMIT 1');
            $sel->execute([$informeRecibidoId, $informeId]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            if ($row && (int)($row['auto_pacs_try_count'] ?? 0) >= IR_AUTO_PACS_MAX_TRIES
                && ($row['auto_pacs_estado'] ?? '') !== 'enviado') {
                $msg = 'Máximo de intentos automáticos al PACS alcanzado (' . IR_AUTO_PACS_MAX_TRIES . ')';
                $db->prepare("UPDATE informes_recibidos SET auto_pacs_estado='error', auto_pacs_error=? WHERE id=? AND informe_id=?")
                   ->execute([$msg, $informeRecibidoId, $informeId]);
            }
            return false;
        }
    } catch (Throwable $e) {
        error_log('[IR_AUTO_PACS] bump try: ' . $e->getMessage());
    }

    return true;
}

function ir_setAutoPacsRowState(PDO $db, int $informeRecibidoId, int $informeId, string $estado, ?string $error): void
{
    if (!ir_informesRecibidosHasAutoPacsColumns($db)) {
        return;
    }
    try {
        $up = $db->prepare('
            UPDATE informes_recibidos
            SET auto_pacs_estado = ?,
                auto_pacs_error = ?
            WHERE id = ? AND informe_id = ?
        ');
        $up->execute([$estado, $error, $informeRecibidoId, $informeId]);
    } catch (Throwable $e) {
        error_log('[IR_AUTO_PACS] set state: ' . $e->getMessage());
    }
}

/**
 * @return array<int, array<string, mixed>>
 */
function ir_selectRowsForAutoPacsRetry(PDO $db, int $limit, int $maxTries, int $backoffSec): array
{
    if (!ir_informesRecibidosHasAutoPacsColumns($db) || !ir_configAutoEnviarPacsActivo($db)) {
        return [];
    }
    $limit = max(1, min(500, $limit));
    $backoffSec = max(0, min(86400, $backoffSec));
    $maxTries = max(1, min(100, $maxTries));
    $sql = "
        SELECT id, informe_id
        FROM informes_recibidos
        WHERE estado = 'vinculado'
          AND informe_id IS NOT NULL
          AND informe_id > 0
          AND (
              auto_pacs_estado IN ('pendiente', 'error')
              OR (
                  -- 'procesando' con más de 5 min indica proceso muerto; volver a intentar
                  auto_pacs_estado = 'procesando'
                  AND auto_pacs_last_try_at < DATE_SUB(NOW(), INTERVAL 300 SECOND)
              )
          )
          AND auto_pacs_try_count < ?
          AND (
              auto_pacs_last_try_at IS NULL
              OR auto_pacs_last_try_at < DATE_SUB(NOW(), INTERVAL " . (int)$backoffSec . " SECOND)
          )
        ORDER BY auto_pacs_last_try_at IS NULL DESC, auto_pacs_last_try_at ASC, id ASC
        LIMIT " . (int)$limit;
    try {
        $st = $db->prepare($sql);
        $st->execute([$maxTries]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[IR_AUTO_PACS] retry select: ' . $e->getMessage());

        return [];
    }
}

/**
 * Si ir_auto_enviar_pacs_activo = 1, envía el informe de manager a Orthanc (misma lógica que send-to-pacs).
 * Los errores no revierten la vinculación; con informe_recibido_id y columnas auto_pacs_* se guarda estado en BD.
 */
function ir_enviarPacsSiCorresponde(PDO $db, ?int $informeId, ?int $informeRecibidoId = null): void
{
    if (!$informeId || $informeId <= 0) {
        return;
    }
    if (!ir_configAutoEnviarPacsActivo($db)) {
        return;
    }

    if (file_exists(__DIR__ . '/../../../vendor/autoload.php')) {
        require_once __DIR__ . '/../../../vendor/autoload.php';
    }
    require_once __DIR__ . '/../send_to_pacs_service.php';

    $irId = $informeRecibidoId !== null ? (int)$informeRecibidoId : 0;
    $trackIr = $irId > 0 && ir_informesRecibidosHasAutoPacsColumns($db);

    if (function_exists('stps_informeYaEnPacs') && stps_informeYaEnPacs($db, (int)$informeId)) {
        if ($trackIr) {
            ir_setAutoPacsRowState($db, $irId, (int)$informeId, 'enviado', null);
        }
        error_log('[IR_AUTO_PACS] Omitido: informe ya tiene referencias PACS en BD informe_id=' . $informeId);

        return;
    }

    // Check por numero_informe_pdf: si otro IR del mismo paciente con el mismo número ya está en PACS,
    // este es una re-entrega del mismo informe → omitir envío y sincronizar estado.
    if ($trackIr) {
        $dupNum = ir_checkNumeroInformeDuplicadoEnPacs($db, $irId);
        if ($dupNum !== null) {
            ir_setAutoPacsRowState($db, $irId, (int)$informeId, 'enviado',
                'N° informe ' . $dupNum['numero_informe_pdf'] . ' ya enviado (IR#' . $dupNum['ir_id'] . ', serie ' . substr($dupNum['pacs_series_id'], 0, 8) . '...)');
            error_log('[IR_AUTO_PACS] Omitido por numero_informe_pdf duplicado: ir_id=' . $irId
                . ' num=' . $dupNum['numero_informe_pdf']
                . ' duplicado_en=IR#' . $dupNum['ir_id']
                . ' pacs_series=' . $dupNum['pacs_series_id']);
            return;
        }
    }

    if ($trackIr && !ir_bumpAutoPacsTryOrSkip($db, $irId, (int)$informeId)) {
        return;
    }

    // Re-check DESPUÉS del bump: el intento anterior pudo haber enviado a PACS
    // pero muerto antes de actualizar informes_recibidos.auto_pacs_estado.
    // Si informes.pacs_series_id (u otra columna PACS) ya está seteado, no reenviar.
    if (function_exists('stps_informeYaEnPacs') && stps_informeYaEnPacs($db, (int)$informeId)) {
        if ($trackIr) {
            ir_setAutoPacsRowState($db, $irId, (int)$informeId, 'enviado', null);
        }
        error_log('[IR_AUTO_PACS] Post-bump: informe ya en PACS (enviado previo sin confirmar). informe_id=' . $informeId);
        return;
    }

    try {
        $query = 'SELECT i.*, u.nombre as usuario_nombre, u.apellido as usuario_apellido,
                         u.email as usuario_email, u.matricula_profesional
                  FROM informes i
                  LEFT JOIN usuarios u ON i.usuario_id = u.id
                  WHERE i.id = ?';
        $stmt = $db->prepare($query);
        $stmt->execute([$informeId]);
        $informe = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$informe) {
            error_log('[IR_AUTO_PACS] Informe no encontrado id=' . $informeId);
            if ($trackIr) {
                ir_setAutoPacsRowState($db, $irId, (int)$informeId, 'error', 'Informe no encontrado');
            }

            return;
        }
        $estadoInf = strtolower(trim((string)($informe['estado'] ?? '')));
        if ($estadoInf !== 'finalizado') {
            // Circuito firma: no auto-enviar hasta Finalizado (tras firma médica)
            error_log('[IR_AUTO_PACS] Omitido: estado=' . $estadoInf . ' (requiere finalizado) informe_id=' . $informeId);
            if ($trackIr) {
                ir_setAutoPacsRowState($db, $irId, (int)$informeId, 'pendiente', 'Esperando estado finalizado (firma médica)');
            }
            return;
        }
        $format = ir_configFormatoPacs($db);
        stps_sendInformeToPacsInternal($db, $informe, [
            'format' => $format,
            'check_duplicates' => true,
        ]);
        error_log('[IR_AUTO_PACS] Enviado a PACS informe_id=' . $informeId . ' formato=' . $format);
        if ($trackIr) {
            ir_setAutoPacsRowState($db, $irId, (int)$informeId, 'enviado', null);
        }
    } catch (Throwable $e) {
        error_log('[IR_AUTO_PACS] Fallo envío informe_id=' . $informeId . ': ' . $e->getMessage());
        if ($trackIr) {
            ir_setAutoPacsRowState($db, $irId, (int)$informeId, 'error', $e->getMessage());
        }
    }
}
