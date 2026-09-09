<?php
/**
 * API Endpoint para enviar audios a servidor FTP (síncrono — uso manual).
 * El flujo automático al finalizar informe usa enqueue-ftp.php + worker.
 */

ignore_user_abort(true);
set_time_limit(300);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/FtpAudioSender.php';

try {
    $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? $_POST['session_token'] ?? null;
    if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
        $sessionToken = substr($sessionToken, 7);
    }

    if (!$sessionToken) {
        throw new Exception('Token de sesión requerido');
    }

    $user = new User();
    $userData = $user->validateSession($sessionToken);

    if (!$userData) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sesión inválida']);
        exit();
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $audioId = $input['audio_id'] ?? null;
    if (!$audioId) {
        throw new Exception('Se requiere audio_id');
    }

    $db = getDBConnection();
    $isAutoSend = !empty($input['auto_send']);
    $result = FtpAudioSender::sendSynchronously($db, (int) $audioId, $userData, $isAutoSend);

    if (!empty($result['success'])) {
        echo json_encode(array_merge(['success' => true], $result));
    } else {
        http_response_code(500);
        echo json_encode(array_merge(['success' => false], $result));
    }
} catch (Exception $e) {
    error_log('Error en send-to-ftp.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
