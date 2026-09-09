<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Directorio para archivos temporales de audio
$tempDir = __DIR__ . '/../uploads/temp_audios/';
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0755, true);
}

// Manejar descarga de archivos primero
if (isset($_GET['action']) && $_GET['action'] === 'download' && isset($_GET['file'])) {
    $fileName = $_GET['file'];
    $filePath = $tempDir . $fileName;
    
    if (file_exists($filePath) && strpos($fileName, '..') === false) {
        header('Content-Type: audio/wav');
        header('Content-Disposition: inline; filename="' . $fileName . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Archivo no encontrado']);
        exit;
    }
}

$response = ['success' => false, 'message' => ''];

try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($method) {
        case 'POST':
            // Guardar archivo de audio temporal
            if (!isset($_POST['studyId']) || !isset($_POST['patientId'])) {
                throw new Exception('studyId y patientId son requeridos');
            }
            
            if (!isset($_FILES['audio'])) {
                throw new Exception('No se recibió el archivo de audio');
            }
            
            // Verificar errores de subida
            if ($_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
                $errorMessages = [
                    UPLOAD_ERR_INI_SIZE => 'El archivo excede el tamaño máximo permitido',
                    UPLOAD_ERR_FORM_SIZE => 'El archivo excede el tamaño máximo del formulario',
                    UPLOAD_ERR_PARTIAL => 'El archivo se subió parcialmente',
                    UPLOAD_ERR_NO_FILE => 'No se subió ningún archivo',
                    UPLOAD_ERR_NO_TMP_DIR => 'Falta el directorio temporal',
                    UPLOAD_ERR_CANT_WRITE => 'Error al escribir el archivo en disco',
                    UPLOAD_ERR_EXTENSION => 'Una extensión PHP detuvo la subida del archivo'
                ];
                $errorMsg = $errorMessages[$_FILES['audio']['error']] ?? 'Error desconocido al subir el archivo';
                throw new Exception($errorMsg . ' (código: ' . $_FILES['audio']['error'] . ')');
            }
            
            // Verificar que el directorio existe y es escribible
            if (!is_dir($tempDir)) {
                if (!mkdir($tempDir, 0755, true)) {
                    throw new Exception('No se pudo crear el directorio de archivos temporales');
                }
            }
            
            if (!is_writable($tempDir)) {
                throw new Exception('El directorio de archivos temporales no tiene permisos de escritura');
            }
            
            $studyId = $_POST['studyId'];
            $patientId = $_POST['patientId'];
            $audioType = $_POST['audioType'] ?? 'regular'; // 'regular' o 'sync'
            $timestamp = time();
            
            // Determinar extensión correcta según el tipo MIME real del archivo
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $_FILES['audio']['tmp_name']);
            finfo_close($finfo);
            
            // Mapear tipo MIME a extensión
            $extensionMap = [
                'audio/webm' => 'webm',
                'audio/x-webm' => 'webm',
                'audio/mp4' => 'mp4',
                'audio/x-m4a' => 'm4a',
                'audio/m4a' => 'm4a',
                'audio/ogg' => 'ogg',
                'audio/vorbis' => 'ogg',
                'audio/wav' => 'wav',
                'audio/x-wav' => 'wav',
                'audio/wave' => 'wav',
                'audio/mp3' => 'mp3',
                'audio/mpeg' => 'mp3',
                'audio/flac' => 'flac'
            ];
            
            $extension = $extensionMap[$mimeType] ?? 'webm'; // Por defecto webm (más común)
            
            // También verificar por extensión del nombre del archivo como fallback
            $uploadedFileName = $_FILES['audio']['name'] ?? '';
            $uploadedExtension = strtolower(pathinfo($uploadedFileName, PATHINFO_EXTENSION));
            if ($uploadedExtension && in_array($uploadedExtension, ['webm', 'mp4', 'm4a', 'ogg', 'wav', 'mp3', 'flac'])) {
                $extension = $uploadedExtension;
            }
            
            // Generar nombre único para el archivo con la extensión correcta
            $fileName = "{$studyId}_{$patientId}_{$audioType}_{$timestamp}.{$extension}";
            $filePath = $tempDir . $fileName;
            
            // Intentar mover el archivo
            if (!move_uploaded_file($_FILES['audio']['tmp_name'], $filePath)) {
                $lastError = error_get_last();
                $errorDetails = $lastError ? $lastError['message'] : 'Error desconocido';
                throw new Exception('Error al mover el archivo: ' . $errorDetails . '. Verifique permisos del directorio: ' . $tempDir);
            }
            
            // Verificar que el archivo se movió correctamente
            if (!file_exists($filePath)) {
                throw new Exception('El archivo no se guardó correctamente');
            }
            
            // Guardar metadatos en archivo JSON
            $metadataFile = $tempDir . "{$studyId}_{$patientId}_metadata.json";
            $metadata = [];
            
            if (file_exists($metadataFile)) {
                $metadata = json_decode(file_get_contents($metadataFile), true) ?: [];
            }
            
            $metadata[] = [
                'fileName' => $fileName,
                'audioType' => $audioType,
                'timestamp' => $timestamp,
                'size' => filesize($filePath),
                'created' => date('Y-m-d H:i:s')
            ];
            
            if (file_put_contents($metadataFile, json_encode($metadata, JSON_PRETTY_PRINT)) === false) {
                throw new Exception('Error al guardar los metadatos del archivo');
            }
            
            $response['success'] = true;
            $response['message'] = 'Audio guardado correctamente';
            $response['data'] = [
                'fileName' => $fileName,
                'audioType' => $audioType,
                'timestamp' => $timestamp
            ];
            break;
            
        case 'GET':
            // Recuperar archivos de audio temporales
            if (!isset($_GET['studyId']) || !isset($_GET['patientId'])) {
                throw new Exception('studyId y patientId son requeridos');
            }
            
            $studyId = $_GET['studyId'];
            $patientId = $_GET['patientId'];
            $metadataFile = $tempDir . "{$studyId}_{$patientId}_metadata.json";
            
            if (file_exists($metadataFile)) {
                $metadata = json_decode(file_get_contents($metadataFile), true) ?: [];
                
                // Verificar que los archivos aún existen
                $validFiles = [];
                foreach ($metadata as $file) {
                    $filePath = $tempDir . $file['fileName'];
                    if (file_exists($filePath)) {
                        $file['url'] = 'temp-audio.php?action=download&file=' . urlencode($file['fileName']);
                        $validFiles[] = $file;
                    }
                }
                
                // Actualizar metadata si algunos archivos no existen
                if (count($validFiles) !== count($metadata)) {
                    file_put_contents($metadataFile, json_encode($validFiles, JSON_PRETTY_PRINT));
                }
                
                $response['success'] = true;
                $response['data'] = $validFiles;
            } else {
                $response['success'] = true;
                $response['data'] = [];
            }
            break;
            
        case 'DELETE':
            // Eliminar archivos de audio temporales
            if (!isset($_GET['studyId']) || !isset($_GET['patientId'])) {
                throw new Exception('studyId y patientId son requeridos');
            }
            
            $studyId = $_GET['studyId'];
            $patientId = $_GET['patientId'];
            $metadataFile = $tempDir . "{$studyId}_{$patientId}_metadata.json";
            
            // Si se especifica un archivo específico, eliminar solo ese
            if (isset($_GET['fileName'])) {
                $fileName = $_GET['fileName'];
                $filePath = $tempDir . $fileName;
                
                // Eliminar archivo físico
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
                
                // Actualizar metadata
                if (file_exists($metadataFile)) {
                    $metadata = json_decode(file_get_contents($metadataFile), true) ?: [];
                    $metadata = array_filter($metadata, function($file) use ($fileName) {
                        return $file['fileName'] !== $fileName;
                    });
                    
                    if (empty($metadata)) {
                        unlink($metadataFile);
                    } else {
                        file_put_contents($metadataFile, json_encode(array_values($metadata), JSON_PRETTY_PRINT));
                    }
                }
                
                $response['success'] = true;
                $response['message'] = 'Archivo de audio eliminado correctamente';
            } else {
                // Eliminar todos los archivos del estudio
                if (file_exists($metadataFile)) {
                    $metadata = json_decode(file_get_contents($metadataFile), true) ?: [];
                    
                    // Eliminar archivos físicos
                    foreach ($metadata as $file) {
                        $filePath = $tempDir . $file['fileName'];
                        if (file_exists($filePath)) {
                            unlink($filePath);
                        }
                    }
                    
                    // Eliminar archivo de metadata
                    unlink($metadataFile);
                }
                
                $response['success'] = true;
                $response['message'] = 'Archivos de audio eliminados correctamente';
            }
            break;
            
        default:
            throw new Exception('Método no permitido');
    }
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);
?>