<?php
/**
 * Reintento manual de reconciliación desde auditoría PACS Manager.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../OrthancPacsSender.php';
require_once __DIR__ . '/pacs_manager_bootstrap.php';
require_once __DIR__ . '/lib/PacsModifyPostProcessor.php';

$boot = pacsManagerBootstrap();
if ($boot === null) {
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true) ?: [];
$migrationLogId = (int) ($input['migration_log_id'] ?? 0);
$attemptDelete = !empty($input['attempt_delete_original']);
$mode = strtolower(trim((string) ($input['mode'] ?? 'full')));
if (!in_array($mode, ['full', 'metadata_only', 'doc_only'], true)) {
    $mode = 'full';
}

if ($migrationLogId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'migration_log_id es requerido'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        throw new Exception('Sin conexión a base de datos');
    }
    $pacs = new OrthancPacsSender();
    $result = PacsModifyPostProcessor::retryReconcile($db, $pacs, $migrationLogId, $attemptDelete, $mode);
    if (!($result['success'] ?? false)) {
        http_response_code(500);
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
