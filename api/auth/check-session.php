<?php
/**
 * Endpoint ligero de polling para verificar si la sesión del usuario sigue activa.
 *
 * GET /api/auth/check-session.php
 *
 * Respuestas:
 *   200 { valid: true,  userId: N }   → sesión vigente
 *   401 { valid: false, reason: "..." } → sesión expirada o no existe
 */
header('Content-Type: application/json; charset=utf-8');
// Permitir solo desde el mismo origen
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

error_reporting(0);
ini_set('display_errors', 0);

$session_token = $_COOKIE['session_token'] ?? '';

if (empty($session_token)) {
    http_response_code(401);
    echo json_encode(['valid' => false, 'reason' => 'no_token']);
    exit();
}

try {
    require_once __DIR__ . '/../../classes/User.php';

    $user    = new User();
    $userData = $user->validateSession($session_token);

    if (!$userData) {
        http_response_code(401);
        echo json_encode(['valid' => false, 'reason' => 'expired_or_invalid']);
        exit();
    }

    http_response_code(200);
    echo json_encode([
        'valid'  => true,
        'userId' => (int) $userData['id'],
        'nivel'  => $userData['nivel'] ?? null
    ]);

} catch (Throwable $e) {
    error_log('check-session.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['valid' => false, 'reason' => 'server_error']);
}
exit();
