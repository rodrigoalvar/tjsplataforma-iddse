<?php
/**
 * API para aceptar una imagen temporal y moverla a la carpeta permanente
 * También la registra en la base de datos
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
    
    $tempPath = $input['temp_path'] ?? null;
    $studyId = $input['study_id'] ?? null;
    $createdBy = $input['created_by'] ?? 1;
    
    if (!$tempPath || !$studyId) {
        throw new Exception('temp_path y study_id son requeridos');
    }
    
    // Generar nombre para archivo permanente
    $fileName = basename($tempPath);
    $permanentDir = '../uploads/antecedents/';
    $permanentPath = $permanentDir . $fileName;
    $fullTempPath = '../' . $tempPath;
    $relativePath = 'uploads/antecedents/' . $fileName;
    
    // Verificar estado de la imagen en mobile_temp_images
    $checkStatusSql = "SELECT status FROM mobile_temp_images WHERE temp_path = ? LIMIT 1";
    $checkStatusStmt = $pdo->prepare($checkStatusSql);
    $checkStatusStmt->execute([$tempPath]);
    $imageStatus = $checkStatusStmt->fetchColumn();
    
    // Si la imagen ya fue aceptada, verificar si el archivo está en permanente
    if ($imageStatus === 'accepted') {
        // Verificar si el archivo ya está en la carpeta permanente
        if (file_exists($permanentPath)) {
            // Verificar si ya está registrado en study_antecedents_files
            $checkFileSql = "SELECT id FROM study_antecedents_files WHERE file_path = ? LIMIT 1";
            $checkFileStmt = $pdo->prepare($checkFileSql);
            $checkFileStmt->execute([$relativePath]);
            $fileId = $checkFileStmt->fetchColumn();
            
            if ($fileId) {
                // Ya está procesada completamente, devolver éxito (idempotencia)
                echo json_encode([
                    'success' => true,
                    'message' => 'Imagen ya fue aceptada anteriormente',
                    'data' => [
                        'id' => $fileId,
                        'file_path' => $relativePath,
                        'file_name' => $fileName,
                        'study_id' => $studyId,
                        'created_by' => $createdBy,
                        'already_processed' => true
                    ]
                ]);
                exit;
            }
        }
    }
    
    // Si la imagen fue rechazada, no permitir aceptarla
    if ($imageStatus === 'rejected') {
        throw new Exception('La imagen fue rechazada y no puede ser aceptada');
    }
    
    // Verificar que el archivo temporal existe
    if (!file_exists($fullTempPath)) {
        // Si el archivo temporal no existe pero ya está en permanente, intentar recuperar
        if (file_exists($permanentPath)) {
            // El archivo ya fue movido, verificar si está registrado
            $checkFileSql = "SELECT id FROM study_antecedents_files WHERE file_path = ? LIMIT 1";
            $checkFileStmt = $pdo->prepare($checkFileSql);
            $checkFileStmt->execute([$relativePath]);
            $fileId = $checkFileStmt->fetchColumn();
            
            if ($fileId) {
                // Ya está registrado, solo actualizar status en mobile_temp_images
                $updateSQL = "UPDATE mobile_temp_images SET status = 'accepted' WHERE temp_path = ?";
                $updateStmt = $pdo->prepare($updateSQL);
                $updateStmt->execute([$tempPath]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Imagen ya procesada, estado actualizado',
                    'data' => [
                        'id' => $fileId,
                        'file_path' => $relativePath,
                        'file_name' => $fileName,
                        'study_id' => $studyId,
                        'created_by' => $createdBy,
                        'already_processed' => true
                    ]
                ]);
                exit;
            } else {
                throw new Exception('El archivo temporal no existe y no se encontró en la carpeta permanente');
            }
        } else {
            throw new Exception('El archivo temporal no existe');
        }
    }
    
    // Crear directorio permanente si no existe
    if (!file_exists($permanentDir)) {
        mkdir($permanentDir, 0777, true);
    }
    
    // Mover archivo de temporal a permanente
    if (!rename($fullTempPath, $permanentPath)) {
        throw new Exception('Error moviendo el archivo a la carpeta permanente');
    }
    
    // Obtener o crear antecedente
    $antecedentSql = "SELECT id FROM study_antecedents WHERE study_id = ?";
    $antecedentStmt = $pdo->prepare($antecedentSql);
    $antecedentStmt->execute([$studyId]);
    $antecedentId = $antecedentStmt->fetchColumn();
    
    if (!$antecedentId) {
        // Crear antecedente si no existe
        $createSql = "INSERT INTO study_antecedents (study_id, notes, created_by) VALUES (?, '', ?)";
        $createStmt = $pdo->prepare($createSql);
        $createStmt->execute([$studyId, $createdBy]);
        $antecedentId = $pdo->lastInsertId();
    }
    
    // Verificar si el archivo ya está registrado en study_antecedents_files
    $checkFileSql = "SELECT id FROM study_antecedents_files WHERE file_path = ? LIMIT 1";
    $checkFileStmt = $pdo->prepare($checkFileSql);
    $checkFileStmt->execute([$relativePath]);
    $fileId = $checkFileStmt->fetchColumn();
    
    // Si no existe, insertarlo
    if (!$fileId) {
        // Obtener información del archivo
        $fileSize = filesize($permanentPath);
        $mimeType = mime_content_type($permanentPath);
        
        // Insertar en study_antecedents_files
        $sql = "INSERT INTO study_antecedents_files 
                (antecedent_id, file_name, file_path, file_type, file_size, mime_type) 
                VALUES (?, ?, ?, 'camera_capture', ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$antecedentId, $fileName, $relativePath, $fileSize, $mimeType]);
        
        $fileId = $pdo->lastInsertId();
    }
    
    // Marcar imagen como aceptada en mobile_temp_images (si existe el registro)
    $updateSQL = "UPDATE mobile_temp_images SET status = 'accepted' WHERE temp_path = ?";
    $updateStmt = $pdo->prepare($updateSQL);
    $updateStmt->execute(['uploads/temp_mobile/' . $fileName]);
    
    // Devolver información del archivo permanente
    echo json_encode([
        'success' => true,
        'message' => 'Imagen aceptada y guardada permanentemente',
        'data' => [
            'id' => $fileId,
            'file_path' => $relativePath,
            'file_name' => $fileName,
            'study_id' => $studyId,
            'created_by' => $createdBy,
            'created_date' => date('Y-m-d H:i:s')
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

