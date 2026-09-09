<?php
/**
 * API para enviar mensajes de WhatsApp usando WAHA (WhatsApp HTTP API)
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
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

// Función para enviar respuesta JSON
function sendJsonResponse($success, $data = null, $error = null) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    
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
    // Cargar clase WAHA API
    require_once __DIR__ . '/../../modules/whatsapp/WAHAAPI.php';
    require_once __DIR__ . '/../../modules/whatsapp/WhatsAppConfig.php';
    
    // Verificar sesión y permisos del usuario
    $has_permission = false;
    $headers = getallheaders();
    $token = null;
    
    if (isset($headers['Authorization'])) {
        $authHeader = $headers['Authorization'];
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $token = $matches[1];
        }
    }
    
    if (!$token && isset($_COOKIE['session_token'])) {
        $token = $_COOKIE['session_token'];
    }
    
    if ($token) {
        try {
            require_once __DIR__ . '/../../classes/User.php';
            $user = new User();
            $user_data = $user->validateSession($token);
            
            if ($user_data && is_array($user_data)) {
                $user_permisos = isset($user_data['permisos']) ? $user_data['permisos'] : [];
                
                if (is_string($user_permisos)) {
                    $user_permisos = json_decode($user_permisos, true) ?: [];
                }
                
                // Requerir permiso 'envios_whatsapp', 'pacientes' o 'all' para enviar mensajes
                $has_permission = in_array('all', $user_permisos) || 
                                 in_array('envios_whatsapp', $user_permisos) ||
                                 in_array('pacientes', $user_permisos);
            }
        } catch (Exception $e) {
            error_log('Error validando sesión en whatsapp/send-message.php: ' . $e->getMessage());
        }
    }
    
    if (!$has_permission) {
        sendJsonResponse(false, null, 'No tienes permisos para enviar mensajes');
    }
    
    // Obtener datos del JSON
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        sendJsonResponse(false, null, 'Datos inválidos');
    }
    
    // Validar campos requeridos
    if (empty($input['telefono'])) {
        sendJsonResponse(false, null, 'Campo requerido: telefono');
    }
    
    if (empty($input['mensaje'])) {
        sendJsonResponse(false, null, 'Campo requerido: mensaje');
    }
    
    // Obtener ID del usuario actual
    $userId = $user_data['id'] ?? null;
    
    // Intentar cargar configuración del usuario primero
    $wahaConfig = null;
    $configSource = 'global';
    
    if ($userId) {
        $wahaConfig = WhatsAppConfig::loadForUser($userId);
        if ($wahaConfig) {
            $configSource = 'usuario';
            error_log("Usando configuración WAHA personal del usuario #$userId");
        }
    }
    
    // Si no tiene configuración personal, usar la global
    if (!$wahaConfig) {
        $whatsappConfig = WhatsAppConfig::load();
        $wahaConfig = $whatsappConfig->getWahaConfig();
        error_log("Usando configuración WAHA global");
    }
    
    // Mapear default_session a session_name para WAHAAPI
    if (isset($wahaConfig['default_session']) && !isset($wahaConfig['session_name'])) {
        $wahaConfig['session_name'] = $wahaConfig['default_session'];
    }
    
    // Verificar que la configuración esté completa
    if (empty($wahaConfig['base_url'])) {
        sendJsonResponse(false, null, 'Configuración de WAHA incompleta: falta base_url');
    }
    
    // Crear instancia de WAHA API
    $wahaAPI = new WAHAAPI($wahaConfig);
    
    // Validar formato del teléfono antes de enviar
    $telefono_limpio = preg_replace('/[^0-9]/', '', $input['telefono']);
    
    if (empty($telefono_limpio)) {
        sendJsonResponse(false, null, 'Número de teléfono inválido');
    }
    
    // Obtener sesión a usar (por defecto desde configuración)
    $sessionName = $input['session'] ?? $wahaConfig['default_session'] ?? 'default';
    
    // Log del número antes de enviar
    error_log('Enviando WhatsApp - Teléfono original: ' . $input['telefono']);
    error_log('Enviando WhatsApp - Teléfono limpio: ' . $telefono_limpio);
    error_log('Enviando WhatsApp - Sesión: ' . $sessionName);
    
    // Formatear número para logging
    $phoneFormatted = $wahaAPI->formatPhoneNumber($input['telefono']);
    error_log('Enviando WhatsApp - Teléfono formateado: ' . $phoneFormatted);
    
    // Enviar mensaje
    // Nota: sendTextMessage($to, $message, $sessionName = null)
    $result = $wahaAPI->sendTextMessage($input['telefono'], $input['mensaje'], $sessionName);
    
    if ($result['success']) {
        sendJsonResponse(true, [
            'message' => 'Mensaje enviado correctamente',
            'result' => $result['data'] ?? $result
        ]);
    } else {
        $errorMsg = 'Error al enviar mensaje';
        $errorData = $result['data'] ?? [];
        
        // Buscar mensaje de error en diferentes estructuras de respuesta de WAHA
        if (isset($errorData['exception']['message'])) {
            $errorMsg = $errorData['exception']['message'];
        } elseif (isset($errorData['error'])) {
            $errorMsg = $errorData['error'];
        } elseif (isset($errorData['message'])) {
            $errorMsg = $errorData['message'];
        } elseif (isset($result['error'])) {
            $errorMsg = $result['error'];
        }
        
        // Detectar errores específicos de WAHA relacionados con números no válidos
        $errorMsgLower = strtolower($errorMsg);
        if (strpos($errorMsgLower, 'no lid for user') !== false ||
            strpos($errorMsgLower, 'not registered') !== false ||
            strpos($errorMsgLower, 'not a whatsapp user') !== false ||
            strpos($errorMsgLower, 'phone number not registered') !== false) {
            $telefono_info = $input['telefono'];
            $errorMsg = 'No se puede enviar el mensaje al número ' . $telefono_info . '. ' .
                       'El número puede no estar registrado en WhatsApp, no estar en tus contactos, ' .
                       'o requerir que el destinatario te haya enviado un mensaje primero. ' .
                       'Verifica que el número sea correcto y que el destinatario tenga WhatsApp activo.';
        }
        
        sendJsonResponse(false, null, $errorMsg);
    }
    
    } catch (Exception $e) {
        error_log('Error en whatsapp/send-message.php: ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        error_log('Telefono recibido: ' . (isset($input['telefono']) ? $input['telefono'] : 'N/A'));
        error_log('Mensaje length: ' . (isset($input['mensaje']) ? strlen($input['mensaje']) : 'N/A'));
        
        // Mensaje de error más descriptivo
        $errorMessage = $e->getMessage();
        
        // Manejar error específico de WAHA "No LID for user"
        if (strpos($errorMessage, 'No LID for user') !== false) {
            $telefono_info = isset($input['telefono']) ? $input['telefono'] : 'N/A';
            $errorMessage = 'No se puede enviar el mensaje al número ' . $telefono_info . '. ' .
                           'El número puede no estar registrado en WhatsApp, no estar en tus contactos, ' .
                           'o requerir que el destinatario te haya enviado un mensaje primero. ' .
                           'Verifica que el número sea correcto y que el destinatario tenga WhatsApp activo.';
        }
    
    if (strpos($errorMessage, '401') !== false || strpos($errorMessage, 'Unauthorized') !== false) {
        $errorMessage = 'Error de autenticación (401). WAHA requiere autenticación. ' .
                       'Por favor, verifique la API key en la configuración de WhatsApp. ' .
                       'Mensaje original: ' . $e->getMessage();
    } elseif (strpos($errorMessage, '400') !== false || strpos($errorMessage, 'Bad Request') !== false) {
        $telefono_info = isset($input['telefono']) ? 'Teléfono recibido: ' . $input['telefono'] : 'No se recibió teléfono';
        
        // Incluir el mensaje completo del error para debugging
        $fullError = $e->getMessage();
        
        // Si el mensaje contiene información adicional, incluirla
        $errorMessage = 'Error de formato (400). ' . 
                       'Verifique que el número tenga el formato correcto (código de país + número, ej: 5491123456789). ' .
                       $telefono_info . '. ' .
                       'Mensaje del servidor: ' . $fullError;
        
        // Log adicional para debugging
        error_log('Bad Request detallado:');
        error_log('Telefono original: ' . (isset($input['telefono']) ? $input['telefono'] : 'N/A'));
        error_log('Mensaje length: ' . (isset($input['mensaje']) ? strlen($input['mensaje']) : 'N/A'));
        error_log('Error completo: ' . $fullError);
        
        // Si el error contiene detalles de la respuesta, agregarlos al mensaje
        if (strlen($fullError) > 100) {
            // El mensaje tiene detalles adicionales, incluirlos
            $errorMessage .= ' | Detalles: ' . substr($fullError, 0, 200);
        }
    }
    
    sendJsonResponse(false, null, $errorMessage);
}

