<?php
/**
 * API Endpoint: Listar Plantillas Disponibles
 * 
 * Endpoint para obtener lista de plantillas HTML disponibles.
 * 
 * Método: GET
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
header('Access-Control-Allow-Methods: GET, OPTIONS');
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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJsonResponse(false, null, 'Método no permitido. Use GET.', 405);
}

try {
    $template = new EmailTemplate();
    $templates = $template->listTemplates();
    
    sendJsonResponse(true, [
        'templates' => $templates,
        'count' => count($templates),
        'default_variables' => $template->getDefaultVariables()
    ]);
    
} catch (Exception $e) {
    error_log('Error en api/email/list-templates.php: ' . $e->getMessage());
    sendJsonResponse(false, null, 'Error interno del servidor: ' . $e->getMessage(), 500);
}

