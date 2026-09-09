<?php
/**
 * Detalle de un registro de auditoría PACS Manager.
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

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'id requerido'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    $log = PacsStudyModifyLog::getById($db, $id);
    if (!$log) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Registro no encontrado'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $details = [];
    $dstmt = $db->prepare('SELECT * FROM pacs_study_modify_log_details WHERE log_id = ? ORDER BY id');
    $dstmt->execute([$id]);
    $details = $dstmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($log['reconcile_summary']) && is_string($log['reconcile_summary'])) {
        $log['reconcile_summary'] = json_decode($log['reconcile_summary'], true);
    }
    if (!empty($log['tags_requested']) && is_string($log['tags_requested'])) {
        $log['tags_requested'] = json_decode($log['tags_requested'], true);
    }
    echo json_encode([
        'success' => true,
        'log' => $log,
        'details' => $details,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
