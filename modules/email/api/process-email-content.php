<?php
/**
 * API Endpoint: Procesar Contenido HTML de Email con Variables
 * 
 * Procesa contenido HTML de email reemplazando las variables con valores reales.
 * Similar a process-email-template.php pero acepta contenido HTML directamente.
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

/**
 * Procesar bloques condicionales {{#if variable}}...{{/if}}
 */
function processConditionalBlocks($content, $variables) {
    $pattern = '/\{\{#if\s+([^}\s]+)\s*\}\}(.*?)\{\{\/if\}\}/s';
    
    $maxIterations = 10;
    $iteration = 0;
    
    while (preg_match($pattern, $content) && $iteration < $maxIterations) {
        $content = preg_replace_callback($pattern, function($matches) use ($variables) {
            $variableName = trim($matches[1]);
            $blockContent = $matches[2];
            
            $hasValue = false;
            if (isset($variables[$variableName])) {
                $value = $variables[$variableName];
                $hasValue = !empty($value) && $value !== 'N/A' && $value !== '' && $value !== null;
            }
            
            return $hasValue ? $blockContent : '';
        }, $content);
        $iteration++;
    }
    
    return $content;
}

/**
 * Procesar variables con valores por defecto {{variable|default}}
 */
function processDefaultValues($content, $variables) {
    $pattern = '/\{\{\s*([^|\s]+)\s*\|\s*([^}]+)\s*\}\}/';
    
    return preg_replace_callback($pattern, function($matches) use ($variables) {
        $variableName = trim($matches[1]);
        $defaultValue = trim($matches[2]);
        
        if (isset($variables[$variableName])) {
            $value = $variables[$variableName];
            if (!empty($value) && $value !== 'N/A' && $value !== null) {
                return (string)$value;
            }
        }
        
        return $defaultValue;
    }, $content);
}

/**
 * Procesar contenido reemplazando variables
 */
function processEmailContent($content, $variables) {
    // Primero procesar bloques condicionales
    $content = processConditionalBlocks($content, $variables);
    
    // Procesar variables con valores por defecto
    $content = processDefaultValues($content, $variables);
    
    // Reemplazar variables simples
    foreach ($variables as $key => $value) {
        $value = (string)$value;
        $pattern = '/\{\{\s*' . preg_quote($key, '/') . '\s*(?![|#])\}\}/';
        $content = preg_replace($pattern, $value, $content);
    }
    
    // Limpiar variables sin reemplazar
    $content = preg_replace('/\{\{[^|}#\/]+\}\}/', '', $content);
    
    return $content;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST;
    }
    
    if (empty($input['content'])) {
        sendJsonResponse(false, null, 'Campo requerido: content (contenido HTML)', 400);
    }
    
    $content = $input['content'];
    
    // Obtener variables (opcional)
    $variables = $input['variables'] ?? [];
    
    // Obtener variables por defecto del sistema
    $emailTemplate = new EmailTemplate();
    
    // Intentar obtener app_name de la configuración
    $appName = 'TJS Medical - Portal de Estudios';
    try {
        require_once __DIR__ . '/../EmailConfig.php';
        $config = EmailConfig::load();
        $appName = $config->get('app_name', null);
        if ($appName === null) {
            $smtpConfig = $config->getSmtpConfig();
            $appName = $smtpConfig['from_name'] ?? 'TJS Medical - Portal de Estudios';
        }
    } catch (Exception $e) {
        // Usar valor por defecto
    }
    
    // Obtener URL base
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $path = dirname(dirname(dirname($script)));
    $appUrl = $protocol . $host . $path;
    
    // Variables por defecto del sistema
    $defaultVariables = [
        'app_name' => $appName,
        'app_url' => $appUrl,
        'current_year' => date('Y'),
        'current_date' => date('d/m/Y'),
        'current_datetime' => date('d/m/Y H:i:s')
    ];
    
    // Fusionar variables: primero las del sistema, luego las proporcionadas
    $allVariables = array_merge($defaultVariables, $variables);
    
    // Procesar el contenido
    $processedContent = processEmailContent($content, $allVariables);
    
    // Verificar si quedan variables sin procesar
    if (preg_match('/\{\{[^}]+\}\}/', $processedContent, $matches)) {
        error_log('Advertencia: Quedan variables sin procesar en el contenido: ' . implode(', ', array_unique($matches)));
    }
    
    sendJsonResponse(true, [
        'content' => $processedContent,
        'variables_used' => array_keys($allVariables)
    ]);
    
} catch (Exception $e) {
    error_log('Error en api/process-email-content.php: ' . $e->getMessage());
    sendJsonResponse(false, null, 'Error interno del servidor: ' . $e->getMessage(), 500);
}

