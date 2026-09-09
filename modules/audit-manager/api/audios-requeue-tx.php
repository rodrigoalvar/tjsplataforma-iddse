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
$user = auditManagerRequireAuditor();
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

$hasQueue = $db->query("SHOW TABLES LIKE 'ai_transcription_queue'")->rowCount() > 0;
if (!$hasQueue) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No existe ai_transcription_queue'], JSON_UNESCAPED_UNICODE);
    exit;
}
$hasTrans = $db->query("SHOW TABLES LIKE 'ai_transcriptions'")->rowCount() > 0;

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$q = $db->prepare("
    SELECT a.id AS audio_id, a.informe_id, a.estudio_id AS audio_estudio_id, i.estudio_id AS informe_estudio_id
    FROM audios_informe a
    LEFT JOIN informes i ON i.id = a.informe_id
    WHERE a.id IN ($placeholders)
");
$q->execute($ids);
$rows = $q->fetchAll(PDO::FETCH_ASSOC);

$requeued = 0;
$skipped = 0;
$details = [];

foreach ($rows as $r) {
    $audioId = (int)$r['audio_id'];

    if ($hasTrans) {
        $done = $db->prepare("SELECT id FROM ai_transcriptions WHERE audio_id = ? AND status = 'completed' LIMIT 1");
        $done->execute([$audioId]);
        if ($done->fetch(PDO::FETCH_ASSOC)) {
            $skipped++;
            $details[] = ['audio_id' => $audioId, 'result' => 'skipped_completed'];
            continue;
        }
    }

    $check = $db->prepare("SELECT id, status FROM ai_transcription_queue WHERE audio_id = ? ORDER BY id DESC LIMIT 1");
    $check->execute([$audioId]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);

    $orthancStudyId = $r['informe_estudio_id'] ?? $r['audio_estudio_id'] ?? null;
    $studyId = null;
    if ($orthancStudyId) {
        $s = $db->prepare("SELECT id FROM estudios WHERE orthanc_study_id = ? LIMIT 1");
        $s->execute([$orthancStudyId]);
        $study = $s->fetch(PDO::FETCH_ASSOC);
        if ($study) {
            $studyId = (int)$study['id'];
        }
    }

    if ($existing) {
        if (in_array($existing['status'], ['pending', 'processing'], true)) {
            $skipped++;
            $details[] = ['audio_id' => $audioId, 'result' => 'skipped_in_queue'];
            continue;
        }
        $upd = $db->prepare("
            UPDATE ai_transcription_queue
            SET status = 'pending',
                retry_count = 0,
                error_message = ?,
                started_at = NULL,
                completed_at = NULL,
                created_by = ?,
                study_id = COALESCE(?, study_id),
                orthanc_study_id = COALESCE(?, orthanc_study_id)
            WHERE id = ?
        ");
        $upd->execute(['Reencolado manual por auditor', (int)$user['id'], $studyId, $orthancStudyId, (int)$existing['id']]);
        $requeued++;
        $details[] = ['audio_id' => $audioId, 'result' => 'requeued_existing'];
        continue;
    }

    $ins = $db->prepare("
        INSERT INTO ai_transcription_queue
        (audio_id, study_id, orthanc_study_id, status, priority, retry_count, max_retries, created_by, created_at)
        VALUES (?, ?, ?, 'pending', 0, 0, 3, ?, NOW())
    ");
    $ins->execute([$audioId, $studyId, $orthancStudyId, (int)$user['id']]);
    $requeued++;
    $details[] = ['audio_id' => $audioId, 'result' => 'requeued_new'];
}

echo json_encode([
    'success' => true,
    'requeued' => $requeued,
    'skipped' => $skipped,
    'details' => $details
], JSON_UNESCAPED_UNICODE);

