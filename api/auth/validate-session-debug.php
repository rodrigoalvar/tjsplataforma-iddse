<?php
/**
 * API de debug para validar sesión
 * Muestra información detallada sobre cookies y sesiones
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../../classes/User.php';

try {
    // Información de debug
    $debug_info = [
        'cookies_received' => $_COOKIE,
        'session_token_cookie' => isset($_COOKIE['session_token']) ? $_COOKIE['session_token'] : 'NO_COOKIE',
        'all_headers' => getallheaders(),
        'request_method' => $_SERVER['REQUEST_METHOD'],
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'NO_USER_AGENT'
    ];
    
    // Obtener token de sesión de la cookie
    $session_token = null;
    
    if (isset($_COOKIE['session_token']) && !empty($_COOKIE['session_token'])) {
        $session_token = $_COOKIE['session_token'];
    }
    
    // Verificar si hay token de sesión
    if (empty($session_token)) {
        echo json_encode([
            'success' => false,
            'message' => 'No hay sesión activa',
            'debug_info' => $debug_info,
            'user' => null
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }
    
    // Validar sesión usando la clase User
    $user = new User();
    $user_data = $user->validateSession($session_token);
    
    if (!$user_data) {
        echo json_encode([
            'success' => false,
            'message' => 'Sesión inválida o expirada',
            'debug_info' => $debug_info,
            'user' => null
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
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
                $permissions = ['dashboard', 'estudios', 'pacs_query', 'informes', 'gestionInformes', 'usuarios', 'plantillas', 'visor'];
                break;
            case 'user':
                $permissions = ['dashboard', 'informes', 'grabacion'];
                break;
            default:
                $permissions = ['dashboard'];
        }
        
        // Actualizar permisos en la base de datos
        require_once '../../config/database.php';
        $pdo = new PDO($dsn, $username, $password, $options);
        $updateStmt = $pdo->prepare("UPDATE usuarios SET permisos = ? WHERE id = ?");
        $updateStmt->execute([json_encode($permissions), $user_data['id']]);
    }
    
    // Respuesta exitosa
    echo json_encode([
        'success' => true,
        'message' => 'Sesión válida',
        'debug_info' => $debug_info,
        'user' => [
            'id' => $user_data['id'],
            'nombre' => $user_data['nombre'],
            'apellido' => $user_data['apellido'],
            'nivel' => $user_data['nivel'],
            'permisos' => $permissions
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor: ' . $e->getMessage(),
        'debug_info' => $debug_info ?? [],
        'user' => null
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>
