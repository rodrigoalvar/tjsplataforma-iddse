<?php
/**
 * Envío manual de un informe PDF a Gasalud.
 * POST JSON: { informe_id: N }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/gasalud_envio_helper.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Método no permitido');
    }
    $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
        $sessionToken = substr($sessionToken, 7);
    }
    if (!$sessionToken && !empty($_COOKIE['session_token'])) {
        $sessionToken = $_COOKIE['session_token'];
    }
    if (!$sessionToken) {
        throw new Exception('Token de sesión requerido');
    }
    $user = new User();
    $userData = $user->validateSession($sessionToken);
    if (!$userData) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sesión inválida']);
        exit;
    }
    $permisos = json_decode($userData['permisos'] ?? '[]', true) ?: [];
    $can = in_array('all', $permisos, true)
        || in_array('configuracion', $permisos, true)
        || in_array('gestionInformes', $permisos, true)
        || in_array('enviar_pacs', $permisos, true);
    if (!$can) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Sin permiso']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $informeId = (int)($input['informe_id'] ?? 0);
    if ($informeId <= 0) {
        throw new Exception('informe_id requerido');
    }

    // Forzar activo para envío manual explícito: el helper respeta trigger=manual o cualquier trigger con event=manual
    $db = getDBConnection();
    $cfg = gasalud_load_envio_config($db);
    if (!$cfg['activo']) {
        throw new Exception('Envío Gasalud está apagado (gasalud_envio_activo=0). Actívelo en Configuración.');
    }

    $result = gasalud_try_send_informe($db, $informeId, 'manual');
    $ok = empty($result['skipped']) && !empty($result['success']);
    echo json_encode([
        'success' => $ok || (!empty($result['skipped']) && strpos((string)$result['message'], 'externo') !== false),
        'data' => $result,
        'message' => $result['message'] ?? '',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
