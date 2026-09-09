<?php
/**
 * API para eliminar archivos de antecedentes completamente
 * Elimina tanto el archivo físico como la referencia en la base de datos
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Incluir configuración de base de datos
require_once '../config/database.php';

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
    
    $fileId = $input['file_id'] ?? null;
    $filePath = $input['file_path'] ?? null;
    
    if (!$fileId && !$filePath) {
        throw new Exception('file_id o file_path es requerido');
    }
    
    // Crear conexión a la base de datos
    $pdo = getDBConnection();
    
    // Si tenemos file_id, obtener información del archivo
    if ($fileId) {
        $stmt = $pdo->prepare("SELECT file_path, file_name FROM study_antecedents_files WHERE id = ?");
        $stmt->execute([$fileId]);
        $fileInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$fileInfo) {
            throw new Exception('Archivo no encontrado en la base de datos');
        }
        
        $filePath = $fileInfo['file_path'];
        $fileName = $fileInfo['file_name'];
    } else {
        // Si solo tenemos file_path, obtener el ID
        $stmt = $pdo->prepare("SELECT id, file_name FROM study_antecedents_files WHERE file_path = ?");
        $stmt->execute([$filePath]);
        $fileInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($fileInfo) {
            $fileId = $fileInfo['id'];
            $fileName = $fileInfo['file_name'];
        }
    }
    
    $fileDeleted = false;
    $dbDeleted = false;
    
    // Intentar eliminar archivo físico
    if ($filePath) {
        // Intentar diferentes rutas posibles
        $possiblePaths = [
            $filePath,                          // Ruta tal como está en BD
            '../' . $filePath,                  // Con ../ al inicio
            'uploads/' . basename($filePath),   // Solo en uploads/
            'uploads/antecedents/' . basename($filePath), // En uploads/antecedents/
        ];
        
        foreach ($possiblePaths as $testPath) {
            if (file_exists($testPath)) {
                if (unlink($testPath)) {
                    $fileDeleted = true;
                    break;
                }
            }
        }
    }
    
    // Eliminar referencia de la base de datos
    if ($fileId) {
        $stmt = $pdo->prepare("DELETE FROM study_antecedents_files WHERE id = ?");
        $dbDeleted = $stmt->execute([$fileId]);
    }
    
    // Determinar el resultado
    $message = '';
    $warnings = [];
    
    if ($dbDeleted && $fileDeleted) {
        $message = 'Archivo y referencia eliminados exitosamente';
    } elseif ($dbDeleted && !$fileDeleted) {
        $message = 'Referencia eliminada de la base de datos';
        $warnings[] = 'El archivo físico no se encontró o ya había sido eliminado';
    } elseif (!$dbDeleted && $fileDeleted) {
        $message = 'Archivo físico eliminado';
        $warnings[] = 'No se pudo eliminar la referencia de la base de datos';
    } else {
        throw new Exception('No se pudo eliminar ni el archivo ni la referencia');
    }
    
    echo json_encode([
        'success' => true,
        'message' => $message,
        'file_id' => $fileId,
        'file_path' => $filePath,
        'file_name' => $fileName ?? 'Desconocido',
        'file_deleted' => $fileDeleted,
        'db_deleted' => $dbDeleted,
        'warnings' => $warnings
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>