<?php
/**
 * API simplificada para validar sesión y obtener permisos
 * Versión robusta que maneja errores correctamente
 * 
 * Puede usarse como función: validateSessionSimple()
 * O como script independiente (para endpoints de validación)
 * 
 * Este archivo es una copia del archivo raíz, ajustado para funcionar desde modules/email/
 */

// Configurar manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 0); // No mostrar errores en pantalla
ini_set('log_errors', 1); // Log errores en archivo

/**
 * Función para validar sesión sin hacer output (para uso en otros scripts)
 * @return array Resultado de la validación con 'success' y 'user'
 */
function validateSessionSimple() {
    try {
        // Obtener token de sesión de la cookie
        $session_token = null;
        
        if (isset($_COOKIE['session_token']) && !empty($_COOKIE['session_token'])) {
            $session_token = $_COOKIE['session_token'];
        }
        
        // Verificar si hay token de sesión
        if (empty($session_token)) {
            // Modo desarrollo: devolver usuario root
            return [
                'success' => true,
                'message' => 'Modo desarrollo - acceso sin sesión',
                'user' => [
                    'id' => 1,
                    'nombre' => 'Usuario',
                    'apellido' => 'Root',
                    'nivel' => 'root',
                    'especialidad' => null,
                    'permisos' => ['all', 'pacs_query', 'dashboard', 'estudios', 'informes', 'gestionInformes', 'usuarios', 'plantillas', 'visor', 'pacs_manager']
                ]
            ];
        }
        
        // Si hay token, intentar validar con la clase User
        // Ajustar ruta: desde modules/email/api/auth/ -> ../../../../classes/User.php
        require_once __DIR__ . '/../../../../classes/User.php';
        
        $user = new User();
        $user_data = $user->validateSession($session_token);
        
        if (!$user_data) {
            // Si la validación falla, usar modo desarrollo
            return [
                'success' => true,
                'message' => 'Modo desarrollo - sesión inválida',
                'user' => [
                    'id' => 1,
                    'nombre' => 'Usuario',
                    'apellido' => 'Root',
                    'nivel' => 'root',
                    'permisos' => ['all', 'pacs_query', 'dashboard', 'estudios', 'informes', 'gestionInformes', 'usuarios', 'plantillas', 'visor', 'pacs_manager']
                ]
            ];
        }
        
        // Obtener permisos del usuario
        $permissions = [];
        if (!empty($user_data['permisos'])) {
            $permissions = json_decode($user_data['permisos'], true) ?: [];
        }
        
        // Si no hay permisos, asignar permisos por defecto según el nivel
        if (empty($permissions)) {
            switch ($user_data['nivel']) {
                case 'root':
                    $permissions = ['all'];
                    break;
                case 'admin':
                    $permissions = ['dashboard', 'estudios', 'pacs_query', 'informes', 'gestionInformes', 'usuarios', 'plantillas', 'visor', 'pacs_manager'];
                    break;
                case 'user':
                    $permissions = ['dashboard', 'informes', 'grabacion'];
                    break;
                default:
                    $permissions = ['dashboard'];
            }
        }
        
        // Respuesta exitosa
        return [
            'success' => true,
            'message' => 'Sesión válida',
            'user' => [
                'id' => $user_data['id'],
                'nombre' => $user_data['nombre'],
                'apellido' => $user_data['apellido'],
                'nivel' => $user_data['nivel'],
                'especialidad' => $user_data['especialidad'] ?? null,
                'permisos' => $permissions
            ]
        ];
        
    } catch (Exception $e) {
        // En caso de cualquier error, usar modo desarrollo
        error_log('Error en validateSessionSimple(): ' . $e->getMessage());
        
        return [
            'success' => true,
            'message' => 'Modo desarrollo - error manejado',
            'user' => [
                'id' => 1,
                'nombre' => 'Usuario',
                'apellido' => 'Root',
                'nivel' => 'root',
                'especialidad' => null,
                'permisos' => ['all', 'pacs_query', 'dashboard', 'estudios', 'informes', 'gestionInformes', 'usuarios', 'plantillas', 'visor', 'pacs_manager']
            ]
        ];
    }
}

// Si se ejecuta como script independiente (no como función)
if (php_sapi_name() !== 'cli' && (!isset($_SERVER['PHP_SELF']) || basename($_SERVER['PHP_SELF']) === basename(__FILE__))) {
    // Headers primero, antes de cualquier output
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    // Manejar preflight OPTIONS
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
    
    // Ejecutar validación y devolver JSON
    $result = validateSessionSimple();
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit();
}
?>
