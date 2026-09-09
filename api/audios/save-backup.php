<?php
/**
 * API Endpoint para guardar respaldo automático de audios
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Solo permitir POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

require_once '../../classes/User.php';
require_once '../../config/database.php';

try {
    // Validar sesión
    $sessionToken = null;
    if (isset($_COOKIE["session_token"]) && !empty($_COOKIE["session_token"])) {
        $sessionToken = trim($_COOKIE["session_token"]);
    } elseif (isset($_COOKIE["sessionToken"]) && !empty($_COOKIE["sessionToken"])) {
        $sessionToken = trim($_COOKIE["sessionToken"]);
    } elseif (isset($_POST["session_token"]) && !empty($_POST["session_token"])) {
        $sessionToken = trim($_POST["session_token"]);
    } elseif (isset($_GET["session_token"]) && !empty($_GET["session_token"])) {
        $sessionToken = trim($_GET["session_token"]);
    } elseif (isset($_SERVER["HTTP_AUTHORIZATION"])) {
        $auth = $_SERVER["HTTP_AUTHORIZATION"];
        $sessionToken = strpos($auth, "Bearer ") === 0 ? trim(substr($auth, 7)) : trim($auth);
    }
    
    if (!$sessionToken) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Token de sesión requerido']);
        exit();
    }
    
    $user = new User();
    $userData = $user->validateSession($sessionToken);
    
    if (!$userData) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sesión inválida']);
        exit();
    }
    
    // Obtener datos del audio
    if (!isset($_FILES['audio'])) {
        throw new Exception('No se recibió el archivo de audio');
    }
    
    $audioFile = $_FILES['audio'];
    $studyId = $_POST['study_id'] ?? null;
    $patientId = $_POST['patient_id'] ?? null;
    $recordingId = $_POST['recording_id'] ?? null; // ID temporal del workspace
    
    // Crear directorio por usuario: uploads/audio_backups/{user_id}_{username}/
    $userId = $userData['id'];
    $username = $userData['username'] ?? $userData['email'] ?? 'usuario';
    $username = preg_replace('/[^a-zA-Z0-9_-]/', '_', $username);
    
    $backupBaseDir = __DIR__ . '/../../uploads/audio_backups/';
    if (!is_dir($backupBaseDir)) {
        if (!mkdir($backupBaseDir, 0777, true)) {
            $error = error_get_last();
            throw new Exception('No se pudo crear el directorio base de respaldos: ' . ($error['message'] ?? 'Error desconocido'));
        }
    }
    
    // Asegurar permisos de escritura para el servidor web (intentar, pero no fallar si no se puede)
    @chmod($backupBaseDir, 0777);
    if (!is_writable($backupBaseDir)) {
        error_log('⚠️ El directorio base de respaldos no es escribible: ' . $backupBaseDir);
        throw new Exception('El directorio de respaldos no tiene permisos de escritura. Contacte al administrador.');
    }
    
    $userBackupDir = $backupBaseDir . $userId . '_' . $username . '/';
    if (!is_dir($userBackupDir)) {
        if (!mkdir($userBackupDir, 0777, true)) {
            $error = error_get_last();
            error_log('Error creando directorio de usuario: ' . $userBackupDir . ' - Error: ' . ($error['message'] ?? 'Desconocido'));
            throw new Exception('No se pudo crear el directorio de respaldos del usuario. Verifique permisos del servidor.');
        }
    }
    
    // Asegurar permisos de escritura (intentar, pero no fallar si no se puede)
    @chmod($userBackupDir, 0777);
    if (!is_writable($userBackupDir)) {
        error_log('⚠️ El directorio de usuario no es escribible: ' . $userBackupDir);
        throw new Exception('El directorio de respaldos del usuario no tiene permisos de escritura. Contacte al administrador.');
    }
    
    // Generar nombre de archivo: {username}_{fecha}_{hora}_{recording_id}.{ext}
    $timestamp = date('Y-m-d_H-i-s');
    $extension = strtolower(pathinfo($audioFile['name'], PATHINFO_EXTENSION)) ?: 'webm';
    $fileName = $username . '_' . $timestamp . '_' . ($recordingId ?? uniqid()) . '.' . $extension;
    $filePath = $userBackupDir . $fileName;
    
    // Mover archivo
    if (!move_uploaded_file($audioFile['tmp_name'], $filePath)) {
        throw new Exception('Error al guardar el archivo de respaldo');
    }
    
    // Guardar metadatos en JSON
    $metadataFile = $userBackupDir . 'metadata.json';
    $metadata = [];
    if (file_exists($metadataFile)) {
        $metadata = json_decode(file_get_contents($metadataFile), true) ?: [];
    }
    
    $metadata[] = [
        'fileName' => $fileName,
        'recordingId' => $recordingId,
        'studyId' => $studyId,
        'patientId' => $patientId,
        'timestamp' => $timestamp,
        'savedAt' => date('Y-m-d H:i:s'),
        'size' => filesize($filePath),
        'sentToFtp' => false,
        'sentToTranscription' => false
    ];
    
    file_put_contents($metadataFile, json_encode($metadata, JSON_PRETTY_PRINT));
    
    // Devolver ruta relativa desde la raíz del proyecto (no absoluta)
    $relativePath = 'uploads/audio_backups/' . $userId . '_' . $username . '/' . $fileName;
    
    echo json_encode([
        'success' => true,
        'message' => 'Audio guardado como respaldo',
        'data' => [
            'fileName' => $fileName,
            'filePath' => $relativePath // Ruta relativa para usar en backup_path
        ]
    ]);
    
} catch (Exception $e) {
    error_log('Error en api/audios/save-backup.php: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
