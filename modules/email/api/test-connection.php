<?php
/**
 * API Endpoint: Probar Conexión SMTP
 * 
 * Endpoint para probar la configuración SMTP sin enviar emails.
 * 
 * Método: GET
 * Autenticación: Requerida (solo administradores)
 * 
 * @package EmailModule
 * @version 1.0
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    ob_end_clean();
    exit();
}

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../EmailConfig.php';
require_once __DIR__ . '/../EmailService.php';

// Requerir permisos de administrador
$user = validateEmailApiAuth(true);
if (!$user) {
    sendJsonResponse(false, null, 'No autenticado o sin permisos de administrador', 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJsonResponse(false, null, 'Método no permitido. Use GET.', 405);
}

try {
    // Cargar configuración
    // Intentar cargar configuración del usuario primero
    $config = null;
    if (isset($user) && isset($user['id'])) {
        $config = EmailConfig::loadForUser($user['id']);
        if ($config) {
            error_log('🔍 [api/test-connection.php] Usando configuración SMTP del usuario ID: ' . $user['id']);
        }
    }
    // Si no hay configuración de usuario, usar global
    if (!$config) {
        $config = EmailConfig::load();
    }
    
    // Validar configuración
    $validation = $config->validate();
    if (!$validation['valid']) {
        sendJsonResponse(false, [
            'errors' => $validation['errors'],
            'config_file' => $config->get('options.log_file', 'N/A')
        ], 'Configuración inválida: ' . implode(', ', $validation['errors']), 400);
    }
    
    // Crear servicio y probar conexión
    $emailService = new EmailService($config);
    $result = $emailService->testConnection();
    
    if ($result['success']) {
        sendJsonResponse(true, [
            'message' => $result['message'],
            'server_info' => $result['server_info'] ?? null
        ]);
    } else {
        sendJsonResponse(false, null, $result['message'] ?? 'Error al probar conexión', 500);
    }
    
} catch (Exception $e) {
    error_log('Error en api/email/test-connection.php: ' . $e->getMessage());
    sendJsonResponse(false, null, 'Error interno del servidor: ' . $e->getMessage(), 500);
}

