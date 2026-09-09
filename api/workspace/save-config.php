<?php
/**
 * API para guardar configuración del workspace por usuario
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * Endpoint: /api/workspace/save-config.php
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    require_once '../../config/database.php';
    require_once '../../middleware/auth.php';
    
    // Solo permitir método POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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
    
    // Obtener datos del cuerpo de la petición
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data || !isset($data['config'])) {
        throw new Exception('Datos de configuración requeridos');
    }
    
    $configData = $data['config'];
    
    // Validar que configData sea un array/objeto válido
    if (!is_array($configData)) {
        throw new Exception('Formato de configuración inválido');
    }
    
    // Conectar a la base de datos
    $pdo = getDBConnection();
    
    // Convertir configuración a JSON
    $configJson = json_encode($configData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    // Guardar o actualizar configuración
    // NOTA: Si el mismo usuario tiene múltiples dispositivos abiertos simultáneamente,
    // el último guardado sobrescribirá al anterior (último en escribir gana).
    // Sin embargo, como las posiciones se guardan como relativas (relativeX, relativeW),
    // cuando un dispositivo carga el layout guardado por otro, se adaptará automáticamente
    // al tamaño de pantalla del dispositivo actual.
    $query = "INSERT INTO workspace_config (user_id, config_data) 
              VALUES (?, ?)
              ON DUPLICATE KEY UPDATE 
                  config_data = VALUES(config_data),
                  updated_at = CURRENT_TIMESTAMP";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$userId, $configJson]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Configuración guardada correctamente',
        'user_id' => $userId
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
