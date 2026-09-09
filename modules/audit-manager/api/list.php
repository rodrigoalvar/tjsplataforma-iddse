<?php
/**
 * Lista eventos de auditoría (paginado).
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

$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;

$userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
$userQ = isset($_GET['user_q']) ? trim((string) $_GET['user_q']) : '';
if (strlen($userQ) > 120) {
    $userQ = substr($userQ, 0, 120);
}
$action = isset($_GET['action_key']) ? trim((string) $_GET['action_key']) : '';
$from = isset($_GET['from']) ? trim((string) $_GET['from']) : '';
$to = isset($_GET['to']) ? trim((string) $_GET['to']) : '';

if (!AuditLogger::tableExists($db)) {
    echo json_encode(['success' => true, 'events' => [], 'total' => 0, 'page' => $page, 'limit' => $limit]);
    exit;
}

$where = ['1=1'];
$params = [];

if ($userId > 0) {
    $where[] = 'e.user_id = ?';
    $params[] = $userId;
}
if ($userQ !== '') {
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $userQ) . '%';
    $where[] = '(u.nombre LIKE ? OR u.apellido LIKE ? OR u.email LIKE ? OR CONCAT(TRIM(COALESCE(u.nombre,\'\')), \' \', TRIM(COALESCE(u.apellido,\'\'))) LIKE ?)';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($action !== '') {
    $where[] = 'e.action_key LIKE ?';
    $params[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $action) . '%';
}
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $from)) {
    $where[] = 'e.created_at >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $to)) {
    $where[] = 'e.created_at <= ?';
    $params[] = $to . ' 23:59:59';
}

$whereSql = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM audit_manager_events e LEFT JOIN usuarios u ON u.id = e.user_id WHERE $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$sql = "SELECT e.*, u.nombre, u.apellido, u.email
        FROM audit_manager_events e
        LEFT JOIN usuarios u ON u.id = e.user_id
        WHERE $whereSql
        ORDER BY e.id DESC
        LIMIT " . (int) $limit . " OFFSET " . (int) $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($events as &$ev) {
    if (!empty($ev['metadata'])) {
        $decoded = json_decode($ev['metadata'], true);
        $ev['metadata'] = $decoded !== null ? $decoded : $ev['metadata'];
    }
}
unset($ev);

echo json_encode([
    'success' => true,
    'events' => $events,
    'total' => $total,
    'page' => $page,
    'limit' => $limit,
], JSON_UNESCAPED_UNICODE);
