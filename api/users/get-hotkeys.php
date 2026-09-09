<?php
/**
 * API para obtener los hotkeys del usuario en informes-manager
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
    
    $db = getDBConnection();
    
    if (!$db) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos']);
        exit();
    }
    
    // Verificar si existe la tabla
    $tableExists = $db->query("SHOW TABLES LIKE 'user_preferences'")->rowCount() > 0;
    
    if (!$tableExists) {
        // Si no existe la tabla, retornar null (sin hotkeys guardados)
        echo json_encode([
            'success' => true,
            'data' => null,
            'message' => 'No hay hotkeys guardados'
        ]);
        exit();
    }
    
    // Obtener preferencia de hotkeys
    $stmt = $db->prepare("
        SELECT preference_value 
        FROM user_preferences 
        WHERE user_id = :user_id AND preference_key = 'informes_manager_hotkeys'
    ");
    
    $stmt->execute([':user_id' => $userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result && $result['preference_value']) {
        $hotkeys = json_decode($result['preference_value'], true);
        
        if ($hotkeys && is_array($hotkeys)) {
            echo json_encode([
                'success' => true,
                'data' => $hotkeys,
                'message' => 'Hotkeys obtenidos correctamente'
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                'success' => true,
                'data' => null,
                'message' => 'Formato de hotkeys inválido'
            ]);
        }
    } else {
        echo json_encode([
            'success' => true,
            'data' => null,
            'message' => 'No hay hotkeys guardados para este usuario'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Error en users/get-hotkeys.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error al obtener los hotkeys: ' . $e->getMessage()
    ]);
}
?>
