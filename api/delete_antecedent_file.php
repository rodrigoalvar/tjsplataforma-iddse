<?php
/**
 * API para eliminar archivos de antecedentes (temporales o permanentes)
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

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
    // Verificar que sea POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido');
    }
    
    // Obtener datos del request
    $input = json_decode(file_get_contents('php://input'), true);
    
    $filePath = $input['file_path'] ?? null;
    
    if (!$filePath) {
        throw new Exception('file_path es requerido');
    }
    
    // Construir ruta completa
    $fullPath = '../' . $filePath;
    
    // Verificar que el archivo existe
    if (!file_exists($fullPath)) {
        // No es un error crítico, el archivo ya no existe
        echo json_encode([
            'success' => true,
            'message' => 'Archivo no encontrado (ya eliminado)',
            'file_path' => $filePath
        ]);
        exit;
    }
    
    // Eliminar archivo
    if (!unlink($fullPath)) {
        throw new Exception('Error eliminando el archivo del servidor');
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Archivo eliminado exitosamente',
        'file_path' => $filePath
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}








