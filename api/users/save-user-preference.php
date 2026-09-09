<?php
/**
 * API genérica para guardar preferencias de usuario
 * Permite guardar cualquier tipo de preferencia usando preference_key
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
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
    
    // Obtener datos del cuerpo de la petición
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['preference_key']) || !isset($input['preference_value'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Faltan parámetros: preference_key y preference_value son requeridos']);
        exit();
    }
    
    $preferenceKey = $input['preference_key'];
    $preferenceValue = $input['preference_value'];
    
    // Validar preference_key (solo alfanumérico, guiones y guiones bajos)
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $preferenceKey)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'preference_key inválido']);
        exit();
    }
    
    // Validación específica para preferencias de ordenamiento
    if (strpos($preferenceKey, '_sort') !== false) {
        if (!isset($preferenceValue['column']) || !isset($preferenceValue['direction'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Para preferencias de ordenamiento, se requieren column y direction']);
            exit();
        }
        
        // Validar dirección
        if (!in_array($preferenceValue['direction'], ['asc', 'desc'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Dirección inválida. Debe ser "asc" o "desc"']);
            exit();
        }
        
        // Validar columnas según preference_key
        $allowedColumns = [];
        if ($preferenceKey === 'ai_informes_sort') {
            $allowedColumns = ['estudio_id', 'paciente', 'modalidad', 'descripcion', 'fecha'];
        }
        // Agregar más validaciones según se necesiten para otras secciones
        
        if (!empty($allowedColumns) && $preferenceValue['column'] !== null && !in_array($preferenceValue['column'], $allowedColumns)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Columna inválida para esta sección']);
            exit();
        }
    }
    
    $db = getDBConnection();
    
    if (!$db) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos']);
        exit();
    }
    
    // Crear tabla de preferencias de usuario si no existe
    $db->exec("
        CREATE TABLE IF NOT EXISTS user_preferences (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            preference_key VARCHAR(100) NOT NULL,
            preference_value TEXT,
            fecha_modificacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_preference (user_id, preference_key),
            FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            INDEX idx_user_id (user_id),
            INDEX idx_preference_key (preference_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Guardar preferencia
    $preferenceData = json_encode($preferenceValue, JSON_UNESCAPED_UNICODE);
    
    $stmt = $db->prepare("
        INSERT INTO user_preferences (user_id, preference_key, preference_value)
        VALUES (:user_id, :preference_key, :preference_value)
        ON DUPLICATE KEY UPDATE 
            preference_value = :preference_value_update,
            fecha_modificacion = CURRENT_TIMESTAMP
    ");
    
    $stmt->execute([
        ':user_id' => $userId,
        ':preference_key' => $preferenceKey,
        ':preference_value' => $preferenceData,
        ':preference_value_update' => $preferenceData
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Preferencia guardada correctamente',
        'data' => [
            'preference_key' => $preferenceKey,
            'preference_value' => $preferenceValue
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Error en users/save-user-preference.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error al guardar la preferencia: ' . $e->getMessage()
    ]);
}