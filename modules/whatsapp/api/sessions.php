<?php
/**
 * API para gestión de sesiones de WhatsApp
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    ob_end_clean();
    exit();
}

function sendJsonResponse($success, $data = null, $error = null, $httpCode = 200) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    http_response_code($httpCode);
    
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
    require_once __DIR__ . '/../WhatsAppConfig.php';
    require_once __DIR__ . '/../WAHAAPI.php';
    
    // Verificar autenticación (usar mismo sistema que email)
    require_once __DIR__ . '/../../email/api/_auth.php';
    
    $user = validateEmailApiAuth();
    if (!$user || !isset($user['id'])) {
        sendJsonResponse(false, null, 'No autenticado o sesión inválida', 401);
    }
    
    // Verificar permisos (requerir permiso de administración)
    $user_permisos = isset($user['permisos']) ? $user['permisos'] : [];
    if (is_string($user_permisos)) {
        $user_permisos = json_decode($user_permisos, true) ?: [];
    }
    
    $has_permission = in_array('all', $user_permisos) || 
                     in_array('administracion_email', $user_permisos) ||
                     (isset($user['nivel']) && $user['nivel'] === 'root');
    
    if (!$has_permission) {
        sendJsonResponse(false, null, 'No tienes permisos para gestionar sesiones de WhatsApp', 403);
    }
    
    // Cargar configuración
    $config = WhatsAppConfig::load();
    $wahaConfig = $config->getWahaConfig();
    
    // Mapear default_session a session_name para WAHAAPI
    if (isset($wahaConfig['default_session']) && !isset($wahaConfig['session_name'])) {
        $wahaConfig['session_name'] = $wahaConfig['default_session'];
    }
    
    // Crear instancia de WAHA API
    $wahaAPI = new WAHAAPI($wahaConfig);
    
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method === 'GET') {
        // Obtener sesiones o estado de una sesión específica
        $sessionName = $_GET['session'] ?? null;
        
        if ($sessionName) {
            // Obtener estado de una sesión específica
            try {
                $status = $wahaAPI->getSessionStatus($sessionName);
                sendJsonResponse(true, [
                    'session' => $sessionName,
                    'status' => $status
                ]);
            } catch (Exception $e) {
                sendJsonResponse(false, null, 'Error obteniendo estado de sesión: ' . $e->getMessage(), 500);
            }
        } else {
            // Obtener todas las sesiones
            try {
                $sessions = $wahaAPI->listSessions();
                
                // Normalizar respuesta: WAHA puede retornar diferentes formatos
                // Puede ser un array directo o un objeto con 'sessions' o 'data'
                if (isset($sessions['sessions'])) {
                    $sessions = $sessions['sessions'];
                } elseif (isset($sessions['data'])) {
                    $sessions = $sessions['data'];
                } elseif (!is_array($sessions)) {
                    $sessions = [];
                }
                
                sendJsonResponse(true, ['sessions' => $sessions]);
            } catch (Exception $e) {
                error_log('Error en listSessions: ' . $e->getMessage());
                error_log('Stack trace: ' . $e->getTraceAsString());
                sendJsonResponse(false, null, 'Error obteniendo sesiones: ' . $e->getMessage(), 500);
            }
        }
        
    } elseif ($method === 'POST') {
        // Crear nueva sesión
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input || empty($input['name'])) {
            sendJsonResponse(false, null, 'Campo requerido: name', 400);
        }
        
        $sessionName = trim($input['name']);
        
        try {
            $result = $wahaAPI->createSession($sessionName);
            sendJsonResponse(true, [
                'session' => $sessionName,
                'result' => $result
            ]);
        } catch (Exception $e) {
            sendJsonResponse(false, null, 'Error creando sesión: ' . $e->getMessage(), 500);
        }
        
    } elseif ($method === 'DELETE') {
        // Eliminar sesión
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input || empty($input['name'])) {
            sendJsonResponse(false, null, 'Campo requerido: name', 400);
        }
        
        $sessionName = trim($input['name']);
        
        try {
            $result = $wahaAPI->deleteSession($sessionName);
            sendJsonResponse(true, [
                'session' => $sessionName,
                'result' => $result
            ]);
        } catch (Exception $e) {
            sendJsonResponse(false, null, 'Error eliminando sesión: ' . $e->getMessage(), 500);
        }
        
    } else {
        sendJsonResponse(false, null, 'Método no permitido', 405);
    }
    
} catch (Exception $e) {
    error_log('Error en whatsapp/api/sessions.php: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    sendJsonResponse(false, null, 'Error interno: ' . $e->getMessage(), 500);
} catch (Error $e) {
    error_log('Fatal Error en whatsapp/api/sessions.php: ' . $e->getMessage());
    sendJsonResponse(false, null, 'Error fatal: ' . $e->getMessage(), 500);
}

