<?php
/**
 * API para migrar audios temporales a permanentes y vincularlos con informes
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

require_once '../../classes/User.php';
require_once '../../config/database.php';

try {
    // Validar sesión
    $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? $_POST['session_token'] ?? null;
    if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
        $sessionToken = substr($sessionToken, 7);
    }
    
    // Si no hay token en header, intentar obtenerlo del body JSON
    if (!$sessionToken) {
        $input = json_decode(file_get_contents('php://input'), true);
        if ($input && isset($input['session_token'])) {
            $sessionToken = $input['session_token'];
        }
    }
    
    if (!$sessionToken) {
        throw new Exception('Token de sesión requerido');
    }
    
    $user = new User();
    $userData = $user->validateSession($sessionToken);
    
    if (!$userData) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sesión inválida']);
        exit();
    }
    
    // Obtener parámetros
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $studyInstanceUID = $input['studyInstanceUID'] ?? $input['study_instance_uid'] ?? null;
    $studyId = $input['studyId'] ?? $input['study_id'] ?? null;
    
    if (!$studyInstanceUID && !$studyId) {
        throw new Exception('studyInstanceUID o studyId requerido');
    }
    
    // Conectar a la base de datos
    $db = getDBConnection();
    
    // Buscar el informe más reciente para este estudio
    $query = "SELECT id, estudio_id FROM informes WHERE estudio_id = ? OR study_instance_uid = ? ORDER BY fecha_creacion DESC LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute([$studyInstanceUID, $studyInstanceUID]);
    $informe = $stmt->fetch();
    
    if (!$informe) {
        throw new Exception('No se encontró informe para este estudio');
    }
    
    // Directorios
    $tempDir = '../../uploads/temp_audios/';
    $permanentDir = '../../uploads/audios/';
    
    // Crear directorio permanente si no existe
    if (!is_dir($permanentDir)) {
        mkdir($permanentDir, 0755, true);
    }
    
    // Buscar archivos temporales para este estudio
    $tempFiles = glob($tempDir . '*_regular_*.wav');
    $migratedCount = 0;
    $migratedFiles = [];
    
    foreach ($tempFiles as $tempFile) {
        $fileName = basename($tempFile);
        
        // Verificar si el archivo pertenece a este estudio
        // El archivo temporal contiene el studyId, no el studyInstanceUID
        $belongsToStudy = false;
        
        if ($studyId && strpos($fileName, $studyId) !== false) {
            $belongsToStudy = true;
        } elseif ($studyInstanceUID && strpos($fileName, $studyInstanceUID) !== false) {
            $belongsToStudy = true;
        }
        
        if ($belongsToStudy) {
            // Generar nombre único para el archivo permanente
            $extension = pathinfo($fileName, PATHINFO_EXTENSION);
            $newFileName = 'audio_' . $informe['id'] . '_' . time() . '_' . uniqid() . '.' . $extension;
            $permanentPath = $permanentDir . $newFileName;
            
            // Mover archivo
            if (copy($tempFile, $permanentPath)) {
                // Insertar en base de datos
                $insertQuery = "INSERT INTO audios_informe (
                                informe_id, estudio_id, usuario_id, nombre_archivo, ruta_archivo, nombre_original,
                                tipo_mime, tamano_bytes, tipo_grabacion, fecha_creacion
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                
                $insertStmt = $db->prepare($insertQuery);
                $result = $insertStmt->execute([
                    $informe['id'],
                    $informe['estudio_id'],
                    $userData['id'],
                    $newFileName,
                    'uploads/audios/' . $newFileName,
                    $fileName,
                    'audio/wav',
                    filesize($permanentPath),
                    'simple'
                ]);
                
                if ($result) {
                    $audioId = $db->lastInsertId();
                    $migratedCount++;
                    $migratedFiles[] = [
                        'id' => $audioId,
                        'original_name' => $fileName,
                        'new_name' => $newFileName,
                        'size' => filesize($permanentPath)
                    ];
                    
                    // Eliminar archivo temporal
                    unlink($tempFile);
                    
                    // Eliminar metadata si existe
                    $metadataFile = str_replace('.wav', '_metadata.json', $tempFile);
                    if (file_exists($metadataFile)) {
                        unlink($metadataFile);
                    }
                } else {
                    // Si falla la inserción, eliminar el archivo copiado
                    unlink($permanentPath);
                }
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Migración completada: $migratedCount audios migrados",
        'data' => [
            'informe_id' => $informe['id'],
            'estudio_id' => $informe['estudio_id'],
            'migrated_count' => $migratedCount,
            'migrated_files' => $migratedFiles
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
