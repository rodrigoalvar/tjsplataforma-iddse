<?php
/**
 * Estadísticas agregadas (RTT, duraciones por acción).
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../AuditLogger.php';
auditManagerRequireAuditor();

$db = getDBConnection();

if (!AuditLogger::tableExists($db)) {
    echo json_encode(['success' => true, 'by_action' => [], 'connectivity' => null]);
    exit;
}

$days = max(1, min(90, (int) ($_GET['days'] ?? 7)));
$fromDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));

$stmt = $db->prepare(
    "SELECT action_key,
            COUNT(*) AS cnt,
            AVG(rtt_ms) AS avg_rtt_ms,
            AVG(client_duration_ms) AS avg_client_ms,
            AVG(server_processing_ms) AS avg_server_ms
     FROM audit_manager_events
     WHERE created_at >= ?
     GROUP BY action_key
     ORDER BY cnt DESC
     LIMIT 50"
);
$stmt->execute([$fromDate]);
$byAction = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pingStmt = $db->prepare(
    "SELECT COUNT(*) AS n, AVG(rtt_ms) AS avg_rtt_ms, MIN(rtt_ms) AS min_rtt, MAX(rtt_ms) AS max_rtt
     FROM audit_manager_events
     WHERE action_key = 'connectivity.ping' AND created_at >= ?"
);
$pingStmt->execute([$fromDate]);
$connectivity = $pingStmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'period_days' => $days,
    'by_action' => $byAction,
    'connectivity_ping' => $connectivity,
], JSON_UNESCAPED_UNICODE);
