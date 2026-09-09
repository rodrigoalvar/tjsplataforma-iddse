<?php
/**
 * Registra un evento de actividad / conectividad del usuario actual.
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
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

$t0 = microtime(true);
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../AuditLogger.php';

$user = auditManagerRequireLogin();
$db = getDBConnection();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = isset($input['action_key']) ? preg_replace('/[^a-zA-Z0-9._\-]/', '', (string) $input['action_key']) : '';
if ($action === '' || strlen($action) > 120) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'action_key inválido']);
    exit;
}

$metadata = $input['metadata'] ?? null;
if ($metadata !== null && !is_array($metadata)) {
    $metadata = ['value' => $metadata];
}

$serverMs = (int) round((microtime(true) - $t0) * 1000);

AuditLogger::log($db, [
    'user_id' => (int) $user['id'],
    'action_key' => $action,
    'resource_type' => isset($input['resource_type']) ? substr((string) $input['resource_type'], 0, 64) : null,
    'resource_id' => isset($input['resource_id']) ? substr((string) $input['resource_id'], 0, 128) : null,
    'description' => isset($input['description']) ? substr((string) $input['description'], 0, 512) : null,
    'metadata' => $metadata,
    'client_duration_ms' => isset($input['client_duration_ms']) ? (int) $input['client_duration_ms'] : null,
    'rtt_ms' => isset($input['rtt_ms']) ? (int) $input['rtt_ms'] : null,
    'server_processing_ms' => $serverMs,
]);

echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
