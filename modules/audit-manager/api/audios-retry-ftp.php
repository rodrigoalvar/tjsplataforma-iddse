<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/_common.php';
auditManagerRequireAuditor();
$db = getDBConnection();

$input = json_decode(file_get_contents('php://input'), true);
$ids = $input['audio_ids'] ?? [];
if (!is_array($ids) || empty($ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'audio_ids requerido'], JSON_UNESCAPED_UNICODE);
    exit;
}
$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
if (empty($ids)) {
    echo json_encode(['success' => true, 'requeued' => 0, 'skipped' => 0], JSON_UNESCAPED_UNICODE);
    exit;
}

$hasFtpLog = $db->query("SHOW TABLES LIKE 'audios_ftp_log'")->rowCount() > 0;
if (!$hasFtpLog) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No existe audios_ftp_log'], JSON_UNESCAPED_UNICODE);
    exit;
}

$requeued = 0;
$skipped = 0;
$details = [];

$placeholders = implode(',', array_fill(0, count($ids), '?'));

// Traer en lote el último log por audio para evitar N+1 queries.
$lastLogs = [];
$lastSql = "
    SELECT l.audio_id, l.id, l.status
    FROM audios_ftp_log l
    INNER JOIN (
        SELECT audio_id, MAX(id) AS max_id
        FROM audios_ftp_log
        WHERE audio_id IN ($placeholders)
        GROUP BY audio_id
    ) x ON x.max_id = l.id
";
$stLast = $db->prepare($lastSql);
$stLast->execute($ids);
foreach ($stLast->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $lastLogs[(int)$row['audio_id']] = $row;
}

// Para los audios sin log previo, traemos metadatos/FTP en lote.
$audioMetaById = [];
$missingLogIds = [];
foreach ($ids as $audioId) {
    if (!isset($lastLogs[(int)$audioId])) {
        $missingLogIds[] = (int)$audioId;
    }
}
if (!empty($missingLogIds)) {
    $missingPh = implode(',', array_fill(0, count($missingLogIds), '?'));
    $metaSql = "
        SELECT
            ai.id,
            ai.usuario_id,
            ai.nombre_archivo,
            (
                SELECT c2.ftp_host
                FROM usuarios_ftp_config c2
                WHERE c2.usuario_id = ai.usuario_id
                  AND c2.activo = 1
                ORDER BY c2.created_at DESC, c2.id DESC
                LIMIT 1
            ) AS ftp_host,
            (
                SELECT c3.ftp_remote_path
                FROM usuarios_ftp_config c3
                WHERE c3.usuario_id = ai.usuario_id
                  AND c3.activo = 1
                ORDER BY c3.created_at DESC, c3.id DESC
                LIMIT 1
            ) AS ftp_remote_path
        FROM audios_informe ai
        WHERE ai.id IN ($missingPh)
    ";
    $stMeta = $db->prepare($metaSql);
    $stMeta->execute($missingLogIds);
    foreach ($stMeta->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $audioMetaById[(int)$row['id']] = $row;
    }
}

$upd = $db->prepare("
    UPDATE audios_ftp_log
    SET status = 'failed',
        attempts = 0,
        error_message = ?,
        updated_at = NOW()
    WHERE id = ?
");

$ins = $db->prepare("
    INSERT INTO audios_ftp_log (
        audio_id, ftp_host, remote_path, file_name, status, error_message, attempts, sent_at
    ) VALUES (?, ?, ?, ?, 'failed', ?, 0, NULL)
");

foreach ($ids as $audioId) {
    $audioId = (int)$audioId;
    $last = $lastLogs[$audioId] ?? null;
    if (!$last) {
        // Si no hay log histórico, intentar crear una entrada de retry
        // solo cuando el usuario dueño del audio ya tenga FTP activo.
        $audioMeta = $audioMetaById[$audioId] ?? null;
        if (!$audioMeta) {
            $skipped++;
            $details[] = ['audio_id' => $audioId, 'result' => 'skipped_audio_not_found'];
            continue;
        }

        $ftpHost = trim((string)($audioMeta['ftp_host'] ?? ''));
        $ftpRemotePath = trim((string)($audioMeta['ftp_remote_path'] ?? ''));
        if ($ftpHost === '') {
            $skipped++;
            $details[] = ['audio_id' => $audioId, 'result' => 'skipped_no_log_no_ftp_config'];
            continue;
        }
        if ($ftpRemotePath === '') {
            $ftpRemotePath = '/audios/';
        }

        $fallbackFileName = trim((string)($audioMeta['nombre_archivo'] ?? ''));
        if ($fallbackFileName === '') {
            $fallbackFileName = 'audio_' . (int)$audioId . '.mp3';
        }

        $ins->execute([
            (int)$audioId,
            $ftpHost,
            $ftpRemotePath,
            $fallbackFileName,
            'Reintento manual solicitado desde auditoría (sin log previo)',
        ]);
        $requeued++;
        $details[] = ['audio_id' => $audioId, 'result' => 'retry_scheduled_new_log'];
        continue;
    }
    if ($last['status'] === 'success') {
        $skipped++;
        $details[] = ['audio_id' => $audioId, 'result' => 'skipped_success'];
        continue;
    }

    // Reinicio de intento para que ftp-retry-worker lo tome inmediatamente.
    $upd->execute(['Reintento manual solicitado desde auditoría', (int)$last['id']]);
    $requeued++;
    $details[] = ['audio_id' => $audioId, 'result' => 'retry_scheduled'];
}

echo json_encode([
    'success' => true,
    'requeued' => $requeued,
    'skipped' => $skipped,
    'details' => $details
], JSON_UNESCAPED_UNICODE);

