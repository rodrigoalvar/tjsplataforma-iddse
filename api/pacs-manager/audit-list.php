<?php
/**
 * Lista auditoría de modificaciones PACS Manager.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/pacs_manager_bootstrap.php';
require_once __DIR__ . '/lib/PacsStudyModifyLog.php';

$boot = pacsManagerBootstrap();
if ($boot === null) {
    exit;
}

$limit = min(100, max(1, (int) ($_GET['limit'] ?? 50)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));
$status = $_GET['status'] ?? null;
if ($status === '' || $status === 'all') {
    $status = null;
}

$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = null;
} elseif ($dateFrom === '') {
    $dateFrom = null;
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = null;
} elseif ($dateTo === '') {
    $dateTo = null;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        throw new Exception('Sin conexión a base de datos');
    }
    $data = PacsStudyModifyLog::listRecent($db, $limit, $offset, $status, $dateFrom, $dateTo);
    echo json_encode([
        'success' => true,
        'items' => $data['items'],
        'total' => $data['total'],
        'limit' => $limit,
        'offset' => $offset,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
