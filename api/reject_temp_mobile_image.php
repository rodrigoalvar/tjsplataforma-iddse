<?php
/**
 * API para marcar una imagen temporal como rechazada
 * Esto evita que el polling la vuelva a traer
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
    
    // Obtener datos del request
    $input = json_decode(file_get_contents('php://input'), true);
    
    $imageId = $input['image_id'] ?? null;
    $tempPath = $input['temp_path'] ?? null;
    
    if (!$imageId && !$tempPath) {
        throw new Exception('image_id o temp_path son requeridos');
    }
    
    // Marcar como rechazada en la base de datos
    if ($imageId) {
        $sql = "UPDATE mobile_temp_images SET status = 'rejected' WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$imageId]);
    } elseif ($tempPath) {
        $sql = "UPDATE mobile_temp_images SET status = 'rejected' WHERE temp_path = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tempPath]);
    }
    
    $rowsAffected = $stmt->rowCount();
    
    echo json_encode([
        'success' => true,
        'message' => 'Imagen marcada como rechazada',
        'rows_affected' => $rowsAffected
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}








