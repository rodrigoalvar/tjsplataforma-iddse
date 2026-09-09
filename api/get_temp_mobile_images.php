<?php
/**
 * API para obtener imágenes temporales de una sesión móvil
 * El desktop consulta periódicamente este endpoint para ver si hay nuevas imágenes
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../config/database.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Manejar preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Verificar que sea GET
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Método no permitido');
    }
    
    // Obtener session_id del request
    $sessionId = $_GET['session_id'] ?? null;
    
    if (!$sessionId) {
        throw new Exception('session_id es requerido');
    }
    
    // Crear tabla si no existe
    $createTableSQL = "CREATE TABLE IF NOT EXISTS mobile_temp_images (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id VARCHAR(255) NOT NULL,
        temp_path VARCHAR(500) NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_size INT,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
        INDEX idx_session (session_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $pdo->exec($createTableSQL);
    
    // Obtener imágenes pendientes de la sesión
    $sql = "SELECT id, session_id, temp_path, file_name, file_size, uploaded_at, status 
            FROM mobile_temp_images 
            WHERE session_id = ? AND status = 'pending'
            ORDER BY uploaded_at ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$sessionId]);
    $images = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $images,
        'count' => count($images)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}








