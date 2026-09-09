<?php
/**
 * API para obtener configuración del workspace por usuario
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * Endpoint: /api/workspace/get-config.php
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    require_once '../../config/database.php';
    require_once '../../middleware/auth.php';
    
    // Solo permitir método GET
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Método no permitido');
    }
    
    // Obtener token de sesión
    $token = null;
    if (isset($_COOKIE['session_token']) && !empty($_COOKIE['session_token'])) {
        $token = $_COOKIE['session_token'];
    } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
        if (strpos($auth_header, 'Bearer ') === 0) {
            $token = substr($auth_header, 7);
        }
    }
    
    if (!$token) {
        throw new Exception('Token de sesión requerido');
    }
    
    // Validar sesión y obtener usuario
    $userData = getUserFromToken($token);
    if (!$userData) {
        throw new Exception('Sesión inválida');
    }
    
    $userId = $userData['id'];
    
    // Conectar a la base de datos
    $pdo = getDBConnection();
    
    // Obtener configuración del usuario
    $query = "SELECT config_data FROM workspace_config WHERE user_id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result && $result['config_data']) {
        $configData = json_decode($result['config_data'], true);
        echo json_encode([
            'success' => true,
            'config' => $configData,
            'user_id' => $userId
        ], JSON_UNESCAPED_UNICODE);
    } else {
        // No hay configuración guardada, devolver objeto vacío
        echo json_encode([
            'success' => true,
            'config' => null,
            'user_id' => $userId
        ], JSON_UNESCAPED_UNICODE);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
