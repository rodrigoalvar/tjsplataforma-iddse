<?php
/**
 * API para subir imágenes temporales desde móvil
 * Las imágenes se guardan en una carpeta temporal hasta que sean aceptadas
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../config/database.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Manejar preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Verificar que sea POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido');
    }
    
    // Verificar que se haya subido un archivo
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No se recibió ningún archivo o hubo un error en la subida');
    }
    
    // Obtener datos adicionales
    $sessionId = $_POST['session_id'] ?? null;
    $studyId = $_POST['study_id'] ?? null;
    
    if (!$sessionId || !$studyId) {
        throw new Exception('session_id y study_id son requeridos');
    }
    
    // Crear directorio temporal si no existe
    $tempDir = '../uploads/temp_mobile/';
    if (!file_exists($tempDir)) {
        mkdir($tempDir, 0777, true);
    }
    
    // Generar nombre único para el archivo
    $file = $_FILES['file'];
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $uniqueName = uniqid() . '_' . time() . '.' . $extension;
    $tempPath = $tempDir . $uniqueName;
    
    // Mover archivo a carpeta temporal
    if (!move_uploaded_file($file['tmp_name'], $tempPath)) {
        throw new Exception('Error moviendo el archivo a la carpeta temporal');
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
    
    // Registrar imagen en la base de datos
    $relativePath = 'uploads/temp_mobile/' . $uniqueName;
    $fileSize = filesize($tempPath);
    
    $sql = "INSERT INTO mobile_temp_images (session_id, temp_path, file_name, file_size, status) 
            VALUES (?, ?, ?, ?, 'pending')";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$sessionId, $relativePath, $uniqueName, $fileSize]);
    
    $imageId = $pdo->lastInsertId();
    
    // Devolver información del archivo temporal
    echo json_encode([
        'success' => true,
        'message' => 'Imagen subida a carpeta temporal y registrada',
        'data' => [
            'id' => $imageId,
            'temp_path' => $relativePath,
            'file_name' => $uniqueName,
            'file_size' => $fileSize,
            'session_id' => $sessionId,
            'study_id' => $studyId,
            'uploaded_at' => date('Y-m-d H:i:s'),
            'status' => 'pending'
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

