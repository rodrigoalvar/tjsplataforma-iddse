<?php
/**
 * Encola audios para envío FTP en segundo plano (cola audios_ftp_log.status=pending).
 * POST JSON: { audio_ids: int[] }
 */

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
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit();
}

require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/FtpAudioSender.php';

try {
    $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
        $sessionToken = substr($sessionToken, 7);
    }
    if (!$sessionToken) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No autorizado']);
        exit();
    }

    $user = new User();
    $userData = $user->validateSession($sessionToken);
    if (!$userData) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Sesión inválida']);
        exit();
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $audioIds = $input['audio_ids'] ?? [];
    if (empty($audioIds) || !is_array($audioIds)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'audio_ids es requerido']);
        exit();
    }

    $db = getDBConnection();
    $result = FtpAudioSender::enqueueAudioIds($db, $audioIds, (int) $userData['id']);

    echo json_encode([
        'success' => true,
        'enqueued' => $result['enqueued'],
        'skipped' => $result['skipped'],
        'errors' => $result['errors'],
        'message' => $result['enqueued'] > 0
            ? $result['enqueued'] . ' audio(s) en cola FTP'
            : 'Ningún audio nuevo en cola FTP',
    ]);
} catch (Exception $e) {
    error_log('enqueue-ftp.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
