<?php
/**
 * API de persistencia y consulta de sesiones de métricas de replicación.
 *
 * POST /api/pacs-nodes-manager/replication-sessions.php
 * Body: resumen de sesión finalizada
 *
 * GET /api/pacs-nodes-manager/replication-sessions.php?mode=insights&days=7&limit=5
 * Retorna sesiones recientes y ranking de latencia por nodo.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../../config/database.php';

function epochMsToSqlDateTime($ms) {
    if ($ms === null || $ms === '' || !is_numeric($ms)) {
        return null;
    }
    $sec = (int)floor(((float)$ms) / 1000);
    if ($sec <= 0) return null;
    return date('Y-m-d H:i:s', $sec);
}

try {
    $user = requirePacsNodesAuth('pacs_nodes_manager');
    $db = getDBConnection();
    if (!$db) {
        sendErrorResponse('No se pudo conectar a la base de datos', 500);
    }
    
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            sendErrorResponse('JSON inválido', 400);
        }
        $uid = trim((string)($data['study_uid'] ?? ''));
        if ($uid === '') {
            sendErrorResponse('study_uid es requerido', 400);
        }
        $startedAt = epochMsToSqlDateTime($data['started_at_ms'] ?? null);
        $endedAt = epochMsToSqlDateTime($data['ended_at_ms'] ?? null);
        $durationSec = max(0, (int)($data['duration_sec'] ?? 0));
        $status = trim((string)($data['status'] ?? 'completed'));
        if ($status === '') $status = 'completed';
        $localFirstSeenAt = epochMsToSqlDateTime($data['local_first_seen_at_ms'] ?? null);
        $localLastInstances = max(0, (int)($data['local_last_instances'] ?? 0));
        $avgSpeed = max(0, (float)($data['avg_speed_inst_sec'] ?? 0));
        $peakSpeed = max(0, (float)($data['peak_speed_inst_sec'] ?? 0));
        $ticks = max(0, (int)($data['ticks_count'] ?? 0));
        $notes = trim((string)($data['notes'] ?? ''));
        $notes = $notes === '' ? null : mb_substr($notes, 0, 500);
        $meta = $data['meta'] ?? null;
        $metaJson = $meta !== null ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;
        
        $ins = $db->prepare("
            INSERT INTO pacs_replication_sessions
            (user_id, study_instance_uid, started_at, ended_at, duration_sec, status, local_first_seen_at, local_last_instances, avg_speed_inst_sec, peak_speed_inst_sec, ticks_count, notes, meta_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            (int)($user['id'] ?? 0),
            $uid,
            $startedAt,
            $endedAt,
            $durationSec,
            $status,
            $localFirstSeenAt,
            $localLastInstances,
            $avgSpeed,
            $peakSpeed,
            $ticks,
            $notes,
            $metaJson
        ]);
        $sessionId = (int)$db->lastInsertId();
        
        $nodes = $data['nodes'] ?? [];
        if (is_array($nodes) && !empty($nodes)) {
            $insNode = $db->prepare("
                INSERT INTO pacs_replication_session_nodes
                (session_id, node_id, node_name, remote_first_seen_at, lag_seconds, final_instances, had_error)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($nodes as $n) {
                if (!is_array($n)) continue;
                $insNode->execute([
                    $sessionId,
                    isset($n['node_id']) && $n['node_id'] !== '' ? (int)$n['node_id'] : null,
                    isset($n['node_name']) ? mb_substr((string)$n['node_name'], 0, 255) : null,
                    epochMsToSqlDateTime($n['remote_first_seen_at_ms'] ?? null),
                    isset($n['lag_seconds']) && $n['lag_seconds'] !== null ? (float)$n['lag_seconds'] : null,
                    max(0, (int)($n['final_instances'] ?? 0)),
                    !empty($n['had_error']) ? 1 : 0
                ]);
            }
        }
        
        sendSuccessResponse([
            'session_id' => $sessionId
        ], 'Sesión de replicación guardada');
    }
    
    // GET
    $mode = $_GET['mode'] ?? 'insights';
    $days = max(1, min(30, (int)($_GET['days'] ?? 7)));
    $limit = max(1, min(20, (int)($_GET['limit'] ?? 5)));
    
    if ($mode !== 'insights') {
        sendErrorResponse('mode no soportado', 400);
    }
    
    $recentStmt = $db->prepare("
        SELECT id, study_instance_uid, started_at, ended_at, duration_sec, status,
               local_last_instances, avg_speed_inst_sec, peak_speed_inst_sec, ticks_count, notes, created_at
        FROM pacs_replication_sessions
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ORDER BY created_at DESC
        LIMIT ?
    ");
    $recentStmt->execute([$days, $limit]);
    $recent = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $rankStmt = $db->prepare("
        SELECT
            COALESCE(rn.node_name, CONCAT('Nodo #', rn.node_id)) AS node_name,
            rn.node_id,
            COUNT(*) AS samples,
            ROUND(AVG(rn.lag_seconds), 2) AS avg_lag_seconds,
            ROUND(MIN(rn.lag_seconds), 2) AS min_lag_seconds,
            ROUND(MAX(rn.lag_seconds), 2) AS max_lag_seconds
        FROM pacs_replication_session_nodes rn
        INNER JOIN pacs_replication_sessions rs ON rs.id = rn.session_id
        WHERE rs.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
          AND rn.lag_seconds IS NOT NULL
        GROUP BY rn.node_id, rn.node_name
        HAVING samples > 0
        ORDER BY avg_lag_seconds DESC
        LIMIT 20
    ");
    $rankStmt->execute([$days]);
    $ranking = $rankStmt->fetchAll(PDO::FETCH_ASSOC);
    
    sendSuccessResponse([
        'days' => $days,
        'recent_sessions' => $recent,
        'latency_ranking' => $ranking
    ]);
} catch (Exception $e) {
    error_log('[REPLICATION_SESSIONS] ' . $e->getMessage());
    sendErrorResponse($e->getMessage(), 500);
}

