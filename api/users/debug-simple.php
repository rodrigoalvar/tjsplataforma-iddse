<?php
/**
 * API Simplificada para Diagnóstico
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

// Configurar headers para JSON
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Función para enviar respuesta JSON
function sendJsonResponse($success, $data = null, $error = null) {
    $response = ['success' => $success];
    
    if ($success && $data !== null) {
        $response['data'] = $data;
    }
    
    if (!$success && $error !== null) {
        $response['error'] = $error;
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    // Obtener método HTTP
    $method = $_SERVER['REQUEST_METHOD'];
    
    // Log del método y datos recibidos
    error_log("API Debug - Método: " . $method);
    error_log("API Debug - GET: " . print_r($_GET, true));
    error_log("API Debug - POST: " . print_r($_POST, true));
    
    // Obtener datos del input
    $input = file_get_contents('php://input');
    error_log("API Debug - Input raw: " . $input);
    
    $inputData = json_decode($input, true);
    error_log("API Debug - Input decoded: " . print_r($inputData, true));
    
    // Manejar diferentes métodos HTTP
    switch ($method) {
        case 'GET':
            sendJsonResponse(true, ['message' => 'API GET funciona correctamente']);
            break;
            
        case 'POST':
            sendJsonResponse(true, ['message' => 'API POST funciona correctamente', 'data' => $inputData]);
            break;
            
        case 'PUT':
            $userId = $_GET['id'] ?? null;
            if (!$userId) {
                sendJsonResponse(false, null, 'ID de usuario requerido');
            }
            
            if (!$inputData) {
                sendJsonResponse(false, null, 'Datos inválidos');
            }
            
            sendJsonResponse(true, [
                'message' => 'API PUT funciona correctamente',
                'user_id' => $userId,
                'data' => $inputData
            ]);
            break;
            
        case 'DELETE':
            $userId = $_GET['id'] ?? null;
            if (!$userId) {
                sendJsonResponse(false, null, 'ID de usuario requerido');
            }
            
            sendJsonResponse(true, ['message' => 'API DELETE funciona correctamente', 'user_id' => $userId]);
            break;
            
        default:
            sendJsonResponse(false, null, 'Método no permitido');
    }
    
} catch (Exception $e) {
    error_log("API Debug - Error: " . $e->getMessage());
    sendJsonResponse(false, null, $e->getMessage());
}
?>


