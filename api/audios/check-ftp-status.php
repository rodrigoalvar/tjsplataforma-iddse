<?php
/**
 * API Endpoint para verificar el estado de envío FTP de un audio
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Solo permitir POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../config/database.php';

try {
    // Validar sesión
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
    
    // Obtener input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $audioId = $input['audio_id'] ?? null;
    
    // Conectar a la base de datos
    $db = getDBConnection();
    
    // Verificar configuración FTP del usuario
    // IMPORTANTE: Solo considerar configuraciones donde activo = 1
    // Si activo = 0, la configuración FTP no está activa y no se debe usar
    $ftpConfigQuery = "SELECT ftp_auto_send, activo 
                       FROM usuarios_ftp_config 
                       WHERE usuario_id = ? AND activo = 1 
                       ORDER BY created_at DESC 
                       LIMIT 1";
    $ftpConfigStmt = $db->prepare($ftpConfigQuery);
    $ftpConfigStmt->execute([$userData['id']]);
    $ftpConfig = $ftpConfigStmt->fetch(PDO::FETCH_ASSOC);
    
    // FTP está habilitado solo si:
    // 1. Existe configuración
    // 2. activo = 1 (ya filtrado en SQL, pero verificamos explícitamente)
    // Si existe una configuración activa, FTP está habilitado
    $ftpEnabled = $ftpConfig && 
                  isset($ftpConfig['activo']) && $ftpConfig['activo'] == 1;
    
    // Envío automático solo si FTP está habilitado Y ftp_auto_send = 1
    $ftpAutoSend = $ftpEnabled && 
                   isset($ftpConfig['ftp_auto_send']) && $ftpConfig['ftp_auto_send'] == 1;
    
    // Si no se proporciona audio_id, solo retornar configuración
    if (!$audioId || $audioId == 0) {
        echo json_encode([
            'success' => true,
            'ftp_enabled' => $ftpEnabled,
            'ftp_auto_send' => $ftpAutoSend
        ]);
        return;
    }
    
    // Verificar si el audio ya fue enviado a FTP
    $logQuery = "SELECT status, attempts 
                 FROM audios_ftp_log 
                 WHERE audio_id = ? 
                 ORDER BY created_at DESC 
                 LIMIT 1";
    $logStmt = $db->prepare($logQuery);
    $logStmt->execute([$audioId]);
    $ftpLog = $logStmt->fetch(PDO::FETCH_ASSOC);
    
    $ftpSent = false;
    $ftpStatus = null;
    
    if ($ftpLog) {
        $ftpStatus = $ftpLog['status'];
        // Considerar como enviado si el estado es 'sent' o 'success'
        $ftpSent = in_array($ftpStatus, ['sent', 'success']);
    }
    
    echo json_encode([
        'success' => true,
        'ftp_enabled' => $ftpEnabled,
        'ftp_auto_send' => $ftpAutoSend,
        'ftp_sent' => $ftpSent,
        'ftp_status' => $ftpStatus
    ]);
    
} catch (Exception $e) {
    error_log("Error en check-ftp-status.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
