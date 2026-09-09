<?php
/**
 * API Endpoint para obtener blob de audio desde backup_path
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Retorna el archivo de audio para recuperación o descarga
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
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
    
    $userId = (int)($userData['id'] ?? 0);
    $userLevel = strtolower(trim((string)($userData['nivel'] ?? 'user')));
    $userPermissions = $userData['permisos'] ?? [];
    if (is_string($userPermissions)) {
        $decoded = json_decode($userPermissions, true);
        $userPermissions = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($userPermissions)) {
        $userPermissions = [];
    }
    $hasGlobalAudioPermission = (
        $userLevel === 'root' ||
        in_array('all', $userPermissions, true) ||
        in_array('audios_ver_todos_workspace', $userPermissions, true)
    );
    
    // Obtener audio_id o backup_path
    $audioId = $_GET['audio_id'] ?? $_POST['audio_id'] ?? null;
    $backupPath = $_GET['backup_path'] ?? $_POST['backup_path'] ?? null;
    $download = isset($_GET['download']) ? filter_var($_GET['download'], FILTER_VALIDATE_BOOLEAN) : false;
    
    // Conectar a la base de datos
    $db = getDBConnection();
    
    // Si se proporciona audio_id, obtener backup_path
    if ($audioId && !$backupPath) {
        $audioId = intval($audioId); // Asegurar que es un entero
        if ($audioId <= 0) {
            throw new Exception('ID de audio inválido');
        }
        
        $query = "SELECT backup_path, ruta_archivo, nombre_archivo, nombre_original, usuario_id FROM audios_informe WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$audioId]);
        $audioData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$audioData) {
            http_response_code(404);
            throw new Exception('Audio no encontrado');
        }
        
        // Verificar permisos - usuario dueño, root o permiso global
        if (!$hasGlobalAudioPermission && (int)$audioData['usuario_id'] !== $userId) {
            http_response_code(403);
            throw new Exception('No tiene permiso para acceder a este audio');
        }
        
        $backupPath = $audioData['backup_path'] ?? $audioData['ruta_archivo'];
        $fileName = $audioData['nombre_original'] ?? $audioData['nombre_archivo'] ?? 'audio_' . $audioId;
        
        if (empty($backupPath)) {
            http_response_code(404);
            throw new Exception('Ruta de archivo no disponible para este audio');
        }
    } elseif (!$backupPath) {
        http_response_code(400);
        throw new Exception('Se requiere audio_id o backup_path');
    } else {
        $fileName = basename($backupPath);
    }
    
    // Construir ruta completa
    $fullPath = __DIR__ . '/../../' . ltrim($backupPath, '/');
    
    // Verificar que el archivo existe
    if (!file_exists($fullPath)) {
        http_response_code(404);
        throw new Exception('Archivo no encontrado: ' . $backupPath);
    }
    
    // Verificar permisos del archivo
    if (!is_readable($fullPath)) {
        http_response_code(403);
        throw new Exception('No se puede leer el archivo');
    }
    
    // Obtener tipo MIME
    $mimeType = mime_content_type($fullPath);
    if (!$mimeType) {
        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $mimeTypes = [
            'webm' => 'audio/webm',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'mp4' => 'audio/mp4'
        ];
        $mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';
    }
    
    // Establecer headers
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($fullPath));
    
    if ($download) {
        header('Content-Disposition: attachment; filename="' . basename($fileName) . '"');
    } else {
        header('Content-Disposition: inline; filename="' . basename($fileName) . '"');
    }
    
    // Leer y enviar archivo
    readfile($fullPath);
    exit();
    
} catch (Exception $e) {
    error_log('Error en api/audios/get-audio-blob.php: ' . $e->getMessage());
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
