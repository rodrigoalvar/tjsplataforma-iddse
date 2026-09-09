<?php
/**
 * API Endpoint: Procesar Plantilla de Email con Variables
 * 
 * Procesa una plantilla de email reemplazando las variables con valores reales.
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
require_once __DIR__ . '/../EmailTemplate.php';

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
    
    // Agregar variables del sistema si no están presentes
    if (!isset($variables['app_name'])) {
        $variables['app_name'] = 'TJS Medical - Portal de Estudios';
    }
    if (!isset($variables['current_year'])) {
        $variables['current_year'] = date('Y');
    }
    if (!isset($variables['current_date'])) {
        $variables['current_date'] = date('d/m/Y');
    }
    if (!isset($variables['current_datetime'])) {
        $variables['current_datetime'] = date('d/m/Y H:i:s');
    }
    
    // Log para debugging (solo en desarrollo)
    error_log('Procesando plantilla Email: ' . $input['template']);
    error_log('Variables recibidas: ' . json_encode($variables));
    
    // Cargar plantilla de email
    $emailTemplate = new EmailTemplate();
    
    // Verificar que la plantilla existe
    if (!$emailTemplate->exists($input['template'])) {
        sendJsonResponse(false, null, 'Plantilla no encontrada: ' . $input['template'], 404);
    }
    
    // Cargar y procesar plantilla con variables
    $processedContent = $emailTemplate->load($input['template'], $variables);
    
    // Verificar si quedan variables sin procesar
    if (preg_match('/\{\{[^}]+\}\}/', $processedContent, $matches)) {
        error_log('Advertencia: Quedan variables sin procesar en la plantilla: ' . implode(', ', array_unique($matches)));
    }
    
    sendJsonResponse(true, [
        'template' => $input['template'],
        'content' => $processedContent,
        'variables_used' => array_keys($variables)
    ]);
    
} catch (Exception $e) {
    error_log('Error en api/process-email-template.php: ' . $e->getMessage());
    sendJsonResponse(false, null, 'Error interno del servidor: ' . $e->getMessage(), 500);
}

