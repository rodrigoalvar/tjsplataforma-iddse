<?php
/**
 * API Endpoint para eliminar permanentemente audios de la papelera
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * Solo usuarios ROOT pueden eliminar permanentemente
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
require_once '../../middleware/permissions.php';

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
    
    // Solo ROOT puede eliminar permanentemente
    $isRoot = ($userData['nivel'] ?? 'user') === 'root';
    $permissionManager = new PermissionManager();
    $hasDeletePermission = $permissionManager->hasPermission('papelera_audios_delete', $userData['id']);
    
    if (!$isRoot && !$hasDeletePermission) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Solo usuarios ROOT pueden eliminar permanentemente audios']);
        exit();
    }
    
    // Obtener datos del request
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    // Validar datos requeridos
    $audioIds = $input['audio_ids'] ?? null;
    
    if (!$audioIds || !is_array($audioIds) || empty($audioIds)) {
        throw new Exception('audio_ids es requerido y debe ser un array no vacío');
    }
    
    // Conectar a la base de datos
    $db = getDBConnection();
    
    // Preparar placeholders para la consulta IN
    $placeholders = str_repeat('?,', count($audioIds) - 1) . '?';
    
    // Obtener información de los audios antes de eliminar (para logging)
    $query = "SELECT id, nombre_archivo, backup_path, ruta_archivo, usuario_id, estudio_id 
              FROM audios_informe 
              WHERE id IN ($placeholders)";
    
    $stmt = $db->prepare($query);
    $stmt->execute($audioIds);
    $audios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($audios) !== count($audioIds)) {
        throw new Exception('Algunos audios no fueron encontrados');
    }
    
    $deletedFiles = [];
    $errors = [];
    
    foreach ($audios as $audio) {
        try {
            // Eliminar archivo físico si existe
            $backupPath = $audio['backup_path'] ?? $audio['ruta_archivo'];
            if ($backupPath) {
                $fullBackupPath = __DIR__ . '/../../' . ltrim($backupPath, '/');
                if (file_exists($fullBackupPath)) {
                    if (@unlink($fullBackupPath)) {
                        $deletedFiles[] = $backupPath;
                    } else {
                        $errors[] = "No se pudo eliminar archivo: {$backupPath}";
                    }
                }
            }
            
            // Eliminar de la base de datos (CASCADE eliminará registros relacionados)
            $deleteQuery = "DELETE FROM audios_informe WHERE id = ?";
            $deleteStmt = $db->prepare($deleteQuery);
            $deleteStmt->execute([$audio['id']]);
            
        } catch (Exception $e) {
            $errors[] = "Audio ID {$audio['id']}: " . $e->getMessage();
        }
    }
    
    if (empty($deletedFiles) && !empty($errors)) {
        throw new Exception('No se pudo eliminar ningún audio: ' . implode('; ', $errors));
    }
    
    // Log de eliminación permanente (opcional, si existe tabla de logs)
    try {
        $tablesCheck = $db->query("SHOW TABLES LIKE 'audios_estado_log'");
        if ($tablesCheck->rowCount() > 0) {
            foreach ($audios as $audio) {
                $logQuery = "INSERT INTO audios_estado_log (
                    audio_id, estado_anterior, estado_nuevo, accion, usuario_id, estudio_id, metadata
                ) VALUES (?, ?, ?, ?, ?, ?, ?)";
                
                $logStmt = $db->prepare($logQuery);
                $logStmt->execute([
                    $audio['id'],
                    'eliminado',
                    null, // Ya no existe
                    'eliminar_permanentemente',
                    $userData['id'],
                    $audio['estudio_id'],
                    json_encode(['deleted_by' => $userData['id'], 'deleted_at' => date('Y-m-d H:i:s')])
                ]);
            }
        }
    } catch (Exception $e) {
        error_log('Error creando log de eliminación permanente: ' . $e->getMessage());
        // No fallar si el log falla
    }
    
    echo json_encode([
        'success' => true,
        'message' => count($deletedFiles) . ' audio(s) eliminado(s) permanentemente',
        'data' => [
            'deleted_count' => count($deletedFiles),
            'deleted_files' => $deletedFiles,
            'errors' => $errors
        ]
    ]);
    
} catch (Exception $e) {
    error_log('Error en api/audios/delete-permanent.php: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
