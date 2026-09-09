<?php
/**
 * API para obtener la preferencia "WS on Top" del usuario.
 *
 * Devuelve { success: true, data: { enabled: boolean } } cuando hay preferencia
 * guardada, o { success: true, data: null } si nunca se guardó. El default del
 * frontend es true (mantener comportamiento histórico de fijar al tope el
 * estudio abierto en WorkSpace).
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../config/database.php';

try {
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

    // Crear tabla si no existe (idéntica al patrón usado en otras prefs)
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

    $stmt = $db->prepare("
        SELECT preference_value
        FROM user_preferences
        WHERE user_id = :user_id AND preference_key = 'dashboard_pin_workspace_on_top'
    ");

    $stmt->execute([':user_id' => $userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result && $result['preference_value']) {
        $preference = json_decode($result['preference_value'], true);

        if (is_array($preference) && array_key_exists('enabled', $preference)) {
            echo json_encode([
                'success' => true,
                'data' => ['enabled' => (bool)$preference['enabled']]
            ]);
            exit();
        }
    }

    echo json_encode(['success' => true, 'data' => null]);

} catch (Exception $e) {
    error_log('Error en users/get-pin-workspace-preference.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error al obtener la preferencia: ' . $e->getMessage()
    ]);
}
