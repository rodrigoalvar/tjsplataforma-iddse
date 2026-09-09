<?php
/**
 * API Endpoint: Enviar Email con Plantilla
 * 
 * Endpoint para enviar emails usando plantillas HTML predefinidas.
 * 
 * Método: POST
 * Autenticación: Requerida
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
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    ob_end_clean();
    exit();
}

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../EmailConfig.php';
require_once __DIR__ . '/../EmailService.php';
require_once __DIR__ . '/../EmailTemplate.php';

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(false, null, 'Método no permitido. Use POST.', 405);
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST;
    }
    
    if (!$input) {
        sendJsonResponse(false, null, 'Datos inválidos o vacíos', 400);
    }
    
    // Validar campos requeridos
    if (empty($input['template'])) {
        sendJsonResponse(false, null, 'Campo requerido: template (nombre de la plantilla)', 400);
    }
    
    if (empty($input['to'])) {
        sendJsonResponse(false, null, 'Campo requerido: to (email destinatario)', 400);
    }
    
    // Cargar servicios
    // Intentar cargar configuración del usuario primero
    $config = null;
    if (isset($user) && isset($user['id'])) {
        $config = EmailConfig::loadForUser($user['id']);
        if ($config) {
            error_log('🔍 [api/send-template.php] Usando configuración SMTP del usuario ID: ' . $user['id']);
        }
    }
    // Si no hay configuración de usuario, usar global
    if (!$config) {
        $config = EmailConfig::load();
    }
    $emailService = new EmailService($config);
    $template = new EmailTemplate();
    
    // Verificar que la plantilla existe
    if (!$template->exists($input['template'])) {
        sendJsonResponse(false, null, 'Plantilla no encontrada: ' . $input['template'], 404);
    }
    
    // Obtener variables (opcional)
    $variables = $input['variables'] ?? [];
    
    // Obtener asunto (opcional, puede contener variables)
    $subject = $input['subject'] ?? 'Notificación - {{app_name}}';
    
    // Opciones adicionales
    $options = [];
    if (!empty($input['cc']) && is_array($input['cc'])) {
        $options['cc'] = $input['cc'];
    }
    if (!empty($input['bcc']) && is_array($input['bcc'])) {
        $options['bcc'] = $input['bcc'];
    }
    if (!empty($input['attachments']) && is_array($input['attachments'])) {
        $options['attachments'] = $input['attachments'];
    }
    
    // Enviar email con plantilla
    $result = $emailService->sendTemplate(
        $input['template'],
        $input['to'],
        $subject,
        $variables,
        $options
    );
    
    if ($result['success']) {
        sendJsonResponse(true, [
            'message' => $result['message'],
            'message_id' => $result['message_id'] ?? null,
            'template' => $input['template']
        ]);
    } else {
        sendJsonResponse(false, null, $result['message'] ?? 'Error al enviar email', 500);
    }
    
} catch (Exception $e) {
    error_log('Error en api/email/send-template.php: ' . $e->getMessage());
    sendJsonResponse(false, null, 'Error interno del servidor: ' . $e->getMessage(), 500);
}

