<?php
/**
 * API Simplificada para Verificación de Permisos
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * Endpoint: /api/users/check-permission.php
 */

// Configurar headers para JSON
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Solo permitir método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Método no permitido'
    ]);
    exit;
}

try {
    // Obtener datos del POST
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['permission'])) {
        throw new Exception('Permiso requerido');
    }
    
    $permission = $input['permission'];
    
    // Conectar a la base de datos
    require_once '../../config/database.php';
    $pdo = getDBConnection();
    
    // Obtener token de sesión
    $token = null;
    
    // Prioridad 1: Cookie
    if (isset($_COOKIE['session_token']) && !empty($_COOKIE['session_token'])) {
        $token = $_COOKIE['session_token'];
    }
    // Prioridad 2: Header Authorization
    elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
        if (strpos($auth_header, 'Bearer ') === 0) {
            $token = substr($auth_header, 7);
        }
    }
    
    if (!$token) {
        throw new Exception('Token de sesión requerido');
    }
    
    // Verificar sesión en la base de datos
    $query = "SELECT u.id, u.nombre, u.apellido, u.email, u.nivel, u.permisos, u.activo 
              FROM usuarios u 
              INNER JOIN user_sessions s ON u.id = s.user_id 
              WHERE s.session_token = ? AND s.expires_at > NOW() AND u.activo = 1";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    
    if (!$user) {
        throw new Exception('Sesión inválida o expirada');
    }
    
    // Verificar permiso
    $hasPermission = false;
    
    // ROOT tiene todos los permisos
    if ($user['nivel'] === 'root') {
        $hasPermission = true;
    }
    // ADMIN tiene permisos específicos
    elseif ($user['nivel'] === 'admin') {
        $permissions = json_decode($user['permisos'], true) ?: [];
        $hasPermission = in_array($permission, $permissions) || in_array('all', $permissions);
    }
    // USER tiene permisos limitados
    elseif ($user['nivel'] === 'user') {
        $permissions = json_decode($user['permisos'], true) ?: [];
        $hasPermission = in_array($permission, $permissions);
    }
    
    // Respuesta exitosa
    echo json_encode([
        'success' => true,
        'hasPermission' => $hasPermission,
        'permission' => $permission,
        'user' => [
            'id' => $user['id'],
            'nombre' => $user['nombre'],
            'apellido' => $user['apellido'],
            'email' => $user['email'],
            'nivel' => $user['nivel'],
            'permisos' => json_decode($user['permisos'], true) ?: []
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
