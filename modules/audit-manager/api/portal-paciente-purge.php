<?php
/**
 * Elimina registros de auditoría del portal paciente (solo auditores).
 * scope=all → TRUNCATE; scope=filtered → DELETE con mismos filtros que el listado.
 */
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

$input = json_decode(file_get_contents('php://input'), true) ?: [];
if (($input['confirm'] ?? '') !== 'VACIAR_AUDITORIA_PORTAL') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Confirmación inválida'], JSON_UNESCAPED_UNICODE);
    exit;
}

$scope = $input['scope'] ?? '';
if (!in_array($scope, ['all', 'filtered'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'scope debe ser all o filtered'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = getDBConnection();

try {
    $st = $db->query("SHOW TABLES LIKE 'audit_portal_paciente_visits'");
    if (!$st || $st->fetch(PDO::FETCH_NUM) === false) {
        http_response_code(503);
        echo json_encode(['success' => false, 'error' => 'Tabla no configurada'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (Exception $e) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Tabla no configurada'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($scope === 'all') {
    try {
        $db->exec('TRUNCATE TABLE `audit_portal_paciente_visits`');
        echo json_encode(['success' => true, 'scope' => 'all', 'deleted' => null], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[portal-paciente-purge] TRUNCATE: ' . $e->getMessage());
        $db->exec('DELETE FROM `audit_portal_paciente_visits`');
        echo json_encode(['success' => true, 'scope' => 'all', 'deleted' => null, 'fallback' => 'delete_all'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$f = isset($input['filters']) && is_array($input['filters']) ? $input['filters'] : [];
$fromIn = isset($f['from']) ? trim((string) $f['from']) : '';
$toIn = isset($f['to']) ? trim((string) $f['to']) : '';
$ipFilter = isset($f['ip']) ? trim((string) $f['ip']) : '';
$queryFilter = isset($f['q']) ? trim((string) $f['q']) : '';
if (strlen($queryFilter) > 128) {
    $queryFilter = substr($queryFilter, 0, 128);
}

if ($fromIn === '' || !preg_match('/^\d{4}-\d{2}-\d{2}/', $fromIn)) {
    $fromIn = date('Y-m-d', strtotime('-30 days'));
}
if ($toIn === '' || !preg_match('/^\d{4}-\d{2}-\d{2}/', $toIn)) {
    $toIn = date('Y-m-d');
}

$fromDt = $fromIn . ' 00:00:00';
$toDt = $toIn . ' 23:59:59';

$where = ['created_at >= ?', 'created_at <= ?'];
$params = [$fromDt, $toDt];

if ($ipFilter !== '') {
    $where[] = 'ip_address LIKE ?';
    $params[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $ipFilter) . '%';
}

$hasPatientQueryCol = false;
try {
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
$sql = "DELETE FROM `audit_portal_paciente_visits` WHERE $whereSql";
$del = $db->prepare($sql);
$del->execute($params);
$n = $del->rowCount();

echo json_encode([
    'success' => true,
    'scope' => 'filtered',
    'deleted' => $n,
], JSON_UNESCAPED_UNICODE);
