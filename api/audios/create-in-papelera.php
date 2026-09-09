<?php
/**
 * API Endpoint para crear registro de audio en papelera
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Este endpoint crea un registro en audios_informe con estado 'en_papelera'
 * cuando se guarda un audio automáticamente al detener la grabación.
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
    
    // Obtener datos del request
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    // Validar datos requeridos
    $studyId = $input['study_id'] ?? null;
    $backupPath = $input['backup_path'] ?? null;
    $localFileName = $input['local_file_name'] ?? null;
    $workspacePanelId = $input['workspace_panel_id'] ?? null;
    $recordingId = $input['recording_id'] ?? null;
    $duration = $input['duration'] ?? null;
    $size = $input['size'] ?? null;
    $mimeType = $input['mime_type'] ?? 'audio/webm';
    
    if (!$studyId) {
        throw new Exception('study_id es requerido');
    }
    
    if (!$backupPath) {
        throw new Exception('backup_path es requerido');
    }
    
    // Conectar a la base de datos
    $db = getDBConnection();
    
    // Generar nombre de archivo único
    $username = $userData['username'] ?? $userData['email'] ?? 'usuario';
    $username = preg_replace('/[^a-zA-Z0-9_-]/', '_', $username);
    $timestamp = date('Y-m-d_H-i-s');
    $extension = pathinfo($backupPath, PATHINFO_EXTENSION) ?: 'webm';
    $fileName = $username . '_' . $timestamp . '_' . ($recordingId ?? uniqid()) . '.' . $extension;
    
    // Obtener información del estudio si está disponible
    $patientId = null;
    $patientName = null;
    $studyDescription = null;
    
    try {
        $studyStmt = $db->prepare("SELECT patient_id, patient_name, study_description FROM estudios WHERE orthanc_study_id = ? OR id = ? LIMIT 1");
        $studyStmt->execute([$studyId, $studyId]);
        $studyData = $studyStmt->fetch(PDO::FETCH_ASSOC);
        if ($studyData) {
            $patientId = $studyData['patient_id'];
            $patientName = $studyData['patient_name'];
            $studyDescription = $studyData['study_description'];
        }
    } catch (Exception $e) {
        error_log('Error obteniendo datos del estudio: ' . $e->getMessage());
    }
    
    // Crear registro en audios_informe con estado 'en_papelera'
    $insertQuery = "INSERT INTO audios_informe (
        informe_id, estudio_id, usuario_id, nombre_archivo, ruta_archivo, nombre_original,
        tipo_mime, tamano_bytes, duracion_segundos, tipo_grabacion,
        backup_path, local_saved, local_file_name, workspace_panel_id, recording_id,
        estado
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $insertStmt = $db->prepare($insertQuery);
    $insertStmt->execute([
        null, // informe_id (NULL porque está en papelera)
        $studyId,
        $userData['id'],
        $fileName,
        $backupPath, // Usar backup_path como ruta_archivo inicialmente
        $localFileName ?? $fileName,
        $mimeType,
        $size,
        $duration,
        'simple', // tipo_grabacion
        $backupPath,
        !empty($localFileName), // local_saved
        $localFileName,
        $workspacePanelId,
        $recordingId,
        'en_papelera' // estado inicial
    ]);
    
    $audioId = $db->lastInsertId();
    
    // Crear entrada en audios_estado_log
    try {
        $logQuery = "INSERT INTO audios_estado_log (
            audio_id, estado_anterior, estado_nuevo, accion, usuario_id, estudio_id, workspace_panel_id, metadata
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $logStmt = $db->prepare($logQuery);
        $logStmt->execute([
            $audioId,
            null, // estado_anterior (es creación)
            'en_papelera',
            'crear',
            $userData['id'],
            $studyId,
            $workspacePanelId,
            json_encode([
                'backup_path' => $backupPath,
                'local_file_name' => $localFileName,
                'recording_id' => $recordingId,
                'workspace_panel_id' => $workspacePanelId
            ])
        ]);
    } catch (Exception $e) {
        error_log('Error creando log de estado (continuando): ' . $e->getMessage());
        // No fallar si el log falla
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Audio creado en papelera',
        'data' => [
            'audio_id' => $audioId,
            'fileName' => $fileName,
            'backup_path' => $backupPath,
            'estado' => 'en_papelera'
        ]
    ]);
    
} catch (Exception $e) {
    error_log('Error en api/audios/create-in-papelera.php: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
