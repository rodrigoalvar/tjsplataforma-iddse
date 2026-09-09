<?php
/**
 * API para subida de archivos de antecedentes médicos
 * Maneja la subida de imágenes, documentos y capturas de cámara
 */

// Configurar manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Iniciar buffer de salida para capturar cualquier output no deseado
ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Solo permitir método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

// Incluir configuración de base de datos
require_once '../config/database.php';

try {
    $studyId = $_POST['study_id'] ?? null;
    $fileType = $_POST['file_type'] ?? 'image'; // image, document, camera_capture
    $createdBy = $_POST['created_by'] ?? null;
    
    if (!$studyId || !$createdBy) {
        throw new Exception('study_id y created_by son requeridos');
    }
    
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No se recibió archivo válido');
    }
    
    $file = $_FILES['file'];
    
    // Validar tipo de archivo
    $allowedTypes = [
        'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'image_upload' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'file_upload' => [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'application/pdf', 'application/msword', 
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 
            'text/plain', 'application/zip', 'application/x-rar-compressed'
        ],
        'camera_capture' => ['image/jpeg', 'image/png'],
        'mobile_capture' => ['image/jpeg', 'image/png'],
        'document' => ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/plain']
    ];
    
    if (!isset($allowedTypes[$fileType]) || !in_array($file['type'], $allowedTypes[$fileType])) {
        throw new Exception('Tipo de archivo no permitido: ' . $file['type'] . ' para tipo: ' . $fileType);
    }
    
    // Validar tamaño (máximo 10MB)
    if ($file['size'] > 10 * 1024 * 1024) {
        throw new Exception('El archivo es demasiado grande (máximo 10MB)');
    }
    
    // Crear directorio de uploads si no existe
    $uploadDir = '../uploads/antecedents/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Generar nombre único para el archivo
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fileName = uniqid() . '_' . time() . '.' . $extension;
    $filePath = $uploadDir . $fileName;
    
    // Mover archivo subido (compatible con pruebas y subidas reales)
    if (is_uploaded_file($file['tmp_name'])) {
        // Archivo realmente subido via HTTP
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            throw new Exception('Error al guardar el archivo subido');
        }
    } else {
        // Archivo de prueba o simulado
        if (!copy($file['tmp_name'], $filePath)) {
            throw new Exception('Error al copiar el archivo de prueba');
        }
    }
    
    // Guardar información en base de datos
    $pdo = getDBConnection();
    
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
    
    // Insertar información del archivo
    $fileSql = "INSERT INTO study_antecedents_files 
                (antecedent_id, file_name, file_path, file_type, file_size, mime_type) 
                VALUES (?, ?, ?, ?, ?, ?)";
    
    $fileStmt = $pdo->prepare($fileSql);
    $fileStmt->execute([
        $antecedentId,
        $file['name'],
        $filePath,
        $fileType,
        $file['size'],
        $file['type']
    ]);
    
    $fileId = $pdo->lastInsertId();
    
    // Limpiar buffer de salida antes de enviar JSON
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Archivo subido exitosamente',
        'data' => [
            'file_id' => $fileId,
            'file_name' => $file['name'],
            'file_path' => $filePath,
            'file_type' => $fileType,
            'file_size' => $file['size'],
            'mime_type' => $file['type']
        ]
    ]);
    
} catch (Exception $e) {
    // Limpiar buffer de salida antes de enviar JSON de error
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
