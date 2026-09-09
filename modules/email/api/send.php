<?php
/**
 * API Endpoint: Enviar Email
 * 
 * Endpoint para enviar emails manualmente mediante API REST.
 * 
 * Método: POST
 * Autenticación: Requerida
 * 
 * @package EmailModule
 * @version 1.0
 */

// Configurar manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Activar output buffering
ob_start();

// Headers JSON
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    ob_end_clean();
    exit();
}

// Cargar helpers
require_once __DIR__ . '/_auth.php';

// Cargar clases del módulo
require_once __DIR__ . '/../EmailConfig.php';
require_once __DIR__ . '/../EmailService.php';

// Validar autenticación
$user = validateEmailApiAuth();
if (!$user) {
    sendJsonResponse(false, null, 'No autenticado o sesión inválida', 401);
}

// Verificar permiso de envío de email
if (!function_exists('checkEmailSendPermission')) {
    require_once __DIR__ . '/_auth.php';
}
if (!checkEmailSendPermission($user)) {
    sendJsonResponse(false, null, 'No tienes permisos para enviar emails. Se requiere el permiso "Envios por email" (envios_email). Contacta al administrador para solicitar este permiso.', 403);
}

// Solo aceptar POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(false, null, 'Método no permitido. Use POST.', 405);
}

try {
    // Obtener datos del JSON
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        // Intentar obtener de POST
        $input = $_POST;
    }
    
    if (!$input) {
        sendJsonResponse(false, null, 'Datos inválidos o vacíos', 400);
    }
    
    // Validar campos requeridos
    if (empty($input['to'])) {
        sendJsonResponse(false, null, 'Campo requerido: to (email destinatario)', 400);
    }
    
    if (empty($input['subject'])) {
        sendJsonResponse(false, null, 'Campo requerido: subject (asunto)', 400);
    }
    
    if (empty($input['body'])) {
        sendJsonResponse(false, null, 'Campo requerido: body (cuerpo del mensaje)', 400);
    }
    
    // Cargar configuración y crear servicio
    // Intentar cargar configuración del usuario primero
    $config = null;
    if (isset($user) && isset($user['id'])) {
        $config = EmailConfig::loadForUser($user['id']);
        if ($config) {
            error_log('🔍 [api/send.php] Usando configuración SMTP del usuario ID: ' . $user['id']);
        }
    }
    // Si no hay configuración de usuario, usar global
    if (!$config) {
        $config = EmailConfig::load();
    }
    $emailService = new EmailService($config);
    
    // Preparar datos para envío
    $emailData = [
        'to' => $input['to'],
        'subject' => $input['subject'],
        'body' => $input['body'],
        'body_type' => $input['body_type'] ?? 'html'
    ];
    
    // Opcionales
    if (!empty($input['cc']) && is_array($input['cc'])) {
        $emailData['cc'] = $input['cc'];
    }
    
    if (!empty($input['bcc']) && is_array($input['bcc'])) {
        $emailData['bcc'] = $input['bcc'];
    }
    
    if (!empty($input['reply_to'])) {
        $emailData['reply_to'] = $input['reply_to'];
    }
    
    if (!empty($input['attachments']) && is_array($input['attachments'])) {
        $emailData['attachments'] = $input['attachments'];
    }
    
    // Enviar email
    $result = $emailService->send($emailData);
    
    if ($result['success']) {
        sendJsonResponse(true, [
            'message' => $result['message'],
            'message_id' => $result['message_id'] ?? null
        ]);
    } else {
        sendJsonResponse(false, null, $result['message'] ?? 'Error al enviar email', 500);
    }
    
} catch (Exception $e) {
    error_log('Error en api/email/send.php: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    sendJsonResponse(false, null, 'Error interno del servidor: ' . $e->getMessage(), 500);
}

