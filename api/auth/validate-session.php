<?php
/**
 * API Endpoint simplificado para validar sesión de usuario
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once '../../classes/User.php';

// Permitir GET y POST
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'])) {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

try {
    // Obtener token de sesión
    $session_token = null;
    
    // Prioridad 1: Cookie
    if (isset($_COOKIE['session_token']) && !empty($_COOKIE['session_token'])) {
        $session_token = $_COOKIE['session_token'];
    }
    // Prioridad 2: Header Authorization
    elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
        if (strpos($auth_header, 'Bearer ') === 0) {
            $session_token = substr($auth_header, 7);
        }
    }
    // Prioridad 3: Header personalizado
    elseif (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            $auth_header = $headers['Authorization'];
            if (strpos($auth_header, 'Bearer ') === 0) {
                $session_token = substr($auth_header, 7);
            }
        }
    }
    
    // Verificar si se encontró token
    if (empty($session_token)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Token de sesión no encontrado']);
        exit;
    }
    
    // Validar sesión
    $user = new User();
    $user_data = $user->validateSession($session_token);
    
    if ($user_data) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Sesión válida',
            'user' => $user_data
        ]);
    } else {
        // Limpiar cookie si existe
        if (isset($_COOKIE['session_token'])) {
            setcookie('session_token', '', time() - 3600, '/', '', false, true);
        }
        
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sesión inválida o expirada']);
    }
    
} catch (Exception $e) {
    error_log('Error en validación de sesión: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Error interno del servidor'
    ]);
}
?>