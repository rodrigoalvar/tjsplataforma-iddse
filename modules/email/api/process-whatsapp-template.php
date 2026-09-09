<?php
/**
 * API Endpoint: Procesar Plantilla de WhatsApp con Variables
 * 
 * Procesa una plantilla de WhatsApp reemplazando las variables con valores reales.
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
require_once __DIR__ . '/../../whatsapp/WhatsAppTemplate.php';

$user = validateEmailApiAuth();
if (!$user) {
    sendJsonResponse(false, null, 'No autenticado o sesión inválida', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(false, null, 'Método no permitido. Use POST.', 405);
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST;
    }
    
    if (empty($input['template'])) {
        sendJsonResponse(false, null, 'Campo requerido: template (nombre de la plantilla)', 400);
    }
    
    // Obtener variables (opcional)
    $variables = $input['variables'] ?? [];
    
    // Log para debugging (solo en desarrollo)
    error_log('Procesando plantilla WhatsApp: ' . $input['template']);
    error_log('Variables recibidas: ' . json_encode($variables));
    
    // Cargar plantilla de WhatsApp
    $whatsappTemplate = new WhatsAppTemplate();
    
    // Verificar que la plantilla existe
    if (!$whatsappTemplate->exists($input['template'])) {
        sendJsonResponse(false, null, 'Plantilla no encontrada: ' . $input['template'], 404);
    }
    
    // Cargar y procesar plantilla con variables
    $processedContent = $whatsappTemplate->load($input['template'], $variables);
    
    // Log del resultado (solo en desarrollo)
    error_log('Contenido procesado (primeros 200 chars): ' . substr($processedContent, 0, 200));
    
    sendJsonResponse(true, [
        'template' => $input['template'],
        'content' => $processedContent,
        'variables_used' => array_keys($variables)
    ]);
    
} catch (Exception $e) {
    error_log('Error en api/process-whatsapp-template.php: ' . $e->getMessage());
    sendJsonResponse(false, null, 'Error interno del servidor: ' . $e->getMessage(), 500);
}

