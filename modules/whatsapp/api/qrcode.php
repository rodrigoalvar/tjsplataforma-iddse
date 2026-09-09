<?php
/**
 * API para obtener QR code de sesión de WhatsApp
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Limpiar cualquier output previo
while (ob_get_level()) {
    ob_end_clean();
}
ob_start();

// Headers deben enviarse antes de cualquier output
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    while (ob_get_level()) {
        ob_end_clean();
    }
    exit();
}

function sendJsonResponse($success, $data = null, $error = null, $httpCode = 200) {
    // Limpiar todo el output buffer (pero no usar ob_end_clean que elimina el contenido)
    // En su lugar, usar ob_end_flush para enviar cualquier contenido previo
    while (ob_get_level() > 1) {
        ob_end_flush();
    }
    // Limpiar el último buffer si existe
    if (ob_get_level() > 0) {
        ob_clean();
    }
    
    // Asegurar que los headers se envíen
    if (!headers_sent()) {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
    }
    
    $response = ['success' => $success];
    
    if ($success && $data !== null) {
        $response['data'] = $data;
    }
    
    if (!$success && $error !== null) {
        $response['error'] = $error;
    }
    
    $json = json_encode($response, JSON_UNESCAPED_UNICODE);
    
    // Verificar que el JSON se generó correctamente
    if ($json === false) {
        error_log('qrcode.php - ERROR: No se pudo generar JSON. Error: ' . json_last_error_msg());
        // Intentar enviar un error en JSON
        $json = json_encode(['success' => false, 'error' => 'Error generando respuesta JSON: ' . json_last_error_msg()], JSON_UNESCAPED_UNICODE);
    }
    
    // Log para debugging (limitar tamaño del log)
    $jsonSize = strlen($json);
    error_log('qrcode.php - Enviando respuesta JSON (tamaño: ' . $jsonSize . ' bytes)');
    error_log('qrcode.php - Headers sent: ' . (headers_sent() ? 'SÍ' : 'NO'));
    
    if ($jsonSize > 1000000) {
        error_log('qrcode.php - ADVERTENCIA: JSON muy grande (' . $jsonSize . ' bytes), puede causar problemas');
    }
    
    // Asegurar que se envíe el output
    // Limpiar cualquier output buffer antes de enviar
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    
    echo $json;
    
    // Forzar el flush del output
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        flush();
    }
    
    exit();
}

try {
    // Log inicial para verificar que el archivo se está ejecutando
    error_log('qrcode.php - Inicio de ejecución');
    error_log('qrcode.php - REQUEST_METHOD: ' . ($_SERVER['REQUEST_METHOD'] ?? 'NO SET'));
    error_log('qrcode.php - Session parameter: ' . ($_GET['session'] ?? 'NO SET'));
    
    require_once __DIR__ . '/../WhatsAppConfig.php';
    require_once __DIR__ . '/../WAHAAPI.php';
    
    error_log('qrcode.php - Archivos WAHA cargados');
    
    // Verificar autenticación
    require_once __DIR__ . '/../../email/api/_auth.php';
    
    error_log('qrcode.php - Archivo _auth.php cargado');
    
    $user = validateEmailApiAuth();
    error_log('qrcode.php - Usuario validado: ' . (isset($user['id']) ? 'SÍ (ID: ' . $user['id'] . ')' : 'NO'));
    
    if (!$user || !isset($user['id'])) {
        error_log('qrcode.php - Usuario no autenticado, enviando error 401');
        sendJsonResponse(false, null, 'No autenticado o sesión inválida', 401);
    }
    
    // Verificar permisos
    $user_permisos = isset($user['permisos']) ? $user['permisos'] : [];
    if (is_string($user_permisos)) {
        $user_permisos = json_decode($user_permisos, true) ?: [];
    }
    
    $has_permission = in_array('all', $user_permisos) || 
                     in_array('administracion_email', $user_permisos) ||
                     (isset($user['nivel']) && $user['nivel'] === 'root');
    
    if (!$has_permission) {
        sendJsonResponse(false, null, 'No tienes permisos para ver QR codes', 403);
    }
    
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        sendJsonResponse(false, null, 'Método no permitido. Use GET.', 405);
    }
    
    $sessionName = $_GET['session'] ?? null;
    
    if (empty($sessionName)) {
        sendJsonResponse(false, null, 'Campo requerido: session', 400);
    }
    
    // Cargar configuración de WAHA desde la configuración guardada
    $config = WhatsAppConfig::load();
    $wahaConfig = $config->getWahaConfig();
    
    // Log de la configuración cargada para verificación
    error_log('🔍 [qrcode.php] Configuración WAHA cargada:');
    error_log('🔍 [qrcode.php] Base URL: ' . ($wahaConfig['base_url'] ?? 'NO CONFIGURADA'));
    error_log('🔍 [qrcode.php] API Key: ' . (isset($wahaConfig['api_key']) && !empty($wahaConfig['api_key']) ? 'CONFIGURADA' : 'NO CONFIGURADA'));
    error_log('🔍 [qrcode.php] Sesión por defecto: ' . ($wahaConfig['default_session'] ?? 'default'));
    
    // Mapear default_session a session_name para WAHAAPI
    if (isset($wahaConfig['default_session']) && !isset($wahaConfig['session_name'])) {
        $wahaConfig['session_name'] = $wahaConfig['default_session'];
    }
    
    // Crear instancia de WAHA API usando la configuración cargada
    // Esta instancia usará la URL base de WAHA configurada para todas las peticiones
    $wahaAPI = new WAHAAPI($wahaConfig);
    
    try {
        // Primero verificar si la sesión existe y su estado
        $sessionStatus = null;
        $isAuthenticated = false;
        $requiresQR = false;
        
        try {
            $status = $wahaAPI->getSessionStatus($sessionName);
            error_log('Estado de sesión ' . $sessionName . ': ' . json_encode($status));
            $sessionStatus = $status;
            
            // Verificar si la sesión ya está autenticada
            $state = null;
            if (isset($status['status'])) {
                $state = is_string($status['status']) ? $status['status'] : ($status['status']['state'] ?? null);
            } elseif (isset($status['state'])) {
                $state = is_string($status['state']) ? $status['state'] : ($status['state']['state'] ?? null);
            }
            
            $stateLower = strtolower($state ?? '');
            
            // Estados que indican que la sesión está autenticada y lista
            $authenticatedStates = ['working', 'ready', 'authenticated', 'open', 'connected'];
            $isAuthenticated = in_array($stateLower, $authenticatedStates);
            
            // Estados que requieren escanear QR code
            $qrRequiredStates = ['scan_qr_code', 'qr_code', 'not_logged', 'not_authenticated', 'disconnected', 'close', 'closed'];
            $requiresQR = in_array($stateLower, $qrRequiredStates) || 
                         strpos($stateLower, 'qr') !== false || 
                         strpos($stateLower, 'scan') !== false;
            
            // Si está claramente autenticada, no mostrar QR
            if ($isAuthenticated && !$requiresQR) {
                error_log('Sesión ' . $sessionName . ' ya está autenticada (estado: ' . $state . ')');
                sendJsonResponse(false, null, 'La sesión "' . $sessionName . '" ya está autenticada. No se requiere código QR.', 200);
                return;
            }
            
            // Si requiere QR o el estado no está claro, continuar para obtenerlo
            if ($requiresQR) {
                error_log('Sesión ' . $sessionName . ' requiere QR code (estado: ' . $state . ')');
            } else if (!$isAuthenticated && $state) {
                // Estado conocido pero no autenticado, intentar obtener QR
                error_log('Sesión ' . $sessionName . ' no autenticada (estado: ' . $state . '), intentando obtener QR');
            }
        } catch (Exception $e) {
            error_log('Error verificando estado de sesión: ' . $e->getMessage());
            // Continuar de todas formas, puede que la sesión no exista aún o necesite QR
            // Si no se pudo verificar el estado, intentar obtener QR de todas formas
            $state = null;
            $isAuthenticated = false;
            $requiresQR = false;
        }
        
        $qrData = null;
        try {
            $qrData = $wahaAPI->getQRCode($sessionName);
            error_log('QR Data recibido - Tipo: ' . gettype($qrData));
            error_log('QR Data recibido - Tiene campo qr: ' . (isset($qrData['qr']) ? 'SÍ' : 'NO'));
            if (isset($qrData['qr'])) {
                $qrSize = strlen($qrData['qr']);
                error_log('QR Data recibido - Tamaño del QR completo: ' . $qrSize . ' bytes');
                
                // Verificar si el QR parece estar completo
                // Un QR code completo en base64 con prefijo "data:image/png;base64," debería ser de al menos 6000+ bytes
                if ($qrSize < 6000) {
                    error_log('QR Data recibido - ADVERTENCIA: El QR parece muy pequeño (' . $qrSize . ' bytes). Un QR code completo debería ser de al menos 6000+ bytes.');
                    error_log('QR Data recibido - Primeros 200 chars: ' . substr($qrData['qr'], 0, 200));
                    error_log('QR Data recibido - Últimos 200 chars: ' . substr($qrData['qr'], -200));
                } else {
                    error_log('QR Data recibido - QR parece completo (' . $qrSize . ' bytes)');
                    error_log('QR Data recibido - Primeros 100 chars: ' . substr($qrData['qr'], 0, 100));
                }
            }
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            
            // Si la sesión ya está autenticada, WAHA puede retornar 404
            if (strpos($errorMessage, '404') !== false || strpos($errorMessage, 'Not Found') !== false) {
                // Si el estado indica que requiere QR, mostrar error más descriptivo
                if ($requiresQR) {
                    error_log('QR no disponible pero estado indica que requiere QR (estado: ' . ($state ?? 'desconocido') . ')');
                    sendJsonResponse(false, null, 'No se pudo obtener el código QR. El estado de la sesión es "' . ($state ?? 'desconocido') . '". Esto puede indicar que la sesión necesita ser recreada. Intente eliminar y recrear la sesión.', 404);
                    return;
                } else {
                    // Si no requiere QR y hay 404, probablemente está autenticada
                    error_log('QR no disponible - sesión probablemente ya autenticada: ' . $errorMessage);
                    sendJsonResponse(false, null, 'La sesión "' . $sessionName . '" ya está autenticada. No se requiere código QR.', 200);
                    return;
                }
            } else {
                // Otro tipo de error al obtener QR
                error_log('Error obteniendo QR code: ' . $errorMessage);
                // Si el estado requiere QR, mostrar error más descriptivo
                if ($requiresQR) {
                    sendJsonResponse(false, null, 'Error al obtener código QR. Estado de sesión: "' . ($state ?? 'desconocido') . '". Error: ' . $errorMessage, 500);
                    return;
                }
                // Re-lanzar el error para que se maneje en el catch general
                throw $e;
            }
        }
        
        // WAHA retorna el QR según el formato solicitado:
        // - Con format=image y Accept: image/png → retorna imagen PNG binaria (convertida a base64 por WAHAAPI)
        // - Con format=json y Accept: application/json → retorna JSON con base64
        // Verificar estructura de respuesta
        $qrCode = null;
        
        // Si WAHAAPI ya convirtió la imagen PNG a base64, usar directamente
        if (isset($qrData['qr']) && strpos($qrData['qr'], 'data:image') === 0) {
            $qrCode = $qrData['qr'];
        } elseif (isset($qrData['qr'])) {
            // Tiene campo qr pero sin prefijo, agregarlo
            $qrCode = 'data:image/png;base64,' . $qrData['qr'];
        } elseif (isset($qrData['data'])) {
            $qrCode = is_string($qrData['data']) && strpos($qrData['data'], 'data:image') === 0 
                ? $qrData['data'] 
                : 'data:image/png;base64,' . $qrData['data'];
        } elseif (isset($qrData['qrcode'])) {
            $qrCode = is_string($qrData['qrcode']) && strpos($qrData['qrcode'], 'data:image') === 0 
                ? $qrData['qrcode'] 
                : 'data:image/png;base64,' . $qrData['qrcode'];
        } elseif (isset($qrData['qrcode_base64'])) {
            $qrCode = 'data:image/png;base64,' . $qrData['qrcode_base64'];
        } elseif (is_string($qrData)) {
            // Si es string, puede ser base64 directo o necesita prefijo
            if (strpos($qrData, 'data:image') === 0) {
                $qrCode = $qrData;
            } else {
                $qrCode = 'data:image/png;base64,' . $qrData;
            }
        } elseif (is_array($qrData) && !empty($qrData)) {
            // Si es un array, buscar cualquier campo que pueda contener el QR
            foreach ($qrData as $key => $value) {
                if (is_string($value) && (strlen($value) > 100 || strpos($key, 'qr') !== false || strpos($key, 'code') !== false)) {
                    $qrCode = strpos($value, 'data:image') === 0 ? $value : 'data:image/png;base64,' . $value;
                    break;
                }
            }
        }
        
        if ($qrCode === null) {
            error_log('No se pudo extraer QR code de la respuesta: ' . json_encode($qrData));
            sendJsonResponse(false, null, 'No se pudo obtener el código QR. La sesión puede estar ya autenticada o no existir.', 404);
            return;
        }
        
        // Asegurar que el QR tenga el formato correcto para usar directamente en <img src="...">
        // Según documentación: "En JavaScript solo haces: img.src = data.qr;"
        if (strpos($qrCode, 'data:image') !== 0) {
            $qrCode = 'data:image/png;base64,' . $qrCode;
        }
        
        // Preparar respuesta (sin incluir raw para evitar problemas de tamaño)
        $responseData = [
            'session' => $sessionName,
            'qr' => $qrCode
        ];
        
        error_log('qrcode.php - QR Code procesado, tamaño: ' . strlen($qrCode) . ' bytes');
        error_log('qrcode.php - Preparando respuesta JSON');
        
        // Calcular tamaño del JSON antes de enviarlo
        $jsonTest = json_encode($responseData, JSON_UNESCAPED_UNICODE);
        error_log('qrcode.php - Tamaño del JSON a enviar: ' . strlen($jsonTest) . ' bytes');
        error_log('qrcode.php - Primeros 200 chars del JSON: ' . substr($jsonTest, 0, 200));
        
        sendJsonResponse(true, $responseData);
        
    } catch (Exception $e) {
        error_log('Error obteniendo QR code para sesión ' . $sessionName . ': ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        
        // Asegurar que siempre se retorne JSON válido, incluso en caso de error
        $errorMessage = 'Error obteniendo QR code: ' . $e->getMessage();
        // Limitar el tamaño del mensaje de error para evitar problemas
        if (strlen($errorMessage) > 500) {
            $errorMessage = substr($errorMessage, 0, 500) . '...';
        }
        
        sendJsonResponse(false, null, $errorMessage, 500);
    }
    
} catch (Exception $e) {
    error_log('Error en whatsapp/api/qrcode.php: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    // Asegurar que siempre se retorne JSON válido
    $errorMessage = 'Error interno: ' . $e->getMessage();
    if (strlen($errorMessage) > 500) {
        $errorMessage = substr($errorMessage, 0, 500) . '...';
    }
    
    sendJsonResponse(false, null, $errorMessage, 500);
} catch (Error $e) {
    error_log('Fatal Error en whatsapp/api/qrcode.php: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    // Asegurar que siempre se retorne JSON válido
    $errorMessage = 'Error fatal: ' . $e->getMessage();
    if (strlen($errorMessage) > 500) {
        $errorMessage = substr($errorMessage, 0, 500) . '...';
    }
    
    sendJsonResponse(false, null, $errorMessage, 500);
}

