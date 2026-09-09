<?php
/**
 * Listado de solo lectura de audios para auditores (no modifica audios ni flujos existentes).
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/_common.php';
auditManagerRequireAuditor();

$db = getDBConnection();

function auditTableExists(PDO $db, string $table): bool {
    try {
        $st = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $st->execute([$table]);

        return (int) $st->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function auditColumnExists(PDO $db, string $table, string $column): bool {
    try {
        $st = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $st->execute([$table, $column]);

        return (int) $st->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function auditFormatLabel(?string $mime, ?string $fileName): string {
    $mime = $mime ? strtolower(trim($mime)) : '';
    if ($mime !== '') {
        if (strpos($mime, 'webm') !== false) {
            return 'WebM';
        }
        if (strpos($mime, 'mpeg') !== false || strpos($mime, 'mp3') !== false) {
            return 'MP3';
        }
        if (strpos($mime, 'wav') !== false) {
            return 'WAV';
        }
        if (strpos($mime, 'mp4') !== false || strpos($mime, 'm4a') !== false) {
            return 'MP4/M4A';
        }
        if (strpos($mime, 'ogg') !== false) {
            return 'OGG';
        }
        if (strpos($mime, 'flac') !== false) {
            return 'FLAC';
        }
        if (strpos($mime, 'aac') !== false) {
            return 'AAC';
        }
        if (strpos($mime, 'audio/') === 0) {
            return substr($mime, 6, 20);
        }
    }
    $ext = strtolower(pathinfo((string) $fileName, PATHINFO_EXTENSION));

    return $ext !== '' ? strtoupper($ext) : '—';
}

/**
 * Duración en segundos a partir de datos_sincronizacion (palabras con startTime/endTime).
 * sync-editor: tiempos en milisegundos. sync-recorder: en segundos (ítems con confidence/segmentIndex).
 */
function auditDurationSecondsFromSyncJson($raw): ?float {
    if ($raw === null || $raw === '') {
        return null;
    }
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || $decoded === []) {
            return null;
        }
    } elseif (is_array($raw)) {
        $decoded = $raw;
    } else {
        return null;
    }

    $words = $decoded;
    if (isset($decoded['words']) && is_array($decoded['words'])) {
        $words = $decoded['words'];
    }
    if (!is_array($words) || $words === []) {
        return null;
    }

    $maxEnd = 0.0;
    $first = null;
    foreach ($words as $item) {
        if (!is_array($item)) {
            continue;
        }
        if ($first === null) {
            $first = $item;
        }
        foreach (['endTime', 'end', 'end_time'] as $k) {
            if (isset($item[$k]) && is_numeric($item[$k])) {
                $v = (float) $item[$k];
                if ($v > $maxEnd) {
                    $maxEnd = $v;
                }
                break;
            }
        }
    }
    if ($maxEnd <= 0) {
        return null;
    }

    $timesInSeconds = false;
    if ($first !== null) {
        if (array_key_exists('segmentIndex', $first) || array_key_exists('confidence', $first)) {
            $timesInSeconds = true;
        }
    }

    $sec = $timesInSeconds ? $maxEnd : ($maxEnd / 1000.0);

    return $sec > 0 ? round($sec, 2) : null;
}

/**
 * @param mixed $syncRaw columna datos_sincronizacion (string JSON o null)
 */
function auditResolveAudioDurationSeconds(?float $dbDur, $syncRaw, bool $hasDatosSyncCol): ?float {
    $durEffective = ($dbDur !== null && $dbDur > 0) ? round($dbDur, 2) : null;
    if ($durEffective !== null) {
        return $durEffective;
    }
    if (!$hasDatosSyncCol || $syncRaw === null || $syncRaw === '') {
        return null;
    }

    return auditDurationSecondsFromSyncJson($syncRaw);
}

function auditBytesLabel($bytes): string {
    $n = (int) $bytes;
    if ($n <= 0) {
        return '—';
    }
    if ($n < 1024) {
        return $n . ' B';
    }
    if ($n < 1048576) {
        return round($n / 1024, 1) . ' KB';
    }

    return round($n / 1048576, 2) . ' MB';
}

function auditSlugFilePart(string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') {
        return '';
    }
    $value = preg_replace('/[^a-z0-9]+/i', '_', $value);
    $value = trim((string) $value, '_');

    return $value !== '' ? $value : '';
}

function auditDisplayFileName(string $storedName, string $originLabel, string $userLabel, string $email, ?string $createdAt, int $audioId): string {
    $storedName = trim($storedName);
    if ($storedName === '') {
        return '—';
    }

    // Para móviles, normalizar nombres auto-generados (audio__..., audio_...)
    // con un formato legible y consistente con workspace.
    if (strtolower(trim($originLabel)) === 'móvil' && preg_match('/^audio_+.*\.[a-z0-9]+$/i', $storedName)) {
        $ext = strtolower(pathinfo($storedName, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = 'webm';
        }
        $base = auditSlugFilePart($email);
        if ($base === '') {
            $base = auditSlugFilePart($userLabel);
        }
        if ($base === '') {
            $base = 'usuario';
        }
        $dt = $createdAt ? strtotime($createdAt) : false;
        $stamp = $dt ? date('Y-m-d_H-i-s', $dt) : date('Y-m-d_H-i-s');

        return $base . '_' . $stamp . '_' . $audioId . '.' . $ext;
    }

    return $storedName;
}

if (!auditTableExists($db, 'audios_informe')) {
    echo json_encode([
        'success' => true,
        'audios' => [],
        'total' => 0,
        'page' => 1,
        'limit' => 50,
        'stats' => null,
        'audios_table_configured' => false,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$hasTamano = auditColumnExists($db, 'audios_informe', 'tamano_bytes');
$hasMime = auditColumnExists($db, 'audios_informe', 'tipo_mime');
$hasActivo = auditColumnExists($db, 'audios_informe', 'activo');
$hasEstado = auditColumnExists($db, 'audios_informe', 'estado');
$hasMobile = auditColumnExists($db, 'audios_informe', 'mobile_session_id');
$hasFechaFtp = auditColumnExists($db, 'audios_informe', 'fecha_envio_ftp');
$hasFechaTx = auditColumnExists($db, 'audios_informe', 'fecha_envio_transcripcion');
$hasEstudios = auditTableExists($db, 'estudios');
$hasStudyUid = $hasEstudios && auditColumnExists($db, 'estudios', 'study_instance_uid');
$hasPatientNamePacs = $hasEstudios && auditColumnExists($db, 'estudios', 'patient_name_pacs');
$hasPatientName = $hasEstudios && auditColumnExists($db, 'estudios', 'patient_name');
$hasModality = $hasEstudios && auditColumnExists($db, 'estudios', 'modality');
$hasStudyDate = $hasEstudios && auditColumnExists($db, 'estudios', 'study_date');
$hasInformes = auditTableExists($db, 'informes');
$hasInformesEstudioId = $hasInformes && auditColumnExists($db, 'informes', 'estudio_id');
$hasInformesPatientName = $hasInformes && auditColumnExists($db, 'informes', 'patient_name');
$hasInformesModality = $hasInformes && auditColumnExists($db, 'informes', 'modality');
$hasFtpLog = auditTableExists($db, 'audios_ftp_log');
$hasTxQueue = auditTableExists($db, 'ai_transcription_queue');
$hasDatosSync = auditColumnExists($db, 'audios_informe', 'datos_sincronizacion');

$fromIn = isset($_GET['from']) ? trim((string) $_GET['from']) : '';
$toIn = isset($_GET['to']) ? trim((string) $_GET['to']) : '';
$userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
$userQ = isset($_GET['user_q']) ? trim((string) $_GET['user_q']) : '';
if (strlen($userQ) > 120) {
    $userQ = substr($userQ, 0, 120);
}
$studyQ = isset($_GET['study_q']) ? trim((string) $_GET['study_q']) : '';
if (strlen($studyQ) > 120) {
    $studyQ = substr($studyQ, 0, 120);
}
$estadoFilter = isset($_GET['estado']) ? trim((string) $_GET['estado']) : '';
if (strlen($estadoFilter) > 40) {
    $estadoFilter = substr($estadoFilter, 0, 40);
}
$origin = isset($_GET['origin']) ? trim((string) $_GET['origin']) : '';
$ftpFilter = isset($_GET['ftp']) ? trim((string) $_GET['ftp']) : '';
$txFilter = isset($_GET['transcription']) ? trim((string) $_GET['transcription']) : '';
$includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] === '1';

$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;

if ($fromIn === '' || !preg_match('/^\d{4}-\d{2}-\d{2}/', $fromIn)) {
    $fromIn = date('Y-m-d', strtotime('-30 days'));
}
if ($toIn === '' || !preg_match('/^\d{4}-\d{2}-\d{2}/', $toIn)) {
    $toIn = date('Y-m-d');
}

$fromDt = $fromIn . ' 00:00:00';
$toDt = $toIn . ' 23:59:59';

$where = ['ai.fecha_creacion >= ?', 'ai.fecha_creacion <= ?'];
$params = [$fromDt, $toDt];

if ($hasActivo && !$includeInactive) {
    $where[] = '(ai.activo = 1 OR ai.activo IS NULL)';
}

if ($userId > 0) {
    $where[] = 'ai.usuario_id = ?';
    $params[] = $userId;
}

if ($userQ !== '') {
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $userQ) . '%';
    $where[] = '(u.nombre LIKE ? OR u.apellido LIKE ? OR u.email LIKE ? OR CONCAT(TRIM(COALESCE(u.nombre,\'\')), \' \', TRIM(COALESCE(u.apellido,\'\'))) LIKE ?)';
    array_push($params, $like, $like, $like, $like);
}

if ($studyQ !== '') {
    $likeS = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $studyQ) . '%';
    $where[] = 'ai.estudio_id LIKE ?';
    $params[] = $likeS;
}

if ($estadoFilter !== '' && $hasEstado) {
    $where[] = 'ai.estado = ?';
    $params[] = $estadoFilter;
}

if ($origin === 'mobile') {
    $parts = [];
    if ($hasMobile) {
        $parts[] = "(TRIM(COALESCE(ai.mobile_session_id,'')) <> '')";
    }
    $parts[] = "(LOWER(TRIM(COALESCE(ai.tipo_grabacion,''))) = 'mobile')";
    $where[] = '(' . implode(' OR ', $parts) . ')';
} elseif ($origin === 'workspace') {
    $neg = [];
    if ($hasMobile) {
        $neg[] = "(TRIM(COALESCE(ai.mobile_session_id,'')) = '')";
    }
    $neg[] = "(LOWER(TRIM(COALESCE(ai.tipo_grabacion,''))) <> 'mobile')";
    $where[] = '(' . implode(' AND ', $neg) . ')';
}

if ($hasFtpLog) {
    if ($ftpFilter === 'yes') {
        $where[] = 'EXISTS (SELECT 1 FROM audios_ftp_log fl WHERE fl.audio_id = ai.id AND fl.status = \'success\' LIMIT 1)';
    } elseif ($ftpFilter === 'no') {
        $where[] = 'NOT EXISTS (SELECT 1 FROM audios_ftp_log fl WHERE fl.audio_id = ai.id AND fl.status = \'success\' LIMIT 1)';
    }
}

if ($hasTxQueue && $txFilter !== '' && $txFilter !== 'all') {
    if ($txFilter === 'none') {
        $where[] = 'NOT EXISTS (SELECT 1 FROM ai_transcription_queue q0 WHERE q0.audio_id = ai.id LIMIT 1)';
    } elseif ($txFilter === 'pending') {
        $where[] = 'EXISTS (SELECT 1 FROM ai_transcription_queue q1 WHERE q1.audio_id = ai.id AND q1.status IN (\'pending\',\'processing\') LIMIT 1)';
    } elseif ($txFilter === 'completed') {
        $where[] = 'EXISTS (SELECT 1 FROM ai_transcription_queue q2 WHERE q2.audio_id = ai.id AND q2.status = \'completed\' LIMIT 1)';
    } elseif ($txFilter === 'failed') {
        $where[] = 'EXISTS (SELECT 1 FROM ai_transcription_queue q3 WHERE q3.audio_id = ai.id AND q3.status = \'failed\' LIMIT 1)';
    } elseif ($txFilter === 'any') {
        $where[] = 'EXISTS (SELECT 1 FROM ai_transcription_queue q4 WHERE q4.audio_id = ai.id LIMIT 1)';
    }
}

$whereSql = implode(' AND ', $where);

$selectExtra = '';
if ($hasTamano) {
    $selectExtra .= ', ai.tamano_bytes';
}
if ($hasMime) {
    $selectExtra .= ', ai.tipo_mime';
}
if ($hasEstado) {
    $selectExtra .= ', ai.estado';
}
if ($hasMobile) {
    $selectExtra .= ', ai.mobile_session_id';
}
if ($hasFechaFtp) {
    $selectExtra .= ', ai.fecha_envio_ftp';
}
if ($hasFechaTx) {
    $selectExtra .= ', ai.fecha_envio_transcripcion';
}
if ($hasDatosSync) {
    $selectExtra .= ', ai.datos_sincronizacion';
}

$joinEstudio = '';
$selectEstudio = ", NULL AS study_patient_name, NULL AS study_modality, NULL AS study_date_local, NULL AS study_instance_uid";
$joinInforme = '';
 $selectInforme = ", NULL AS informe_patient_name, NULL AS informe_modality";
if ($hasInformes) {
    $joinInforme = 'LEFT JOIN informes inf ON inf.id = ai.informe_id';
    $selectInforme = ', ' . ($hasInformesPatientName ? 'inf.patient_name' : 'NULL') . ' AS informe_patient_name, ' . ($hasInformesModality ? 'inf.modality' : 'NULL') . ' AS informe_modality';
}
if ($hasEstudios) {
    $candidateAi = "LOWER(TRIM(COALESCE(ai.estudio_id, '')))";
    $candidateInf = ($hasInformes && $hasInformesEstudioId) ? "LOWER(TRIM(COALESCE(inf.estudio_id, '')))" : "''";
    $matchByOrthanc = "(e2.orthanc_study_id IS NOT NULL AND LOWER(TRIM(e2.orthanc_study_id)) IN ($candidateAi, $candidateInf))";
    $matchByStudyUid = $hasStudyUid ? " OR (e2.study_instance_uid IS NOT NULL AND LOWER(TRIM(e2.study_instance_uid)) IN ($candidateAi, $candidateInf))" : '';
    $matchByLocalId = " OR LOWER(TRIM(CAST(e2.id AS CHAR))) IN ($candidateAi, $candidateInf)";
    $studyUidOrder = $hasStudyUid ? " + (CASE WHEN e2.study_instance_uid IS NOT NULL AND LOWER(TRIM(e2.study_instance_uid)) IN ($candidateAi, $candidateInf) THEN 10 ELSE 0 END)" : '';
    $joinEstudio = "LEFT JOIN estudios e ON e.id = (
        SELECT e2.id
        FROM estudios e2
        WHERE $matchByOrthanc
            $matchByStudyUid
            $matchByLocalId
        ORDER BY
            (CASE WHEN e2.orthanc_study_id IS NOT NULL AND LOWER(TRIM(e2.orthanc_study_id)) IN ($candidateAi, $candidateInf) THEN 20 ELSE 0 END)
            $studyUidOrder
            + (CASE WHEN LOWER(TRIM(CAST(e2.id AS CHAR))) IN ($candidateAi, $candidateInf) THEN 5 ELSE 0 END) DESC,
            e2.id DESC
        LIMIT 1
    )";
    $patientExpr = 'NULL';
    if ($hasPatientNamePacs && $hasPatientName) {
        $patientExpr = 'COALESCE(e.patient_name_pacs, e.patient_name)';
    } elseif ($hasPatientNamePacs) {
        $patientExpr = 'e.patient_name_pacs';
    } elseif ($hasPatientName) {
        $patientExpr = 'e.patient_name';
    }
    $modalityExpr = $hasModality ? 'e.modality' : 'NULL';
    $studyDateExpr = $hasStudyDate ? 'e.study_date' : 'NULL';
    $studyUidExpr = $hasStudyUid ? 'e.study_instance_uid' : 'NULL';
    $selectEstudio = ', ' . $patientExpr . ' AS study_patient_name, ' . $modalityExpr . ' AS study_modality, ' . $studyDateExpr . ' AS study_date_local, ' . $studyUidExpr . ' AS study_instance_uid';
}

$selectFtp = ', 0 AS ftp_success, NULL AS ftp_file_name';
if ($hasFtpLog) {
    $selectFtp = ', CASE WHEN EXISTS (SELECT 1 FROM audios_ftp_log fls WHERE fls.audio_id = ai.id AND fls.status = \'success\' LIMIT 1) THEN 1 ELSE 0 END AS ftp_success'
               . ', (SELECT fln.file_name FROM audios_ftp_log fln WHERE fln.audio_id = ai.id AND fln.status = \'success\' ORDER BY fln.sent_at DESC LIMIT 1) AS ftp_file_name';
}

$selectQueue = ', NULL AS queue_status';
if ($hasTxQueue) {
    $selectQueue = ', (SELECT qx.status FROM ai_transcription_queue qx WHERE qx.audio_id = ai.id ORDER BY qx.id DESC LIMIT 1) AS queue_status';
}

$sqlBase = "FROM audios_informe ai
    INNER JOIN usuarios u ON u.id = ai.usuario_id
    $joinInforme
    $joinEstudio
    WHERE $whereSql";

if ($hasDatosSync) {
    $statsStmt = $db->prepare('SELECT ai.duracion_segundos, ai.datos_sincronizacion ' . $sqlBase);
    $statsStmt->execute($params);
    $total = 0;
    $durSum = 0.0;
    while ($sr = $statsStmt->fetch(PDO::FETCH_ASSOC)) {
        $total++;
        $dbDur = $sr['duracion_segundos'] !== null ? (float) $sr['duracion_segundos'] : null;
        $eff = auditResolveAudioDurationSeconds($dbDur, $sr['datos_sincronizacion'] ?? null, true);
        if ($eff !== null) {
            $durSum += $eff;
        }
    }
} else {
    $statsSql = "SELECT COUNT(*) AS cnt, COALESCE(SUM(COALESCE(ai.duracion_segundos, 0)), 0) AS dur_sum $sqlBase";
    $statsStmt = $db->prepare($statsSql);
    $statsStmt->execute($params);
    $statsRow = $statsStmt->fetch(PDO::FETCH_ASSOC);
    $total = (int) ($statsRow['cnt'] ?? 0);
    $durSum = (float) ($statsRow['dur_sum'] ?? 0);
}
$stats = [
    'total_audios' => $total,
    'total_minutes' => round($durSum / 60.0, 2),
    'total_seconds' => round($durSum, 2),
];

$selectList = "SELECT ai.id, ai.usuario_id, ai.estudio_id, ai.informe_id, ai.tipo_grabacion,
    ai.nombre_archivo, ai.duracion_segundos, ai.fecha_creacion,
    u.nombre, u.apellido, u.email
    $selectExtra
    $selectInforme
    $selectEstudio
    $selectFtp
    $selectQueue
    $sqlBase
    ORDER BY ai.fecha_creacion DESC, ai.id DESC
    LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset;

$listStmt = $db->prepare($selectList);
$listStmt->execute($params);
$rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$out = [];
foreach ($rows as $r) {
    $ftpOk = !empty($r['ftp_success']);
    $dbDur = $r['duracion_segundos'] !== null ? (float) $r['duracion_segundos'] : null;
    $syncRaw = $hasDatosSync ? ($r['datos_sincronizacion'] ?? null) : null;
    $durEffective = auditResolveAudioDurationSeconds($dbDur, $syncRaw, $hasDatosSync);
    $dbDurationMissing = $r['duracion_segundos'] === null || (float) $r['duracion_segundos'] <= 0;
    $originLabel = (($hasMobile && !empty($r['mobile_session_id']))
            || strtolower(trim((string) ($r['tipo_grabacion'] ?? ''))) === 'mobile')
            ? 'móvil' : 'workspace';
    $studyUid = $r['study_instance_uid'] ?? null;
    $effectiveStudyId = ($studyUid !== null && trim((string) $studyUid) !== '')
        ? (string) $studyUid
        : (string) ($r['estudio_id'] ?? '');
    $userLabel = trim(($r['nombre'] ?? '') . ' ' . ($r['apellido'] ?? ''));
    $displayFileName = auditDisplayFileName(
        (string) ($r['nombre_archivo'] ?? ''),
        $originLabel,
        $userLabel,
        (string) ($r['email'] ?? ''),
        $r['fecha_creacion'] ?? null,
        (int) $r['id']
    );
    $studyPatientName = $r['study_patient_name'] ?? null;
    if (($studyPatientName === null || trim((string) $studyPatientName) === '') && isset($r['informe_patient_name'])) {
        $studyPatientName = $r['informe_patient_name'];
    }
    $studyModality = $r['study_modality'] ?? null;
    if (($studyModality === null || trim((string) $studyModality) === '') && isset($r['informe_modality'])) {
        $studyModality = $r['informe_modality'];
    }
    $out[] = [
        'id' => (int) $r['id'],
        'usuario_id' => (int) $r['usuario_id'],
        'user_label' => $userLabel,
        'email' => $r['email'] ?? '',
        'estudio_id' => $effectiveStudyId,
        'estudio_id_raw' => $r['estudio_id'] ?? '',
        'informe_id' => $r['informe_id'] !== null ? (int) $r['informe_id'] : null,
        'tipo_grabacion' => $r['tipo_grabacion'] ?? '',
        'nombre_archivo' => $r['nombre_archivo'] ?? '',
        'display_file_name' => $displayFileName,
        'duracion_segundos' => $durEffective,
        'fecha_creacion' => $r['fecha_creacion'] ?? null,
        'tamano_bytes' => $hasTamano && isset($r['tamano_bytes']) ? (int) $r['tamano_bytes'] : null,
        'tamano_label' => $hasTamano ? auditBytesLabel($r['tamano_bytes'] ?? 0) : '—',
        'tipo_mime' => $hasMime ? ($r['tipo_mime'] ?? '') : '',
        'format_label' => auditFormatLabel($hasMime ? ($r['tipo_mime'] ?? null) : null, $r['nombre_archivo'] ?? ''),
        'estado' => $hasEstado ? ($r['estado'] ?? null) : null,
        'fecha_envio_ftp' => $hasFechaFtp ? ($r['fecha_envio_ftp'] ?? null) : null,
        'fecha_envio_transcripcion' => $hasFechaTx ? ($r['fecha_envio_transcripcion'] ?? null) : null,
        'study_patient_name' => $studyPatientName,
        'study_modality' => $studyModality,
        'study_date_local' => $r['study_date_local'] ?? null,
        'origin_label' => $originLabel,
        'ftp_sent' => $ftpOk,
        'ftp_destination_format' => $ftpOk ? auditFormatLabel(null, $r['ftp_file_name'] ?? null) : null,
        'transcription_queue_status' => $r['queue_status'] ?? null,
        'db_duration_missing' => $dbDurationMissing,
    ];
}

echo json_encode([
    'success' => true,
    'audios' => $out,
    'total' => $total,
    'page' => $page,
    'limit' => $limit,
    'stats' => $stats,
    'audios_table_configured' => true,
    'meta' => [
        'has_estado' => $hasEstado,
        'has_ftp_log' => $hasFtpLog,
        'has_transcription_queue' => $hasTxQueue,
    ],
], JSON_UNESCAPED_UNICODE);
