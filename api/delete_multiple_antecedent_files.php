<?php
/**
 * API para eliminar múltiples archivos de antecedentes
 * Elimina tanto los archivos físicos como las referencias en la base de datos
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
    
    $files = $input['files'] ?? [];
    
    if (empty($files)) {
        throw new Exception('No se proporcionaron archivos para eliminar');
    }
    
    // Crear conexión a la base de datos
    $pdo = getDBConnection();
    
    $results = [];
    $totalFiles = count($files);
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($files as $file) {
        $fileId = $file['file_id'] ?? null;
        $filePath = $file['file_path'] ?? null;
        
        try {
            // Si tenemos file_id, obtener información del archivo
            if ($fileId) {
                $stmt = $pdo->prepare("SELECT file_path, file_name FROM study_antecedents_files WHERE id = ?");
                $stmt->execute([$fileId]);
                $fileInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($fileInfo) {
                    $filePath = $fileInfo['file_path'];
                    $fileName = $fileInfo['file_name'];
                } else {
                    throw new Exception('Archivo no encontrado en la base de datos');
                }
            } else if ($filePath) {
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
            
            // Determinar el resultado para este archivo
            $warnings = [];
            if ($dbDeleted && $fileDeleted) {
                $message = 'Eliminado exitosamente';
            } elseif ($dbDeleted && !$fileDeleted) {
                $message = 'Referencia eliminada';
                $warnings[] = 'Archivo físico no encontrado';
            } elseif (!$dbDeleted && $fileDeleted) {
                $message = 'Archivo físico eliminado';
                $warnings[] = 'Error eliminando referencia de BD';
            } else {
                throw new Exception('No se pudo eliminar');
            }
            
            $results[] = [
                'success' => true,
                'file_id' => $fileId,
                'file_path' => $filePath,
                'file_name' => $fileName ?? 'Desconocido',
                'message' => $message,
                'file_deleted' => $fileDeleted,
                'db_deleted' => $dbDeleted,
                'warnings' => $warnings
            ];
            
            $successCount++;
            
        } catch (Exception $e) {
            $results[] = [
                'success' => false,
                'file_id' => $fileId,
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ];
            $errorCount++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Procesados {$totalFiles} archivos: {$successCount} exitosos, {$errorCount} errores",
        'total_files' => $totalFiles,
        'success_count' => $successCount,
        'error_count' => $errorCount,
        'results' => $results
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>