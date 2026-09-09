<?php
/**
 * Métricas on-demand de replicación por StudyInstanceUID usando /changes de Orthanc local.
 *
 * POST /api/pacs-nodes-manager/replication-metrics.php
 * Body:
 * {
 *   "study_uid": "...",
 *   "since": 0,
 *   "limit": 300
 * }
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../PacsNodeClient.php';

try {
    $user = requirePacsNodesAuth('pacs_nodes_manager');
    $db = getDBConnection();
    if (!$db) {
        sendErrorResponse('No se pudo conectar a la base de datos', 500);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendErrorResponse('Método no permitido', 405);
    }
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        sendErrorResponse('JSON inválido', 400);
    }
    $studyUid = trim((string)($payload['study_uid'] ?? ''));
    if ($studyUid === '') {
        sendErrorResponse('study_uid es requerido', 400);
    }
    $since = max(0, (int)($payload['since'] ?? 0));
    $limit = max(20, min(1000, (int)($payload['limit'] ?? 300)));
    
    $client = new PacsNodeClient($db);
    $changesPack = $client->getOrthancChanges($since, $limit);
    $changes = is_array($changesPack['changes']) ? $changesPack['changes'] : [];
    
    $instanceCache = [];
    $matchedInstances = 0;
    $matchedEventSeqMax = $since;
    
    foreach ($changes as $ch) {
        $seq = (int)($ch['Seq'] ?? 0);
        if ($seq > $matchedEventSeqMax) {
            $matchedEventSeqMax = $seq;
        }
        $type = strtolower((string)($ch['ChangeType'] ?? ''));
        if ($type !== 'newinstance') {
            continue;
        }
        $instanceId = trim((string)($ch['ID'] ?? ''));
        if ($instanceId === '') {
            continue;
        }
        if (!array_key_exists($instanceId, $instanceCache)) {
            $instanceCache[$instanceId] = $client->getStudyUidFromInstanceId($instanceId);
        }
        if ($instanceCache[$instanceId] === $studyUid) {
            $matchedInstances++;
        }
    }
    
    $localInfo = $client->getLocalStudyInfo($studyUid);
    $localFound = is_array($localInfo) && !empty($localInfo['found']);
    
    sendSuccessResponse([
        'study_uid' => $studyUid,
        'changes_since' => $since,
        'changes_last' => (int)($changesPack['last'] ?? $matchedEventSeqMax),
        'changes_done' => (bool)($changesPack['done'] ?? true),
        'changes_count' => count($changes),
        'matched_new_instances' => $matchedInstances,
        'local_found' => $localFound,
        'local_series' => $localFound ? (int)($localInfo['series'] ?? 0) : 0,
        'local_instances' => $localFound ? (int)($localInfo['instances'] ?? 0) : 0,
        'server_now_ms' => (int)round(microtime(true) * 1000),
        'user_id' => (int)($user['id'] ?? 0)
    ]);
} catch (Exception $e) {
    error_log('[REPLICATION_METRICS] ' . $e->getMessage());
    sendErrorResponse($e->getMessage(), 500);
}

