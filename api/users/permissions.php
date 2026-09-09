<?php
/**
 * API para obtener permisos del sistema
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * Endpoint: /api/users/permissions.php
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';
require_once '../middleware/auth.php';

// Solo permitir método GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Método no permitido'
    ]);
    exit;
}

try {
    // Verificar sesión
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
    
    // Validar sesión
    $userData = getUserFromToken($token);
    if (!$userData) {
        throw new Exception('Sesión inválida');
    }
    
    // Obtener permisos del sistema
    $pdo = getDBConnection();
    
    $query = "SELECT * FROM system_permissions ORDER BY category, permission_name";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    
    $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Agrupar por categoría
    $grouped = [];
    foreach ($permissions as $permission) {
        $grouped[$permission['category']][] = $permission;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $grouped,
        'total' => count($permissions)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>


