<?php
/**
 * api/audios/requeue-transcription.php
 * Reencola audios para transcripción (incluye stuck/failed).
 *
 * POST JSON: { audio_ids: int[] }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../informes/transcription_status_helpers.php';

function getAuthUserForRequeue(PDO $db): ?array
{
    $token = null;
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        if (stripos($authHeader, 'Bearer ') === 0) {
            $token = substr($authHeader, 7);
        }
    }
    if (!$token) {
        return null;
    }
    try {
        $user = new User();
        return $user->validateSession($token) ?: null;
    } catch (Exception $e) {
        error_log('requeue-transcription.php: Error validando sesión: ' . $e->getMessage());
        return null;
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Método no permitido']);
        exit();
    }

    $userData = getAuthUserForRequeue(getDBConnection());
    if (!$userData) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No autorizado']);
        exit();
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $ids = $input['audio_ids'] ?? [];
    if (!is_array($ids) || empty($ids)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'audio_ids requerido']);
        exit();
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (empty($ids)) {
        echo json_encode(['success' => true, 'requeued' => 0, 'skipped' => 0, 'details' => []]);
        exit();
    }

    $db = getDBConnection();
    if (!informesTxTablesExist($db)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Tablas de transcripción no disponibles']);
        exit();
    }

    require_once __DIR__ . '/../transcription_health.php';
    try {
        $txHealth = transcriptionAssertReadyToEnqueue($db);
    } catch (Exception $eHealth) {
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'error' => $eHealth->getMessage(),
            'requeued' => 0,
            'skipped' => count($ids),
            'transcription_health' => [
                'status' => 'down',
                'ready' => false,
                'allow_enqueue' => false,
                'message' => $eHealth->getMessage(),
            ],
        ]);
        exit();
    }

    $currentUserId = (int) ($userData['id'] ?? 0);
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
        $audioId = (int) $r['audio_id'];

        $done = $db->prepare("SELECT id FROM ai_transcriptions WHERE audio_id = ? AND status = 'completed' LIMIT 1");
        $done->execute([$audioId]);
        if ($done->fetch(PDO::FETCH_ASSOC)) {
            $skipped++;
            $details[] = ['audio_id' => $audioId, 'result' => 'skipped_completed'];
            continue;
        }

        $audioRowStmt = $db->prepare("SELECT * FROM audios_informe WHERE id = ? LIMIT 1");
        $audioRowStmt->execute([$audioId]);
        $audioRow = $audioRowStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $txInfo = computeAudioTxStatus($db, $audioRow);

        $check = $db->prepare("SELECT id, status FROM ai_transcription_queue WHERE audio_id = ? ORDER BY id DESC LIMIT 1");
        $check->execute([$audioId]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);

        if ($existing && in_array($existing['status'], ['pending', 'processing'], true) && !$txInfo['tx_is_stuck']) {
            $skipped++;
            $details[] = ['audio_id' => $audioId, 'result' => 'skipped_in_queue'];
            continue;
        }

        if ($txInfo['tx_is_stuck']) {
            $failTx = $db->prepare("
                UPDATE ai_transcriptions
                SET status = 'failed',
                    error_message = 'Reencolado manualmente desde informes-manager',
                    updated_at = NOW()
                WHERE audio_id = ? AND status = 'processing'
            ");
            $failTx->execute([$audioId]);
        }

        $orthancStudyId = $r['informe_estudio_id'] ?? $r['audio_estudio_id'] ?? null;
        $studyId = null;
        if ($orthancStudyId) {
            $s = $db->prepare("SELECT id FROM estudios WHERE orthanc_study_id = ? LIMIT 1");
            $s->execute([$orthancStudyId]);
            $study = $s->fetch(PDO::FETCH_ASSOC);
            if ($study) {
                $studyId = (int) $study['id'];
            }
        }

        if ($existing) {
            $upd = $db->prepare("
                UPDATE ai_transcription_queue
                SET status = 'pending',
                    retry_count = 0,
                    error_message = ?,
                    started_at = NULL,
                    completed_at = NULL,
                    created_by = ?,
                    study_id = COALESCE(?, study_id),
                    orthanc_study_id = COALESCE(?, orthanc_study_id),
                    created_at = NOW()
                WHERE id = ?
            ");
            $upd->execute([
                'Reencolado manual desde informes-manager',
                $currentUserId,
                $studyId,
                $orthancStudyId,
                (int) $existing['id'],
            ]);
            $requeued++;
            $details[] = ['audio_id' => $audioId, 'result' => 'requeued_existing'];
            continue;
        }

        $ins = $db->prepare("
            INSERT INTO ai_transcription_queue
            (audio_id, study_id, orthanc_study_id, status, priority, retry_count, max_retries, created_by, created_at)
            VALUES (?, ?, ?, 'pending', 0, 0, 3, ?, NOW())
        ");
        $ins->execute([$audioId, $studyId, $orthancStudyId, $currentUserId]);
        $requeued++;
        $details[] = ['audio_id' => $audioId, 'result' => 'requeued_new'];
    }

    echo json_encode([
        'success' => true,
        'requeued' => $requeued,
        'skipped' => $skipped,
        'details' => $details,
        'transcription_health' => [
            'status' => $txHealth['status'] ?? null,
            'degraded' => !empty($txHealth['degraded']),
            'message' => $txHealth['message'] ?? null,
        ],
        'warning' => !empty($txHealth['degraded'])
            ? ($txHealth['message'] ?? 'Servidor de transcripción degradado')
            : null,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error interno: ' . $e->getMessage(),
    ]);
}
