<?php
/**
 * API genérica para obtener preferencias de usuario
 * Permite obtener cualquier tipo de preferencia usando preference_key
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../config/database.php';

try {
    // Validar sesión
    $sessionToken = null;
    
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        $sessionToken = $headers['Authorization'] ?? null;
    }
    
    if (!$sessionToken) {
        $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    }
    
    if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
        $sessionToken = substr($sessionToken, 7);
    }
    
    if (!$sessionToken) {
        $sessionToken = $_COOKIE['session_token'] ?? null;
    }
    
    if (!$sessionToken) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Token de autorización requerido']);
        exit();
    }
    
    $user = new User();
    $userData = $user->validateSession($sessionToken);
    
    if (!$userData) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sesión inválida']);
        exit();
    }
    
    $userId = $userData['id'];
    
    // Obtener preference_key de query string
    $preferenceKey = $_GET['preference_key'] ?? null;
    
    if (!$preferenceKey) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'preference_key es requerido']);
        exit();
    }
    
    // Validar preference_key
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $preferenceKey)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'preference_key inválido']);
        exit();
    }
    
    $db = getDBConnection();
    
    if (!$db) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos']);
        exit();
    }
    
    // Verificar si existe la tabla
    $tableCheck = $db->query("SHOW TABLES LIKE 'user_preferences'");
    $tableExists = $tableCheck && $tableCheck->rowCount() > 0;
    
    if (!$tableExists) {
        echo json_encode([
            'success' => true,
            'data' => null
        ]);
        exit();
    }
    
    // Obtener preferencia
    $stmt = $db->prepare("
        SELECT preference_value 
        FROM user_preferences 
        WHERE user_id = :user_id AND preference_key = :preference_key
    ");
    
    $stmt->execute([
        ':user_id' => $userId,
        ':preference_key' => $preferenceKey
    ]);
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result && $result['preference_value']) {
        $preference = json_decode($result['preference_value'], true);
        
        if ($preference !== null) {
            echo json_encode([
                'success' => true,
                'data' => $preference
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                'success' => true,
                'data' => null
            ]);
        }
    } else {
        echo json_encode([
            'success' => true,
            'data' => null
        ]);
    }
    
} catch (Exception $e) {
    error_log("Error en users/get-user-preference.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error al obtener la preferencia: ' . $e->getMessage()
    ]);
}