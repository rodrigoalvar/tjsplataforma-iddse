<?php
/**
 * GET status del módulo HL7 Worklist (admin/root).
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../classes/User.php';
require_once __DIR__ . '/../php/bootstrap.php';
require_once __DIR__ . '/../../../utils/WorklistIngestionService.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Método no permitido']);
        exit;
    }

    $token = null;
    if (!empty($_SERVER['HTTP_AUTHORIZATION']) && stripos($_SERVER['HTTP_AUTHORIZATION'], 'Bearer ') === 0) {
        $token = substr($_SERVER['HTTP_AUTHORIZATION'], 7);
    }
    $token = $token ?: ($_COOKIE['session_token'] ?? null);
    if (!$token) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'No autorizado']);
        exit;
    }

    $user = new User();
    $userData = $user->validateSession($token);
    if (!$userData || !in_array(strtolower($userData['nivel'] ?? ''), ['root', 'admin'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Sin permisos']);
        exit;
    }

    $db = getDBConnection();
    Hl7WorklistModule::ensureConfigColumns($db);
    (new WorklistIngestionService($db))->ensureSchema();
    Hl7WorklistModule::ensureIngestSourceType($db);

    $cfg = Hl7WorklistModule::readConfigFromDb($db);
    $ingests24h = 0;
    try {
        $q = $db->query("
            SELECT COUNT(*) FROM worklist_ingests
            WHERE source_type = 'HL7_MLLP'
              AND created_at >= (NOW() - INTERVAL 24 HOUR)
        ");
        $ingests24h = (int)$q->fetchColumn();
    } catch (Exception $e) {
        $ingests24h = 0;
    }

    $hbPath = Hl7WorklistModule::heartbeatPath();
    $hb = null;
    if (is_file($hbPath)) {
        $hb = json_decode((string)file_get_contents($hbPath), true);
    }

    echo json_encode([
        'success' => true,
        'module_version' => Hl7WorklistModule::MODULE_VERSION,
        'hl7_enabled' => (int)($cfg['hl7_enabled'] ?? 0),
        'hl7_port' => (int)($cfg['hl7_port'] ?? 2575),
        'hl7_bind_host' => (string)($cfg['hl7_bind_host'] ?? '0.0.0.0'),
        'hl7_prestador_field' => (string)($cfg['hl7_prestador_field'] ?? 'PV1-8'),
        'hl7_input_path' => (string)($cfg['hl7_input_path'] ?? ''),
        'listener_alive' => Hl7WorklistModule::isListenerAlive(),
        'heartbeat' => $hb,
        'config_snapshot' => is_file(Hl7WorklistModule::runtimeConfigPath()),
        'ingests_24h' => $ingests24h,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
