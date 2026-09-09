<?php
/**
 * Impacto en plataforma al eliminar un estudio de Orthanc (informes, audios, flags).
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/pacs_manager_bootstrap.php';
require_once __DIR__ . '/lib/StudyDeleteImpact.php';

$boot = pacsManagerBootstrap();
if ($boot === null) {
    exit;
}

$studyId = trim((string) ($_GET['study_id'] ?? ''));
$studyInstanceUid = isset($_GET['study_instance_uid']) ? trim((string) $_GET['study_instance_uid']) : null;
if ($studyInstanceUid === '') {
    $studyInstanceUid = null;
}

if ($studyId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'study_id es requerido'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        throw new Exception('Sin conexión a base de datos');
    }

    $analysis = StudyDeleteImpact::analyze($db, $studyId, $studyInstanceUid);

    echo json_encode([
        'success' => true,
        'study_id' => $studyId,
        'study_instance_uid' => $studyInstanceUid,
        'impact' => $analysis['impact'],
        'severity' => $analysis['severity'],
        'messages' => $analysis['messages'],
        'requires_acknowledgement' => $analysis['severity'] === 'high',
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
