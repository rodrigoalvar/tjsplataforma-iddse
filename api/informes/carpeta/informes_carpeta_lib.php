<?php
/**
 * Ingesta desde carpetas (PDF + TXT en rutas distintas), emparejamiento por idpaciente_ y fecha.
 */

require_once __DIR__ . '/../recibidos/dicom_txt_parser.php';

function ic_default_pdf_path(): string
{
    return '/var/www/tjsiddse/uploads/informespdf_net';
}

function ic_default_txt_path(): string
{
    return '/var/www/tjsiddse/uploads/mensajesris_net';
}

function ic_get_config_paths(PDO $db): array
{
    $stmt = $db->prepare('SELECT valor FROM configuracion WHERE clave = ? LIMIT 1');
    $stmt->execute(['ic_pdf_path']);
    $pdf = trim((string)($stmt->fetchColumn() ?: ''));
    $stmt->execute(['ic_txt_path']);
    $txt = trim((string)($stmt->fetchColumn() ?: ''));

    return [
        'pdf' => $pdf !== '' ? $pdf : ic_default_pdf_path(),
        'txt' => $txt !== '' ? $txt : ic_default_txt_path(),
    ];
}

function ic_is_activo(PDO $db): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $st = $db->prepare("SELECT valor FROM configuracion WHERE clave = 'ic_activo' LIMIT 1");
        $st->execute();
        $val = $st->fetchColumn();
        $cache = ($val !== false && trim((string)$val) === '1');
    } catch (Throwable $e) {
        $cache = false;
    }
    return $cache;
}

function ic_ensure_table(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS `informes_carpeta_archivos` (
      `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
      `tipo` ENUM('pdf','txt') NOT NULL,
      `ruta_absoluta` VARCHAR(1024) NOT NULL,
      `nombre_archivo` VARCHAR(512) NOT NULL,
      `idpaciente` VARCHAR(191) NOT NULL COMMENT 'Prefijo del nombre antes del primer _',
      `sufijo_nombre` VARCHAR(255) DEFAULT NULL COMMENT 'Tras _: ACCNO en txt o N° estudio informe en pdf',
      `tamano_bytes` BIGINT DEFAULT NULL,
      `mtime_fs` DATETIME DEFAULT NULL,
      `sha256` CHAR(64) DEFAULT NULL,
      `estado` ENUM('detectado','pendiente_par','emparejado','ingresado','omitido_duplicado','error') NOT NULL DEFAULT 'detectado',
      `error_message` TEXT DEFAULT NULL,
      `fecha_deteccion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `fecha_actualizacion` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      `parquet_con_id` BIGINT DEFAULT NULL,
      `informe_recibido_id` INT DEFAULT NULL,
      `metadata_json` TEXT DEFAULT NULL,
      UNIQUE KEY `uq_sha256_tipo` (`sha256`,`tipo`),
      KEY `idx_tipo_estado` (`tipo`,`estado`),
      KEY `idx_idpaciente` (`idpaciente`),
      KEY `idx_informe_recibido` (`informe_recibido_id`),
      KEY `idx_fecha_deteccion` (`fecha_deteccion`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function ic_realpath_dir(string $path): ?string
{
    $rp = realpath($path);

    return ($rp !== false && is_dir($rp)) ? $rp : null;
}

function ic_path_under_root(string $absFile, string $rootDir): bool
{
    $fr = realpath($absFile);
    $rr = realpath($rootDir);
    $normF = str_replace('\\', '/', $absFile);
    $normR = rtrim(str_replace('\\', '/', $rootDir), '/');
    if ($fr === false) {
        return strpos($normF, $normR . '/') === 0 || $normF === $normR;
    }
    if ($rr === false) {
        return strpos(str_replace('\\', '/', $fr), $normR . '/') === 0;
    }

    $prefix = $rr . DIRECTORY_SEPARATOR;

    return strpos($fr, $prefix) === 0;
}

function ic_parse_filename(string $basename): ?array
{
    $basename = basename($basename);
    if (!preg_match('/^([^_]+)_(.+)\.([^.]+)$/i', $basename, $m)) {
        return null;
    }

    return [
        'idpaciente' => $m[1],
        'sufijo' => $m[2],
        'ext' => strtolower($m[3]),
    ];
}

function ic_sha256_file(string $path): ?string
{
    $h = @hash_file('sha256', $path);

    return $h !== false ? $h : null;
}

function ic_file_size_stable(string $path, int $waitUs = 200000): bool
{
    if (!is_readable($path)) {
        return false;
    }
    $a = @filesize($path);
    usleep($waitUs);
    $b = @filesize($path);

    return $a !== false && $b !== false && $a === $b && $a > 0;
}

function ic_pdf_first_page_text(string $path): string
{
    if (!is_readable($path)) {
        return '';
    }
    $bin = trim((string)@shell_exec('command -v pdftotext 2>/dev/null'));
    if ($bin === '') {
        return '';
    }
    $esc = escapeshellarg($path);
    $out = @shell_exec($bin . ' -f 1 -l 1 -layout ' . $esc . ' - 2>/dev/null');

    return is_string($out) ? $out : '';
}

/**
 * Fecha de informe tipo DD/MM/YYYY en cabecera PDF (no es fecha DICOM).
 */
function ic_extract_pdf_report_date(string $text): ?string
{
    if (preg_match('/FECHA\s*:\s*(\d{1,2})\/(\d{1,2})\/(\d{4})/iu', $text, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }

    return null;
}

/**
 * Línea de descripción del estudio: la primera línea NO vacía después del separador _______.
 * Ejemplo: "RM PELVIS", "MAMOGRAFIA DIGITAL BILATERAL CON PROYECCION AXILAR".
 */
function ic_extract_pdf_description(string $text): string
{
    // Busca la línea inmediatamente después de una línea de guiones bajos
    if (preg_match('/_{3,}[^\n]*\n([^\n]+)/u', $text, $m)) {
        return trim($m[1]);
    }
    return '';
}

/**
 * Score de similitud entre dos descripciones de procedimiento (0-30 puntos).
 * Tokeniza por palabras (>2 chars) y cuenta coincidencias exactas.
 */
function ic_description_similarity(string $a, string $b): int
{
    $tok = static function (string $s): array {
        $tokens = preg_split('/\s+/u', strtoupper(trim($s))) ?: [];
        return array_values(array_filter($tokens, static fn($t) => mb_strlen($t) > 2));
    };
    $tokA = $tok($a);
    $tokB = $tok($b);
    if (!$tokA || !$tokB) {
        return 0;
    }
    $matches = 0;
    foreach ($tokA as $t) {
        if (in_array($t, $tokB, true)) {
            $matches++;
        }
    }
    return min(30, $matches * 10);
}

function ic_procedure_date_from_dicom(array $d): ?string
{
    if (!empty($d['scheduled_date'])) {
        return $d['scheduled_date'];
    }
    if (!empty($d['study_date'])) {
        return $d['study_date'];
    }

    return null;
}

function ic_dates_match(?string $dicomYmd, ?string $pdfYmd): bool
{
    if ($dicomYmd && $pdfYmd) {
        if ($dicomYmd === $pdfYmd) {
            return true;
        }
        $t1 = strtotime($dicomYmd);
        $t2 = strtotime($pdfYmd);
        if ($t1 && $t2) {
            return abs($t1 - $t2) <= 86400;
        }
    }

    return false;
}

function ic_load_ir_functions(): void
{
    if (!function_exists('ir_ingest_recibido_pair_from_paths')) {
        if (!defined('IR_RECIBIR_PDF_FUNCTIONS_ONLY_LOAD')) {
            define('IR_RECIBIR_PDF_FUNCTIONS_ONLY_LOAD', true);
        }
        require_once __DIR__ . '/../recibir-pdf.php';
    }
}

/**
 * Tras insertar fila pdf o txt, intenta emparejar e ingerir en informes_recibidos.
 */
function ic_try_pair_and_ingest(PDO $db, int $rowId): void
{
    $st = $db->prepare('SELECT * FROM informes_carpeta_archivos WHERE id = ? LIMIT 1');
    $st->execute([$rowId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || !in_array($row['estado'], ['detectado', 'pendiente_par'], true)) {
        return;
    }

    $tipo = $row['tipo'];
    $oppTipo = $tipo === 'pdf' ? 'txt' : 'pdf';
    $idp = $row['idpaciente'];

    $q = $db->prepare("SELECT * FROM informes_carpeta_archivos
        WHERE idpaciente = ? AND tipo = ? AND estado IN ('detectado','pendiente_par') AND id <> ?
        ORDER BY fecha_deteccion ASC, id ASC LIMIT 20");
    $q->execute([$idp, $oppTipo, $rowId]);
    $cands = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$cands) {
        $db->prepare("UPDATE informes_carpeta_archivos SET estado = 'pendiente_par' WHERE id = ? AND estado = 'detectado'")->execute([$rowId]);

        return;
    }

    $metaThis = json_decode($row['metadata_json'] ?? '{}', true);
    if (!is_array($metaThis)) {
        $metaThis = [];
    }

    // Fecha y descripción del archivo actual
    if ($tipo === 'txt') {
        $ourDate = $metaThis['procedure_date'] ?? null;
        $ourDesc = (string)($metaThis['procedure_description'] ?? '');
    } else {
        $ourDate = $metaThis['pdf_report_date'] ?? null;
        $ourDesc = (string)($metaThis['pdf_description'] ?? '');
    }

    $best          = null;
    $bestScore     = -1;
    $bestAccno     = PHP_INT_MAX;
    $emparejamientoTipo = 'sin_fecha';

    foreach ($cands as $c) {
        $metaO = json_decode($c['metadata_json'] ?? '{}', true);
        if (!is_array($metaO)) {
            $metaO = [];
        }

        if ($tipo === 'txt') {
            $oppDate = $metaO['pdf_report_date'] ?? null;
            $oppDesc = (string)($metaO['pdf_description'] ?? '');
        } else {
            $oppDate = $metaO['procedure_date'] ?? null;
            $oppDesc = (string)($metaO['procedure_description'] ?? '');
        }

        // --- Scoring por fecha (criterio principal) ---
        $score = 0;
        if ($ourDate !== null && $oppDate !== null) {
            // Ambos archivos tienen fecha: si no coinciden (±1 día), descartar candidato
            if ($ourDate === $oppDate) {
                $score += 60;
            } elseif (abs(strtotime($ourDate) - strtotime($oppDate)) <= 86400) {
                $score += 20;
            } else {
                // Demasiado separados → no es el par correcto
                continue;
            }
        } elseif ($ourDate !== null || $oppDate !== null) {
            // Solo uno de los dos tiene fecha → señal débil, pero no se descarta
            $score += 5;
        } else {
            // Ninguno tiene fecha → candidato muy incierto
            $score += 2;
        }

        // --- Bonus por similitud de descripción (0-30 puntos) ---
        if ($ourDesc !== '' && $oppDesc !== '') {
            $score += ic_description_similarity($ourDesc, $oppDesc);
        }

        // Desempate por ACCNO menor (serie más temprana de la sesión)
        $candAccno = is_numeric($c['sufijo_nombre']) ? (int)$c['sufijo_nombre'] : PHP_INT_MAX;
        if ($score > $bestScore || ($score === $bestScore && $candAccno < $bestAccno)) {
            $bestScore = $score;
            $bestAccno = $candAccno;
            $best      = $c;
        }
    }

    // Decisión de emparejamiento:
    // >= 60  → fecha exacta (par confiable)
    // 20-59  → fecha aproximada ±1 día (aceptable)
    // < 20   → sin coincidencia de fechas → solo aceptar si hay UN ÚNICO candidato sin fechas disponibles
    if ($best === null) {
        // Todos los candidatos fueron descartados por fecha incompatible
        $db->prepare("UPDATE informes_carpeta_archivos SET estado = 'pendiente_par' WHERE id = ?")->execute([$rowId]);
        return;
    }

    if ($bestScore < 20) {
        // Candidatos sin información de fecha suficiente
        if (count($cands) > 1) {
            // Múltiples candidatos sin fecha → no se puede decidir
            $db->prepare("UPDATE informes_carpeta_archivos SET estado = 'pendiente_par' WHERE id = ?")->execute([$rowId]);
            return;
        }
        // Un único candidato sin fecha → aceptar con advertencia
        $emparejamientoTipo = 'unico_sin_fecha';
    } elseif ($bestScore >= 60) {
        $emparejamientoTipo = 'fecha_exacta';
    } else {
        $emparejamientoTipo = 'fecha_aproximada';
    }

    $pdfRow = $tipo === 'pdf' ? $row : $best;
    $txtRow = $tipo === 'txt' ? $row : $best;

    $txtContent = @file_get_contents($txtRow['ruta_absoluta']);
    if ($txtContent === false) {
        $db->prepare("UPDATE informes_carpeta_archivos SET estado = ?, error_message = ? WHERE id = ?")->execute(['error', 'No se pudo leer TXT', $txtRow['id']]);

        return;
    }

    try {
        $dicomData = ir_parse_dicom_txt_content($txtContent);
    } catch (Exception $e) {
        $db->prepare("UPDATE informes_carpeta_archivos SET estado = ?, error_message = ? WHERE id IN (?,?)")->execute(['error', $e->getMessage(), $pdfRow['id'], $txtRow['id']]);

        return;
    }

    // Detectar duplicado: si ya existe un informe_recibido con el mismo ACCNO, no insertar otro
    $accNoParsed = trim((string)($dicomData['accession_number'] ?? ''));
    if ($accNoParsed !== '') {
        try {
            $dupChk = $db->prepare("SELECT id FROM informes_recibidos WHERE accession_number = ? LIMIT 1");
            $dupChk->execute([$accNoParsed]);
            $existingIrId = $dupChk->fetchColumn();
            if ($existingIrId !== false) {
                $existingIrId = (int)$existingIrId;
                $pairExtraDup = [
                    'emparejamiento'       => $emparejamientoTipo,
                    'score_emparejamiento' => $bestScore,
                    'informe_recibido_id'  => $existingIrId,
                    'duplicado_accno'      => $accNoParsed,
                    'motivo_omision'       => 'ACCNO ya existe en informes_recibidos (ingresado por API u otro canal)',
                ];
                $metaPdfDup = json_decode($pdfRow['metadata_json'] ?: '{}', true);
                if (!is_array($metaPdfDup)) { $metaPdfDup = []; }
                $metaTxtDup = json_decode($txtRow['metadata_json'] ?: '{}', true);
                if (!is_array($metaTxtDup)) { $metaTxtDup = []; }
                $u = $db->prepare("UPDATE informes_carpeta_archivos SET estado = 'omitido_duplicado', parquet_con_id = ?, informe_recibido_id = ?, metadata_json = ? WHERE id = ?");
                $u->execute([$txtRow['id'], $existingIrId, json_encode(array_merge($metaPdfDup, $pairExtraDup), JSON_UNESCAPED_UNICODE), $pdfRow['id']]);
                $u->execute([$pdfRow['id'], $existingIrId, json_encode(array_merge($metaTxtDup, $pairExtraDup), JSON_UNESCAPED_UNICODE), $txtRow['id']]);
                error_log('[IC] Par omitido (ACCNO duplicado ' . $accNoParsed . '): IR existente #' . $existingIrId . ' pdf_id=' . $pdfRow['id'] . ' txt_id=' . $txtRow['id']);
                return;
            }
        } catch (Throwable $e) {
            error_log('[IC] Error al verificar duplicado ACCNO: ' . $e->getMessage());
        }
    }

    // Duplicado adicional por mismo paciente + mismo número de informe PDF
    try {
        $pdfNumero = trim((string)($pdfRow['sufijo_nombre'] ?? ''));
        $pid = trim((string)($pdfRow['idpaciente'] ?? ''));
        if ($pid !== '' && $pdfNumero !== '') {
            $dupPdf = $db->prepare("SELECT id, pdf_path FROM informes_recibidos WHERE patient_id = ?");
            $dupPdf->execute([$pid]);
            foreach ($dupPdf->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $numIr = ic_pdf_report_number_from_path((string)($r['pdf_path'] ?? ''));
                if ($numIr !== '' && strcasecmp($numIr, $pdfNumero) === 0) {
                    $existingIrId = (int)$r['id'];
                    $pairExtraDup = [
                        'emparejamiento'       => $emparejamientoTipo,
                        'score_emparejamiento' => $bestScore,
                        'informe_recibido_id'  => $existingIrId,
                        'duplicado_pdf_numero_informe' => $pdfNumero,
                        'motivo_omision'       => 'Mismo idpaciente + numero_informe PDF ya existe en informes_recibidos',
                    ];
                    $metaPdfDup = json_decode($pdfRow['metadata_json'] ?: '{}', true);
                    if (!is_array($metaPdfDup)) { $metaPdfDup = []; }
                    $metaTxtDup = json_decode($txtRow['metadata_json'] ?: '{}', true);
                    if (!is_array($metaTxtDup)) { $metaTxtDup = []; }
                    $u = $db->prepare("UPDATE informes_carpeta_archivos SET estado = 'omitido_duplicado', parquet_con_id = ?, informe_recibido_id = ?, metadata_json = ? WHERE id = ?");
                    $u->execute([$txtRow['id'], $existingIrId, json_encode(array_merge($metaPdfDup, $pairExtraDup), JSON_UNESCAPED_UNICODE), $pdfRow['id']]);
                    $u->execute([$pdfRow['id'], $existingIrId, json_encode(array_merge($metaTxtDup, $pairExtraDup), JSON_UNESCAPED_UNICODE), $txtRow['id']]);
                    error_log('[IC] Par omitido (PDF numero_informe duplicado ' . $pdfNumero . '): IR existente #' . $existingIrId . ' pdf_id=' . $pdfRow['id'] . ' txt_id=' . $txtRow['id']);
                    return;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[IC] Error al verificar duplicado por numero_informe PDF: ' . $e->getMessage());
    }

    ic_load_ir_functions();

    try {
        // Pasar el nombre original del PDF para extraer numero_informe_pdf
        $ing = ir_ingest_recibido_pair_from_paths($db, $pdfRow['ruta_absoluta'], $txtRow['ruta_absoluta'], $dicomData, basename($pdfRow['ruta_absoluta']));
    } catch (Exception $e) {
        $db->prepare("UPDATE informes_carpeta_archivos SET estado = ?, error_message = ? WHERE id IN (?,?)")->execute(['error', $e->getMessage(), $pdfRow['id'], $txtRow['id']]);

        return;
    }

    $irId = (int)$ing['informe_recibido_id'];
    $pairExtra = [
        'emparejamiento'      => $emparejamientoTipo,
        'score_emparejamiento' => $bestScore,
        'informe_recibido_id' => $irId,
    ];

    $metaPdf = json_decode($pdfRow['metadata_json'] ?: '{}', true);
    if (!is_array($metaPdf)) {
        $metaPdf = [];
    }
    $metaTxt = json_decode($txtRow['metadata_json'] ?: '{}', true);
    if (!is_array($metaTxt)) {
        $metaTxt = [];
    }
    $metaPdfOut = json_encode(array_merge($metaPdf, $pairExtra), JSON_UNESCAPED_UNICODE);
    $metaTxtOut = json_encode(array_merge($metaTxt, $pairExtra), JSON_UNESCAPED_UNICODE);

    $u = $db->prepare('UPDATE informes_carpeta_archivos SET estado = \'ingresado\', parquet_con_id = ?, informe_recibido_id = ?, metadata_json = ? WHERE id = ?');
    $u->execute([$txtRow['id'], $irId, $metaPdfOut, $pdfRow['id']]);
    $u->execute([$pdfRow['id'], $irId, $metaTxtOut, $txtRow['id']]);

    if (!empty($ing['pending_pacs_send'])) {
        ir_enviarPacsSiCorresponde($db, (int)$ing['pending_pacs_send'], $irId);
    }
}

/**
 * Procesa un archivo (ruta absoluta) bajo las carpetas configuradas.
 * $skipStabilityCheck=true omite el usleep de verificación de tamaño (útil para reprocesamiento masivo).
 *
 * @return array{ok:bool,message:string,id?:int}
 */
function ic_process_filepath(PDO $db, string $absPath, bool $skipStabilityCheck = false): array
{
    ic_ensure_table($db);

    // El gate ic_activo solo bloquea el procesamiento automático (inotify/cron).
    // Cuando $skipStabilityCheck=true viene del reprocesador manual y se permite siempre.
    if (!$skipStabilityCheck && !ic_is_activo($db)) {
        return ['ok' => true, 'message' => 'Ingesta desde carpetas desactivada (ic_activo=0)'];
    }

    $paths = ic_get_config_paths($db);
    $pdfRoot = ic_realpath_dir($paths['pdf']) ?: rtrim($paths['pdf'], '/');
    $txtRoot = ic_realpath_dir($paths['txt']) ?: rtrim($paths['txt'], '/');

    $absPath = str_replace('\\', '/', $absPath);
    if (!is_file($absPath) || !is_readable($absPath)) {
        return ['ok' => false, 'message' => 'No es un archivo legible'];
    }

    $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
    if ($ext === 'pdf') {
        if (!ic_path_under_root($absPath, $pdfRoot)) {
            return ['ok' => false, 'message' => 'PDF fuera de la carpeta configurada'];
        }
        $tipo = 'pdf';
    } elseif ($ext === 'txt') {
        if (!ic_path_under_root($absPath, $txtRoot)) {
            return ['ok' => false, 'message' => 'TXT fuera de la carpeta configurada'];
        }
        $tipo = 'txt';
    } else {
        return ['ok' => false, 'message' => 'Extensión no soportada'];
    }

    if (!$skipStabilityCheck && !ic_file_size_stable($absPath)) {
        return ['ok' => false, 'message' => 'Archivo inestable (tamaño cambiante)'];
    }

    $sha = ic_sha256_file($absPath);
    if (!$sha) {
        return ['ok' => false, 'message' => 'No se pudo calcular SHA256'];
    }

    $chk = $db->prepare('SELECT id FROM informes_carpeta_archivos WHERE sha256 = ? AND tipo = ? LIMIT 1');
    $chk->execute([$sha, $tipo]);
    $dupId = $chk->fetchColumn();
    if ($dupId !== false) {
        return ['ok' => true, 'message' => 'Duplicado SHA256 omitido', 'id' => (int)$dupId];
    }

    $bn = ic_parse_filename($absPath);
    if ($bn === null) {
        return ['ok' => false, 'message' => 'Nombre debe ser idpaciente_sufijo.ext'];
    }
    if (($tipo === 'pdf' && $bn['ext'] !== 'pdf') || ($tipo === 'txt' && $bn['ext'] !== 'txt')) {
        return ['ok' => false, 'message' => 'Extensión no coincide con tipo'];
    }

    $mtime = @filemtime($absPath);
    $mtimeSql = $mtime ? date('Y-m-d H:i:s', $mtime) : null;
    $size = (int)@filesize($absPath);

    $meta = ['origen' => 'carpeta_smb'];

    if ($tipo === 'txt') {
        $txtContent = @file_get_contents($absPath);
        if ($txtContent === false) {
            return ['ok' => false, 'message' => 'No se pudo leer TXT'];
        }
        try {
            $dicom = ir_parse_dicom_txt_content($txtContent);
            $meta['procedure_date']        = ic_procedure_date_from_dicom($dicom);
            $meta['accession_number']      = $dicom['accession_number'] ?? null;
            $meta['procedure_description'] = $dicom['procedure_description'] ?? null;
            $meta['modality']              = $dicom['modality'] ?? null;
        } catch (Exception $e) {
            $meta['parse_error'] = $e->getMessage();
        }
    } else {
        $ptext = ic_pdf_first_page_text($absPath);
        $meta['pdf_report_date']  = ic_extract_pdf_report_date($ptext);
        $meta['pdf_description']  = ic_extract_pdf_description($ptext);
    }

    $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);

    try {
        $ins = $db->prepare('INSERT INTO informes_carpeta_archivos (tipo, ruta_absoluta, nombre_archivo, idpaciente, sufijo_nombre, tamano_bytes, mtime_fs, sha256, estado, metadata_json)
            VALUES (?,?,?,?,?,?,?,?,\'detectado\',?)');
        $ins->execute([
            $tipo,
            $absPath,
            basename($absPath),
            $bn['idpaciente'],
            $bn['sufijo'],
            $size,
            $mtimeSql,
            $sha,
            $metaJson,
        ]);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            return ['ok' => true, 'message' => 'Duplicado SHA256'];
        }
        throw $e;
    }

    $newId = (int)$db->lastInsertId();
    ic_try_pair_and_ingest($db, $newId);

    return ['ok' => true, 'message' => 'Registrado', 'id' => $newId];
}

/**
 * Rutas *.pdf / *.txt en el primer nivel de las carpetas configuradas con mtime >= $sinceUnix.
 * Útil como respaldo cuando inotify no recibe eventos sobre CIFS/SMB.
 *
 * @return list<string>
 */
function ic_list_recent_poll_candidates(PDO $db, int $sinceUnix): array
{
    $paths = ic_get_config_paths($db);
    $out = [];
    foreach ([$paths['pdf'], $paths['txt']] as $dir) {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        if (!is_dir($dir) || !is_readable($dir)) {
            continue;
        }
        foreach (['*.pdf', '*.PDF', '*.txt', '*.TXT'] as $pat) {
            foreach (glob($dir . '/' . $pat, GLOB_NOSORT) ?: [] as $f) {
                if (!is_file($f)) {
                    continue;
                }
                $mt = @filemtime($f);
                if ($mt !== false && $mt >= $sinceUnix) {
                    $out[] = $f;
                }
            }
        }
    }

    return array_values(array_unique($out));
}

/**
 * Convierte una ruta absoluta de archivo a URL web relativa (ej: /uploads/...).
 * Asume que el webroot es el directorio padre de /api (el root del proyecto).
 */
function ic_abs_path_to_url(string $absPath): string
{
    $webRoot = rtrim(dirname(__DIR__, 3), '/'); // /var/www/tjsiddse
    $norm    = str_replace('\\', '/', $absPath);
    $root    = str_replace('\\', '/', $webRoot);
    if (strpos($norm, $root . '/') === 0) {
        return '/' . ltrim(substr($norm, strlen($root) + 1), '/');
    }
    return $norm;
}

/**
 * Extrae el "numeroinforme" desde un nombre/ruta PDF con formato idpaciente_numeroinforme.pdf.
 */
function ic_pdf_report_number_from_path(string $pathOrName): string
{
    $bn = basename($pathOrName);
    $p = ic_parse_filename($bn);
    if (!$p || strtolower((string)($p['ext'] ?? '')) !== 'pdf') {
        return '';
    }
    return trim((string)($p['sufijo'] ?? ''));
}

/**
 * Calcula el score de un candidato opuesto para emparejar con $ourRow (dry-run, sin escritura).
 * Retorna ['score'=>int, 'tipo'=>string] o null si el candidato es descartable por fecha.
 */
function ic_score_candidate(array $ourRow, array $oppRow): ?array
{
    $metaThis = json_decode($ourRow['metadata_json'] ?? '{}', true) ?: [];
    $metaO    = json_decode($oppRow['metadata_json'] ?? '{}', true)  ?: [];
    $tipo     = $ourRow['tipo'];

    if ($tipo === 'txt') {
        $ourDate = $metaThis['procedure_date'] ?? null;
        $ourDesc = (string)($metaThis['procedure_description'] ?? '');
        $oppDate = $metaO['pdf_report_date'] ?? null;
        $oppDesc = (string)($metaO['pdf_description'] ?? '');
    } else {
        $ourDate = $metaThis['pdf_report_date'] ?? null;
        $ourDesc = (string)($metaThis['pdf_description'] ?? '');
        $oppDate = $metaO['procedure_date'] ?? null;
        $oppDesc = (string)($metaO['procedure_description'] ?? '');
    }

    $score = 0;
    if ($ourDate !== null && $oppDate !== null) {
        if ($ourDate === $oppDate) {
            $score += 60;
        } elseif (abs(strtotime($ourDate) - strtotime($oppDate)) <= 86400) {
            $score += 20;
        } else {
            return null; // demasiado separados
        }
    } elseif ($ourDate !== null || $oppDate !== null) {
        $score += 5;
    } else {
        $score += 2;
    }

    if ($ourDesc !== '' && $oppDesc !== '') {
        $score += ic_description_similarity($ourDesc, $oppDesc);
    }

    $tipo_emparejamiento = match (true) {
        $score >= 60 => 'fecha_exacta',
        $score >= 20 => 'fecha_aproximada',
        default      => 'sin_fecha',
    };

    return ['score' => $score, 'tipo' => $tipo_emparejamiento];
}

/**
 * Dry-run: propone pares PDF↔TXT sin escribir nada en la BD.
 * Itera sobre los PDFs pendientes y busca el mejor TXT para cada uno.
 *
 * @return array{pares:list<array>,sin_par_pdf:list<array>,sin_par_txt:list<array>}
 */
function ic_preview_pending_pairs(PDO $db, int $limite = 300): array
{
    // Cargar todos los PDFs y TXTs pendientes
    $st = $db->prepare(
        "SELECT * FROM informes_carpeta_archivos
         WHERE tipo = 'pdf' AND estado IN ('detectado','pendiente_par')
         ORDER BY idpaciente ASC, fecha_deteccion ASC
         LIMIT ?"
    );
    $st->execute([$limite]);
    $pdfs = $st->fetchAll(PDO::FETCH_ASSOC);

    $st2 = $db->prepare(
        "SELECT * FROM informes_carpeta_archivos
         WHERE tipo = 'txt' AND estado IN ('detectado','pendiente_par')
         ORDER BY idpaciente ASC, fecha_deteccion ASC"
    );
    $st2->execute();
    $allTxts = $st2->fetchAll(PDO::FETCH_ASSOC);

    // Indexar TXTs por idpaciente
    $txtsByPac = [];
    foreach ($allTxts as $t) {
        $txtsByPac[$t['idpaciente']][] = $t;
    }

    $pares         = [];
    $usedTxtIds    = [];
    $sinParPdfIds  = [];

    foreach ($pdfs as $pdf) {
        ic_enrich_metadata_if_needed($db, $pdf);
        // Recargar metadata tras posible enriquecimiento
        $pdf = $db->prepare("SELECT * FROM informes_carpeta_archivos WHERE id = ? LIMIT 1")
               ->execute([$pdf['id']]) ? $db->query("SELECT * FROM informes_carpeta_archivos WHERE id = {$pdf['id']} LIMIT 1")->fetch(PDO::FETCH_ASSOC) : $pdf;

        $candidates = $txtsByPac[$pdf['idpaciente']] ?? [];
        $bestScore  = -1;
        $bestAccno  = PHP_INT_MAX;
        $bestTxt    = null;
        $bestTipo   = 'sin_fecha';

        foreach ($candidates as $txt) {
            if (isset($usedTxtIds[$txt['id']])) {
                continue; // ya emparejado con otro PDF
            }
            ic_enrich_metadata_if_needed($db, $txt);
            $res = ic_score_candidate($pdf, $txt);
            if ($res === null) {
                continue;
            }
            $accno = is_numeric($txt['sufijo_nombre']) ? (int)$txt['sufijo_nombre'] : PHP_INT_MAX;
            if ($res['score'] > $bestScore || ($res['score'] === $bestScore && $accno < $bestAccno)) {
                $bestScore = $res['score'];
                $bestAccno = $accno;
                $bestTxt   = $txt;
                $bestTipo  = $res['tipo'];
            }
        }

        $metaPdf = json_decode($pdf['metadata_json'] ?? '{}', true) ?: [];
        $pdfInfo = [
            'id'             => (int)$pdf['id'],
            'nombre_archivo' => $pdf['nombre_archivo'],
            'numero_informe' => trim((string)($pdf['sufijo_nombre'] ?? '')),
            'pdf_report_date'=> $metaPdf['pdf_report_date'] ?? null,
            'pdf_description'=> $metaPdf['pdf_description'] ?? '',
            'url'            => ic_abs_path_to_url($pdf['ruta_absoluta']),
        ];

        if ($bestTxt && $bestScore >= 2) {
            $metaTxt = json_decode($bestTxt['metadata_json'] ?? '{}', true) ?: [];
            $usedTxtIds[$bestTxt['id']] = true;
            $pares[] = [
                'idpaciente'         => $pdf['idpaciente'],
                'score'              => $bestScore,
                'tipo_emparejamiento'=> $bestTipo,
                'pdf'                => $pdfInfo,
                'txt'                => [
                    'id'                   => (int)$bestTxt['id'],
                    'nombre_archivo'       => $bestTxt['nombre_archivo'],
                    'procedure_date'       => $metaTxt['procedure_date'] ?? null,
                    'modality'             => $metaTxt['modality'] ?? '',
                    'procedure_description'=> $metaTxt['procedure_description'] ?? '',
                    'accession_number'     => $metaTxt['accession_number'] ?? $bestTxt['sufijo_nombre'] ?? '',
                    'url'                  => ic_abs_path_to_url($bestTxt['ruta_absoluta']),
                ],
            ];
        } else {
            $sinParPdfIds[] = $pdfInfo;
        }
    }

    // TXTs sin emparejar
    $sinParTxt = [];
    foreach ($allTxts as $txt) {
        if (!isset($usedTxtIds[$txt['id']])) {
            $metaTxt     = json_decode($txt['metadata_json'] ?? '{}', true) ?: [];
            $sinParTxt[] = [
                'id'                   => (int)$txt['id'],
                'nombre_archivo'       => $txt['nombre_archivo'],
                'procedure_date'       => $metaTxt['procedure_date'] ?? null,
                'modality'             => $metaTxt['modality'] ?? '',
                'procedure_description'=> $metaTxt['procedure_description'] ?? '',
                'accession_number'     => $metaTxt['accession_number'] ?? $txt['sufijo_nombre'] ?? '',
                'url'                  => ic_abs_path_to_url($txt['ruta_absoluta']),
            ];
        }
    }

    // Marcar pares que YA existen en informes_recibidos por:
    // 1) ACCNO del TXT
    // 2) mismo idpaciente + mismo numeroinforme del PDF (aunque el ACCNO difiera)
    $accList = [];
    $pidList = [];
    foreach ($pares as $p) {
        $acc = trim((string)($p['txt']['accession_number'] ?? ''));
        if ($acc !== '') {
            $accList[$acc] = true;
        }
        $pid = trim((string)($p['idpaciente'] ?? ''));
        if ($pid !== '') {
            $pidList[$pid] = true;
        }
    }
    $dupByAcc = [];
    if ($accList) {
        $phAcc = implode(',', array_fill(0, count($accList), '?'));
        $qDupAcc = $db->prepare("SELECT accession_number, id FROM informes_recibidos WHERE accession_number IN ($phAcc)");
        $qDupAcc->execute(array_keys($accList));
        foreach ($qDupAcc->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $dupByAcc[(string)$r['accession_number']] = (int)$r['id'];
        }
    }

    // Buscar en informes_carpeta_archivos PDFs con mismo idpaciente+sufijo que ya fueron ingresados.
    // Esto es más fiable que usar informes_recibidos.pdf_path, que no conserva el nombre original.
    $dupByPdfKey = [];
    if ($pidList) {
        $phPid = implode(',', array_fill(0, count($pidList), '?'));
        $qDupPdf = $db->prepare(
            "SELECT idpaciente, sufijo_nombre, informe_recibido_id
               FROM informes_carpeta_archivos
              WHERE tipo = 'pdf'
                AND idpaciente IN ($phPid)
                AND estado IN ('ingresado','omitido_duplicado')
                AND informe_recibido_id IS NOT NULL"
        );
        $qDupPdf->execute(array_keys($pidList));
        foreach ($qDupPdf->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pid = trim((string)($r['idpaciente'] ?? ''));
            $num = trim((string)($r['sufijo_nombre'] ?? ''));
            if ($pid === '' || $num === '') {
                continue;
            }
            $key = $pid . '|' . $num;
            if (!isset($dupByPdfKey[$key])) {
                $dupByPdfKey[$key] = (int)$r['informe_recibido_id'];
            }
        }
    }

    foreach ($pares as &$p) {
        $acc = trim((string)($p['txt']['accession_number'] ?? ''));
        $pid = trim((string)($p['idpaciente'] ?? ''));
        $num = trim((string)($p['pdf']['numero_informe'] ?? ''));

        $existingAcc = ($acc !== '' && isset($dupByAcc[$acc])) ? (int)$dupByAcc[$acc] : null;
        $keyPdf = ($pid !== '' && $num !== '') ? ($pid . '|' . $num) : '';
        $existingPdf = ($keyPdf !== '' && isset($dupByPdfKey[$keyPdf])) ? (int)$dupByPdfKey[$keyPdf] : null;

        $existing = $existingAcc ?? $existingPdf;
        $reason = null;
        if ($existingAcc !== null) {
            $reason = 'accession_number';
        } elseif ($existingPdf !== null) {
            $reason = 'pdf_numero_informe';
        }

        $p['ya_existe_en_ir'] = $existing !== null;
        $p['informe_recibido_id_existente'] = $existing;
        $p['motivo_duplicado'] = $reason;
    }
    unset($p);

    // Ordenar: primero fecha_exacta (score >= 60), luego aproximada, luego sin_fecha
    usort($pares, static function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    // Segundo pasada: propagar duplicado entre pares con mismo idpaciente+numero_informe PDF.
    // Ejemplo: PDF "10019638_97519.3.pdf" emparejado con TXT-A (marcado dup por ACCNO, IR#54)
    //          y con TXT-B (diferente ACCNO, sin IR) → también debe quedar marcado como dup.
    $pdfKeyPrimer = []; // "pid|num" → informe_recibido_id del primer par visto
    foreach ($pares as &$p) {
        $pid = trim((string)($p['idpaciente'] ?? ''));
        $num = trim((string)($p['pdf']['numero_informe'] ?? ''));
        $pdfKey = ($pid !== '' && $num !== '') ? ($pid . '|' . $num) : '';
        if ($pdfKey === '') {
            continue;
        }
        if (isset($pdfKeyPrimer[$pdfKey])) {
            // Ya vimos este PDF antes → duplicado dentro del mismo lote de preview
            if (!$p['ya_existe_en_ir']) {
                $p['ya_existe_en_ir'] = true;
                $p['informe_recibido_id_existente'] = $pdfKeyPrimer[$pdfKey]; // puede ser null si el primero tampoco existe en IR aún
                $p['motivo_duplicado'] = 'pdf_numero_informe';
            }
        } else {
            // Primera aparición de este PDF; registrar su IR (puede ser null)
            $pdfKeyPrimer[$pdfKey] = $p['informe_recibido_id_existente'];
        }
    }
    unset($p);

    return [
        'pares'       => $pares,
        'sin_par_pdf' => $sinParPdfIds,
        'sin_par_txt' => $sinParTxt,
    ];
}

/**
 * Ingesta forzada de un par PDF+TXT elegido manualmente (omite scoring).
 * Requiere que ambos archivos estén en estado detectado/pendiente_par.
 *
 * @return array{duplicado:bool,informe_recibido_id:int}
 * @throws Exception
 */
function ic_force_ingest_pair(PDO $db, int $pdfId, int $txtId): array
{
    $stPdf = $db->prepare("SELECT * FROM informes_carpeta_archivos WHERE id = ? AND tipo = 'pdf' LIMIT 1");
    $stPdf->execute([$pdfId]);
    $pdfRow = $stPdf->fetch(PDO::FETCH_ASSOC);

    $stTxt = $db->prepare("SELECT * FROM informes_carpeta_archivos WHERE id = ? AND tipo = 'txt' LIMIT 1");
    $stTxt->execute([$txtId]);
    $txtRow = $stTxt->fetch(PDO::FETCH_ASSOC);

    if (!$pdfRow) {
        throw new Exception("PDF id=$pdfId no encontrado en informes_carpeta_archivos");
    }
    if (!$txtRow) {
        throw new Exception("TXT id=$txtId no encontrado en informes_carpeta_archivos");
    }
    if (!in_array($pdfRow['estado'], ['detectado', 'pendiente_par', 'error'], true)) {
        throw new Exception("PDF ya procesado (estado: {$pdfRow['estado']})");
    }
    if (!in_array($txtRow['estado'], ['detectado', 'pendiente_par', 'error'], true)) {
        throw new Exception("TXT ya procesado (estado: {$txtRow['estado']})");
    }

    $txtContent = @file_get_contents($txtRow['ruta_absoluta']);
    if ($txtContent === false) {
        throw new Exception("No se pudo leer TXT: {$txtRow['ruta_absoluta']}");
    }
    $dicomData = ir_parse_dicom_txt_content($txtContent);

    $accNo = trim((string)($dicomData['accession_number'] ?? ''));
    if ($accNo !== '') {
        $dupChk = $db->prepare("SELECT id FROM informes_recibidos WHERE accession_number = ? LIMIT 1");
        $dupChk->execute([$accNo]);
        $existingId = $dupChk->fetchColumn();
        if ($existingId !== false) {
            $existingId  = (int)$existingId;
            $pairExtra   = ['emparejamiento' => 'confirmado_manual', 'score_emparejamiento' => 999, 'informe_recibido_id' => $existingId, 'duplicado_accno' => $accNo, 'motivo_omision' => 'ACCNO ya existe en informes_recibidos'];
            $metaPdf     = json_decode($pdfRow['metadata_json'] ?: '{}', true) ?: [];
            $metaTxt     = json_decode($txtRow['metadata_json'] ?: '{}', true) ?: [];
            $u = $db->prepare("UPDATE informes_carpeta_archivos SET estado='omitido_duplicado', parquet_con_id=?, informe_recibido_id=?, metadata_json=? WHERE id=?");
            $u->execute([$txtId, $existingId, json_encode(array_merge($metaPdf, $pairExtra), JSON_UNESCAPED_UNICODE), $pdfId]);
            $u->execute([$pdfId, $existingId, json_encode(array_merge($metaTxt, $pairExtra), JSON_UNESCAPED_UNICODE), $txtId]);
            return ['duplicado' => true, 'informe_recibido_id' => $existingId];
        }
    }

    // Regla adicional: mismo paciente + mismo número de informe PDF (aunque cambie ACCNO)
    $pdfNumero = trim((string)($pdfRow['sufijo_nombre'] ?? ''));
    $pid = trim((string)($pdfRow['idpaciente'] ?? ''));
    if ($pid !== '' && $pdfNumero !== '') {
        $dupPdf = $db->prepare("SELECT id, pdf_path FROM informes_recibidos WHERE patient_id = ?");
        $dupPdf->execute([$pid]);
        foreach ($dupPdf->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $numIr = ic_pdf_report_number_from_path((string)($r['pdf_path'] ?? ''));
            if ($numIr !== '' && strcasecmp($numIr, $pdfNumero) === 0) {
                $existingId = (int)$r['id'];
                $pairExtra  = [
                    'emparejamiento' => 'confirmado_manual',
                    'score_emparejamiento' => 999,
                    'informe_recibido_id' => $existingId,
                    'motivo_omision' => 'Mismo idpaciente + numero_informe PDF ya existe en informes_recibidos',
                    'duplicado_pdf_numero_informe' => $pdfNumero,
                ];
                $metaPdf = json_decode($pdfRow['metadata_json'] ?: '{}', true) ?: [];
                $metaTxt = json_decode($txtRow['metadata_json'] ?: '{}', true) ?: [];
                $u = $db->prepare("UPDATE informes_carpeta_archivos SET estado='omitido_duplicado', parquet_con_id=?, informe_recibido_id=?, metadata_json=? WHERE id=?");
                $u->execute([$txtId, $existingId, json_encode(array_merge($metaPdf, $pairExtra), JSON_UNESCAPED_UNICODE), $pdfId]);
                $u->execute([$pdfId, $existingId, json_encode(array_merge($metaTxt, $pairExtra), JSON_UNESCAPED_UNICODE), $txtId]);
                return ['duplicado' => true, 'informe_recibido_id' => $existingId];
            }
        }
    }

    ic_load_ir_functions();
    $ing  = ir_ingest_recibido_pair_from_paths($db, $pdfRow['ruta_absoluta'], $txtRow['ruta_absoluta'], $dicomData, basename($pdfRow['ruta_absoluta']));
    $irId = (int)$ing['informe_recibido_id'];

    $pairExtra = ['emparejamiento' => 'confirmado_manual', 'score_emparejamiento' => 999, 'informe_recibido_id' => $irId];
    $metaPdf   = json_decode($pdfRow['metadata_json'] ?: '{}', true) ?: [];
    $metaTxt   = json_decode($txtRow['metadata_json'] ?: '{}', true) ?: [];
    $u = $db->prepare("UPDATE informes_carpeta_archivos SET estado='ingresado', parquet_con_id=?, informe_recibido_id=?, metadata_json=? WHERE id=?");
    $u->execute([$txtId, $irId, json_encode(array_merge($metaPdf, $pairExtra), JSON_UNESCAPED_UNICODE), $pdfId]);
    $u->execute([$pdfId, $irId, json_encode(array_merge($metaTxt, $pairExtra), JSON_UNESCAPED_UNICODE), $txtId]);

    if (!empty($ing['pending_pacs_send'])) {
        ir_enviarPacsSiCorresponde($db, (int)$ing['pending_pacs_send'], $irId);
    }

    return ['duplicado' => false, 'informe_recibido_id' => $irId];
}

/**
 * Enriquece el metadata_json de una fila con los nuevos campos (pdf_description,
 * procedure_description, modality) si aún no los tiene. Útil al reprocesar registros
 * creados con versiones anteriores del código.
 */
function ic_enrich_metadata_if_needed(PDO $db, array $row): void
{
    $meta = json_decode($row['metadata_json'] ?? '{}', true);
    if (!is_array($meta)) {
        $meta = [];
    }

    $changed = false;

    if ($row['tipo'] === 'pdf' && !array_key_exists('pdf_description', $meta)) {
        if (is_readable($row['ruta_absoluta'])) {
            $ptext = ic_pdf_first_page_text($row['ruta_absoluta']);
            $meta['pdf_description'] = ic_extract_pdf_description($ptext);
            // También re-extraemos la fecha por si tampoco estaba
            if (!array_key_exists('pdf_report_date', $meta)) {
                $meta['pdf_report_date'] = ic_extract_pdf_report_date($ptext);
            }
            $changed = true;
        }
    }

    if ($row['tipo'] === 'txt' && (!array_key_exists('procedure_description', $meta) || !array_key_exists('modality', $meta))) {
        if (is_readable($row['ruta_absoluta'])) {
            $txtContent = @file_get_contents($row['ruta_absoluta']);
            if ($txtContent !== false) {
                try {
                    $dicom = ir_parse_dicom_txt_content($txtContent);
                    if (!array_key_exists('procedure_description', $meta)) {
                        $meta['procedure_description'] = $dicom['procedure_description'] ?? null;
                    }
                    if (!array_key_exists('modality', $meta)) {
                        $meta['modality'] = $dicom['modality'] ?? null;
                    }
                    if (!array_key_exists('procedure_date', $meta)) {
                        $meta['procedure_date'] = ic_procedure_date_from_dicom($dicom);
                    }
                    $changed = true;
                } catch (Exception $e) {
                    // No se puede leer el TXT; dejar como está
                }
            }
        }
    }

    if ($changed) {
        $db->prepare("UPDATE informes_carpeta_archivos SET metadata_json = ? WHERE id = ?")
           ->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $row['id']]);
    }
}

/**
 * Fase 1 del reprocesador: reintenta el emparejamiento de filas ya en BD
 * que están en estado detectado o pendiente_par.
 *
 * @return array{retried:int,ingresados:int,omitidos_duplicado:int,pendiente_par_aun:int}
 */
function ic_retry_pendiente_par(PDO $db, int $limite = 300): array
{
    $st = $db->prepare(
        "SELECT * FROM informes_carpeta_archivos
         WHERE estado IN ('pendiente_par','detectado')
         ORDER BY fecha_deteccion ASC
         LIMIT ?"
    );
    $st->execute([$limite]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $ids  = array_column($rows, 'id');

    // Enriquecer metadata con los nuevos campos antes de intentar emparejar
    foreach ($rows as $row) {
        ic_enrich_metadata_if_needed($db, $row);
    }

    $retried         = 0;
    $ingresados      = 0;
    $omitidosDup     = 0;
    $aun             = 0;

    foreach ($ids as $id) {
        ic_try_pair_and_ingest($db, (int)$id);
        $retried++;
        $chk = $db->prepare("SELECT estado FROM informes_carpeta_archivos WHERE id = ? LIMIT 1");
        $chk->execute([(int)$id]);
        $estadoNuevo = (string)$chk->fetchColumn();
        if ($estadoNuevo === 'ingresado') {
            $ingresados++;
        } elseif ($estadoNuevo === 'omitido_duplicado') {
            $omitidosDup++;
        } else {
            $aun++;
        }
    }

    return [
        'retried'             => $retried,
        'ingresados'          => $ingresados,
        'omitidos_duplicado'  => $omitidosDup,
        'pendiente_par_aun'   => $aun,
    ];
}

/**
 * Fase 2 del reprocesador: escanea los archivos en disco que aún no están en la BD
 * (sin el usleep de estabilidad) y los registra + empareja.
 * Procesa hasta $limite archivos por llamada; retorna si hay más.
 *
 * @return array{total_en_carpeta:int,procesados_lote:int,nuevos:int,omitidos:int,errores:int,hay_mas:bool}
 */
function ic_scan_and_ingest_batch(PDO $db, int $limite = 300): array
{
    $paths   = ic_get_config_paths($db);
    $allFiles = [];

    foreach ([$paths['pdf'], $paths['txt']] as $dir) {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        if (!is_dir($dir) || !is_readable($dir)) {
            continue;
        }
        foreach (['*.pdf', '*.PDF', '*.txt', '*.TXT'] as $pat) {
            foreach (glob($dir . '/' . $pat, GLOB_NOSORT) ?: [] as $f) {
                if (is_file($f)) {
                    $allFiles[] = $f;
                }
            }
        }
    }
    $allFiles = array_values(array_unique($allFiles));
    $total    = count($allFiles);

    // Obtener sha256s ya registrados para saltar rápido archivos conocidos
    $knownSha = [];
    try {
        $shaSt = $db->query("SELECT sha256, tipo FROM informes_carpeta_archivos WHERE sha256 IS NOT NULL");
        foreach ($shaSt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $knownSha[$row['sha256'] . '|' . $row['tipo']] = true;
        }
    } catch (Throwable $e) {
        // tabla vacía o sin columna; continuar sin cache
    }

    $procesados = 0;
    $nuevos     = 0;
    $omitidos   = 0;
    $errores    = 0;

    foreach ($allFiles as $path) {
        if ($procesados >= $limite) {
            break;
        }

        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $tipo = ($ext === 'pdf') ? 'pdf' : (($ext === 'txt') ? 'txt' : null);
        if ($tipo === null) {
            continue;
        }

        // Salto rápido: calcular sha256 sin usleep y ver si ya está en BD
        if (!is_readable($path)) {
            continue;
        }
        $sha = @hash_file('sha256', $path);
        if ($sha !== false && isset($knownSha[$sha . '|' . $tipo])) {
            // Ya en BD — no contar en lote (son archivos ya conocidos)
            continue;
        }

        // Archivo genuinamente nuevo: procesar sin stability check
        $r = ic_process_filepath($db, $path, true);
        $procesados++;
        $msg = $r['message'] ?? '';
        if (!$r['ok']) {
            $errores++;
        } elseif (
            strpos($msg, 'Duplicado') !== false
            || strpos($msg, 'desactivada') !== false
        ) {
            $omitidos++;
        } else {
            $nuevos++;
            // Actualizar cache local
            if ($sha !== false) {
                $knownSha[$sha . '|' . $tipo] = true;
            }
        }
    }

    return [
        'total_en_carpeta' => $total,
        'procesados_lote'  => $procesados,
        'nuevos'           => $nuevos,
        'omitidos'         => $omitidos,
        'errores'          => $errores,
        'hay_mas'          => ($procesados >= $limite && $total > $procesados),
    ];
}
