<?php
/**
 * Lista accesos al portal paciente (solo auditores).
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

$fromIn = isset($_GET['from']) ? trim((string) $_GET['from']) : '';
$toIn = isset($_GET['to']) ? trim((string) $_GET['to']) : '';
$ipFilter = isset($_GET['ip']) ? trim((string) $_GET['ip']) : '';
$queryFilter = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
if (strlen($queryFilter) > 128) {
    $queryFilter = substr($queryFilter, 0, 128);
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = max(1, min(500, (int) ($_GET['limit'] ?? 100)));
$offset = ($page - 1) * $limit;

if ($fromIn === '' || !preg_match('/^\d{4}-\d{2}-\d{2}/', $fromIn)) {
    $fromIn = date('Y-m-d', strtotime('-30 days'));
}
if ($toIn === '' || !preg_match('/^\d{4}-\d{2}-\d{2}/', $toIn)) {
    $toIn = date('Y-m-d');
}

$fromDt = $fromIn . ' 00:00:00';
$toDt = $toIn . ' 23:59:59';

try {
    $st = $db->query("SHOW TABLES LIKE 'audit_portal_paciente_visits'");
    if (!$st || $st->fetch(PDO::FETCH_NUM) === false) {
        echo json_encode([
            'success' => true,
            'visits' => [],
            'total' => 0,
            'page' => $page,
            'limit' => $limit,
            'portal_table_configured' => false,
            'stats' => null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => true,
        'visits' => [],
        'total' => 0,
        'page' => $page,
        'limit' => $limit,
        'portal_table_configured' => false,
        'stats' => null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$where = ['created_at >= ?', 'created_at <= ?'];
$params = [$fromDt, $toDt];

if ($ipFilter !== '') {
    $where[] = 'ip_address LIKE ?';
    $params[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $ipFilter) . '%';
}

$hasPatientQueryCol = false;
try {
    // No usar rowCount() con SHOW COLUMNS en PDO+MySQL: suele devolver 0 aunque exista la columna.
    $cst = $db->query("SHOW COLUMNS FROM `audit_portal_paciente_visits` LIKE 'patient_query'");
    if ($cst) {
        $hasPatientQueryCol = $cst->fetch(PDO::FETCH_ASSOC) !== false;
    }
} catch (Exception $e) {
    $hasPatientQueryCol = false;
}

if ($queryFilter !== '' && $hasPatientQueryCol) {
    $where[] = 'patient_query LIKE ?';
    $params[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $queryFilter) . '%';
}

$whereSql = implode(' AND ', $where);

if ($hasPatientQueryCol) {
    $statsSql = "SELECT COUNT(*) AS total,
        COALESCE(SUM(CASE WHEN COALESCE(TRIM(patient_query), '') = '' THEN 1 ELSE 0 END), 0) AS page_access_only,
        COALESCE(SUM(CASE WHEN COALESCE(TRIM(patient_query), '') <> '' THEN 1 ELSE 0 END), 0) AS with_search_text
        FROM audit_portal_paciente_visits WHERE $whereSql";
} else {
    $statsSql = "SELECT COUNT(*) AS total,
        COUNT(*) AS page_access_only,
        0 AS with_search_text
        FROM audit_portal_paciente_visits WHERE $whereSql";
}
$statsStmt = $db->prepare($statsSql);
$statsStmt->execute($params);
$statsRow = $statsStmt->fetch(PDO::FETCH_ASSOC);
$total = (int) ($statsRow['total'] ?? 0);
$stats = [
    'period_total' => $total,
    'page_access_only' => (int) ($statsRow['page_access_only'] ?? 0),
    'with_search_text' => (int) ($statsRow['with_search_text'] ?? 0),
];

$selectCols = 'id, ip_address, user_agent, page_url, referer, created_at';
if ($hasPatientQueryCol) {
    $selectCols .= ', patient_query';
}
$sql = "SELECT {$selectCols}
        FROM audit_portal_paciente_visits
        WHERE $whereSql
        ORDER BY id DESC
        LIMIT " . (int) $limit . " OFFSET " . (int) $offset;
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'visits' => $rows,
    'total' => $total,
    'page' => $page,
    'limit' => $limit,
    'portal_table_configured' => true,
    'stats' => $stats,
], JSON_UNESCAPED_UNICODE);
