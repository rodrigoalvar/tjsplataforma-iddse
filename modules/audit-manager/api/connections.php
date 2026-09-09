<?php
/**
 * Conexiones (tabla sesiones): histórico y/o activas, con filtros por usuario y fechas.
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

$activeOnly = isset($_GET['active_only']) && $_GET['active_only'] === '1';
$userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
$userQ = isset($_GET['user_q']) ? trim((string) $_GET['user_q']) : '';
if (strlen($userQ) > 120) {
    $userQ = substr($userQ, 0, 120);
}
$fromIn = isset($_GET['from']) ? trim((string) $_GET['from']) : '';
$toIn = isset($_GET['to']) ? trim((string) $_GET['to']) : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = max(1, min(500, (int) ($_GET['limit'] ?? 100)));
$offset = ($page - 1) * $limit;
$inactiveMinutes = isset($_GET['online_within_minutes']) ? max(1, min(120, (int) $_GET['online_within_minutes'])) : 5;

$useDateFilter = true;
if ($fromIn !== '' || $toIn !== '') {
    if ($fromIn === '' || !preg_match('/^\d{4}-\d{2}-\d{2}/', $fromIn)) {
        $fromIn = date('Y-m-d', strtotime('-365 days'));
    }
    if ($toIn === '' || !preg_match('/^\d{4}-\d{2}-\d{2}/', $toIn)) {
        $toIn = date('Y-m-d');
    }
} elseif ($activeOnly) {
    $useDateFilter = false;
} else {
    $fromIn = date('Y-m-d', strtotime('-30 days'));
    $toIn = date('Y-m-d');
}

$fromDt = $fromIn . ' 00:00:00';
$toDt = $toIn . ' 23:59:59';

$where = [];
$params = [];

if ($useDateFilter) {
    $where[] = 's.fecha_creacion >= ?';
    $where[] = 's.fecha_creacion <= ?';
    $params[] = $fromDt;
    $params[] = $toDt;
}

if ($userId > 0) {
    $where[] = 's.usuario_id = ?';
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

if ($activeOnly) {
    $where[] = 's.activa = 1';
    $where[] = 's.fecha_expiracion > NOW()';
}

$whereSql = count($where) ? implode(' AND ', $where) : '1=1';

$countSql = "SELECT COUNT(*) FROM sesiones s LEFT JOIN usuarios u ON u.id = s.usuario_id WHERE $whereSql";
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$likelySql = "CASE WHEN s.ultima_actividad >= DATE_SUB(NOW(), INTERVAL {$inactiveMinutes} MINUTE) AND s.activa = 1 AND s.fecha_expiracion > NOW() THEN 1 ELSE 0 END";
$validSql = 'CASE WHEN s.activa = 1 AND s.fecha_expiracion > NOW() THEN 1 ELSE 0 END';

$sql = "SELECT s.id AS session_id, s.usuario_id,
        u.nombre, u.apellido, u.email,
        s.fecha_creacion, s.fecha_expiracion, s.ultima_actividad, s.activa,
        s.ip_login, s.user_agent_login, s.fecha_cierre,
        COALESCE(s.active_seconds, 0) AS active_seconds,
        {$validSql} AS session_valid,
        {$likelySql} AS likely_online
        FROM sesiones s
        LEFT JOIN usuarios u ON u.id = s.usuario_id
        WHERE $whereSql
        ORDER BY s.fecha_creacion DESC
        LIMIT " . (int) $limit . " OFFSET " . (int) $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'connections' => $rows,
    'total' => $total,
    'page' => $page,
    'limit' => $limit,
    'filters' => [
        'user_id' => $userId > 0 ? $userId : null,
        'user_q' => $userQ !== '' ? $userQ : null,
        'from' => $useDateFilter ? $fromIn : null,
        'to' => $useDateFilter ? $toIn : null,
        'active_only' => $activeOnly,
        'online_within_minutes' => $inactiveMinutes,
    ],
], JSON_UNESCAPED_UNICODE);
