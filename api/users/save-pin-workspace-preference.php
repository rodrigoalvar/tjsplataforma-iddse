<?php
/**
 * API para guardar la preferencia "WS on Top" del usuario.
 *
 * Esta preferencia controla si el estudio abierto en WorkSpace se promueve
 * a la primera fila del listado de dashboard-unified.html (true) o si conserva
 * su orden natural (false). Sigue el mismo patrón que save-sort-preference.php
 * y reutiliza la tabla user_preferences con preference_key = 'dashboard_pin_workspace_on_top'.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../config/database.php';

try {
    // Validar sesión (mismo flujo que save-sort-preference.php)
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

    if (!isset($input['enabled'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Falta parámetro: enabled (boolean) es requerido']);
        exit();
    }

    // Aceptar bool reales o strings/ints típicos
    $rawEnabled = $input['enabled'];
    if (is_bool($rawEnabled)) {
        $enabled = $rawEnabled;
    } elseif (is_numeric($rawEnabled)) {
        $enabled = ((int)$rawEnabled) !== 0;
    } elseif (is_string($rawEnabled)) {
        $normalized = strtolower(trim($rawEnabled));
        $enabled = in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Valor inválido para enabled']);
        exit();
    }

    $db = getDBConnection();

    if (!$db) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos']);
        exit();
    }

    // Crear tabla de preferencias si no existe (idéntica al patrón de save-sort-preference)
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

    $preferenceData = json_encode(['enabled' => (bool)$enabled]);

    $stmt = $db->prepare("
        INSERT INTO user_preferences (user_id, preference_key, preference_value)
        VALUES (:user_id, 'dashboard_pin_workspace_on_top', :preference_value)
        ON DUPLICATE KEY UPDATE
            preference_value = :preference_value_update,
            fecha_modificacion = CURRENT_TIMESTAMP
    ");

    $stmt->execute([
        ':user_id' => $userId,
        ':preference_value' => $preferenceData,
        ':preference_value_update' => $preferenceData
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Preferencia WS on Top guardada correctamente',
        'data' => ['enabled' => (bool)$enabled]
    ]);

} catch (Exception $e) {
    error_log('Error en users/save-pin-workspace-preference.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error al guardar la preferencia: ' . $e->getMessage()
    ]);
}
