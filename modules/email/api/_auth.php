<?php
/**
 * Helper de Autenticación para Endpoints de Email
 * 
 * Funciones comunes para validar autenticación en los endpoints.
 */

if (!function_exists('validateEmailApiAuth')) {
    /**
     * Validar autenticación para endpoints de email
     * @param bool $requireAdmin Si requiere permisos de administrador
     * @return array|null Datos del usuario si está autenticado, null si no
     */
    function validateEmailApiAuth($requireAdmin = false) {
        // Intentar cargar clase User desde el sistema principal
        $userClassPaths = [
            __DIR__ . '/../../classes/User.php',
            __DIR__ . '/../../../classes/User.php'
        ];
        
        $userClassLoaded = false;
        foreach ($userClassPaths as $path) {
            if (file_exists($path)) {
                require_once $path;
                $userClassLoaded = true;
                break;
            }
        }
        
        if (!$userClassLoaded) {
            // Si no se encuentra User.php, usar validación básica por token
            return validateBasicAuth();
        }
        
        // Obtener token de sesión
        $token = null;
        
        // Intentar obtener desde headers
        $headers = null;
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        } else {
            // Fallback para servidores que no tienen getallheaders()
            $headers = [];
            foreach ($_SERVER as $name => $value) {
                if (substr($name, 0, 5) == 'HTTP_') {
                    $headerName = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($name, 5)))));
                    $headers[$headerName] = $value;
                }
            }
        }
        
        if (isset($headers['Authorization'])) {
            $authHeader = $headers['Authorization'];
            if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                $token = $matches[1];
            }
        }
        
        if (!$token && isset($_COOKIE['session_token'])) {
            $token = $_COOKIE['session_token'];
        }
        
        if (!$token) {
            return null;
        }
        
        // Validar sesión
        try {
            $user = new User();
            $user_data = $user->validateSession($token);
            
            if (!$user_data || !is_array($user_data)) {
                return null;
            }
            
            // Verificar permisos de administrador si es requerido
            if ($requireAdmin) {
                $user_permisos = isset($user_data['permisos']) ? $user_data['permisos'] : [];
                
                if (is_string($user_permisos)) {
                    $user_permisos = json_decode($user_permisos, true) ?: [];
                }
                
                $isAdmin = in_array('all', $user_permisos) || 
                          (isset($user_data['nivel']) && in_array($user_data['nivel'], ['root', 'admin']));
                
                if (!$isAdmin) {
                    return null;
                }
            }
            
            return $user_data;
            
        } catch (Exception $e) {
            error_log('Error validando sesión en email API: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Validación básica por token (fallback)
     * @return array|null Datos básicos si el token es válido
     */
    function validateBasicAuth() {
        $token = null;
        
        // Intentar obtener desde headers
        $headers = null;
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        } else {
            // Fallback para servidores que no tienen getallheaders()
            $headers = [];
            foreach ($_SERVER as $name => $value) {
                if (substr($name, 0, 5) == 'HTTP_') {
                    $headerName = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($name, 5)))));
                    $headers[$headerName] = $value;
                }
            }
        }
        
        if (isset($headers['Authorization'])) {
            $authHeader = $headers['Authorization'];
            if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                $token = $matches[1];
            }
        }
        
        if (!$token && isset($_COOKIE['session_token'])) {
            $token = $_COOKIE['session_token'];
        }
        
        // Validación básica: si hay token, permitir (para desarrollo)
        // En producción, esto debería validarse contra la base de datos
        if ($token && strlen($token) > 10) {
            return ['id' => 0, 'authenticated' => true];
        }
        
        return null;
    }
}

/**
 * Verificar permiso de envío de email
 * @param array|null $user Datos del usuario
 * @return bool True si tiene permiso
 */
if (!function_exists('checkEmailSendPermission')) {
    function checkEmailSendPermission($user) {
        if (!$user || !is_array($user)) {
            return false;
        }
        
        // ROOT tiene todos los permisos
        if (isset($user['nivel']) && $user['nivel'] === 'root') {
            return true;
        }
        
        // Verificar permiso específico
        $permissions = [];
        if (isset($user['permisos'])) {
            if (is_string($user['permisos'])) {
                $permissions = json_decode($user['permisos'], true) ?: [];
            } elseif (is_array($user['permisos'])) {
                $permissions = $user['permisos'];
            }
        }
        
        return in_array('envios_email', $permissions) || in_array('all', $permissions);
    }
}

if (!function_exists('sendJsonResponse')) {
    /**
     * Enviar respuesta JSON
     * @param bool $success Si fue exitoso
     * @param mixed $data Datos a enviar
     * @param string|null $error Mensaje de error
     * @param int $httpCode Código HTTP
     */
    function sendJsonResponse($success, $data = null, $error = null, $httpCode = 200) {
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        
        $response = ['success' => $success];
        
        if ($success && $data !== null) {
            $response['data'] = $data;
        }
        
        if (!$success && $error !== null) {
            $response['error'] = $error;
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }
}

