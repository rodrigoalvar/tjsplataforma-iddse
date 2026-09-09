<?php
/**
 * Middleware de autenticación
 * Funciones para validar tokens de sesión
 */

require_once __DIR__ . '/../classes/User.php';

/**
 * Fallback para getallheaders() si no está disponible
 */
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}

/**
 * Validar token de sesión
 * @param string $token Token de sesión
 * @return bool True si el token es válido, false en caso contrario
 */
function validateSessionToken($token) {
    if (empty($token)) {
        return false;
    }
    
    try {
        $user = new User();
        $userData = $user->validateSession($token);
        return $userData !== false;
    } catch (Exception $e) {
        error_log('Error validando token: ' . $e->getMessage());
        return false;
    }
}

/**
 * Obtener datos del usuario desde el token
 * @param string $token Token de sesión
 * @return array|false Datos del usuario o false si el token es inválido
 */
function getUserFromToken($token) {
    if (empty($token)) {
        return false;
    }
    
    try {
        $user = new User();
        return $user->validateSession($token);
    } catch (Exception $e) {
        error_log('Error obteniendo usuario: ' . $e->getMessage());
        return false;
    }
}

/**
 * Requerir autenticación
 * Termina la ejecución si no hay token válido
 */
function requireAuth() {
    $headers = getallheaders();
    // Incluir cookie 'session_token' como fuente adicional del token
    $cookieToken = isset($_COOKIE['session_token']) ? $_COOKIE['session_token'] : null;
    $token = $headers['Authorization'] ?? $_GET['token'] ?? $_POST['token'] ?? $cookieToken ?? null;
    
    if (!$token) {
        http_response_code(401);
        echo json_encode(['error' => 'Token de autorización requerido']);
        exit;
    }
    
    // Remover 'Bearer ' si está presente
    $token = str_replace('Bearer ', '', $token);
    
    if (!validateSessionToken($token)) {
        http_response_code(401);
        echo json_encode(['error' => 'Token inválido o expirado']);
        exit;
    }
    
    return $token;
}
?>