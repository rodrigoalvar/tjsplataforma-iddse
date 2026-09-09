<?php
/**
 * API Endpoint para recuperar audios desde papelera a workspace
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
    
    // Obtener datos del request
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    // Validar datos requeridos
    $audioIds = $input['audio_ids'] ?? null;
    $workspacePanelId = $input['workspace_panel_id'] ?? null;
    $newStudyId = $input['new_study_id'] ?? null;
    $changeStudyId = isset($input['change_study_id']) && $input['change_study_id'] === true;
    
    if (!$audioIds || !is_array($audioIds) || empty($audioIds)) {
        throw new Exception('audio_ids es requerido y debe ser un array no vacío');
    }
    
    // Si se solicita cambiar estudio_id, validar que se proporcione el nuevo estudio_id
    if ($changeStudyId && !$newStudyId) {
        throw new Exception('new_study_id es requerido cuando change_study_id es true');
    }
    
    // Conectar a la base de datos
    $db = getDBConnection();
    
    // Preparar placeholders para la consulta IN
    $placeholders = str_repeat('?,', count($audioIds) - 1) . '?';
    
    // Obtener audios y verificar permisos (incluir campos de FTP/transcripción y autor)
    $query = "SELECT ai.id, ai.informe_id, ai.estado, ai.estudio_id, ai.usuario_id, ai.backup_path, ai.ruta_archivo, ai.workspace_panel_id, ai.recording_id,
                     ai.fecha_envio_ftp, ai.fecha_envio_transcripcion, u.nombre AS autor_nombre, u.apellido AS autor_apellido, u.email AS autor_email
              FROM audios_informe AS ai
              LEFT JOIN usuarios AS u ON ai.usuario_id = u.id
              WHERE ai.id IN ($placeholders)" . ($hasGlobalAudioPermission ? "" : " AND ai.usuario_id = ?");
    
    $params = $hasGlobalAudioPermission ? $audioIds : array_merge($audioIds, [$userId]);
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $audios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($audios) !== count($audioIds)) {
        throw new Exception('Algunos audios no fueron encontrados o no tiene permiso para accederlos');
    }
    
    $recoveredAudios = [];
    $errors = [];
    
    foreach ($audios as $audio) {
        try {
            // Verificar que el archivo existe
            $backupPath = $audio['backup_path'] ?? $audio['ruta_archivo'];
            $fullBackupPath = __DIR__ . '/../../' . ltrim($backupPath, '/');
            
            if (!file_exists($fullBackupPath)) {
                $errors[] = "Audio ID {$audio['id']}: Archivo no encontrado en {$backupPath}";
                continue;
            }
            
            // Determinar el nuevo estado según el estado actual
            $estadoAnterior = $audio['estado'];
            $nuevoEstado = $estadoAnterior; // Por defecto, preservar el estado
            
            // Solo cambiar estado si estaba 'eliminado' o es NULL
            if ($estadoAnterior === 'eliminado' || $estadoAnterior === null) {
                $nuevoEstado = 'en_papelera';
            }
            // Si es 'guardado_informe', mantener ese estado (no cambiar)
            
            // Determinar si se debe cambiar el estudio_id
            $estudioIdAnterior = $audio['estudio_id'];
            $nuevoEstudioId = $changeStudyId && $newStudyId ? $newStudyId : $estudioIdAnterior;
            $estudioIdCambio = $nuevoEstudioId !== $estudioIdAnterior;
            
            // Actualizar solo si el estado cambió o si se debe cambiar el estudio_id
            if ($nuevoEstado !== $estadoAnterior || $estudioIdCambio) {
                $updateFields = [];
                $updateValues = [];
                
                if ($nuevoEstado !== $estadoAnterior) {
                    $updateFields[] = 'estado = ?';
                    $updateValues[] = $nuevoEstado;
                }
                
                if ($estudioIdCambio) {
                    $updateFields[] = 'estudio_id = ?';
                    $updateValues[] = $nuevoEstudioId;
                }
                
                $updateFields[] = 'activo = TRUE';
                $updateFields[] = 'recovered = TRUE';
                $updateFields[] = 'fecha_eliminacion = NULL';
                $updateFields[] = 'workspace_panel_id = ?';
                $updateFields[] = 'recording_id = ?';
                
                $newRecordingId = $audio['recording_id'] ?? uniqid();
                $updateValues[] = $workspacePanelId ?? $audio['workspace_panel_id'];
                $updateValues[] = $newRecordingId;
                $updateValues[] = $audio['id'];
                
                $updateQuery = "UPDATE audios_informe SET " . implode(', ', $updateFields) . " WHERE id = ?";
                $updateStmt = $db->prepare($updateQuery);
                $updateStmt->execute($updateValues);
                
                // Crear entrada en log
                try {
                    $logQuery = "INSERT INTO audios_estado_log (
                        audio_id, estado_anterior, estado_nuevo, accion, usuario_id, estudio_id, workspace_panel_id, metadata
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    
                    $logStmt = $db->prepare($logQuery);
                    $logMetadata = ['recording_id' => $newRecordingId];
                    
                    if ($estudioIdCambio) {
                        $logMetadata['estudio_id_anterior'] = $estudioIdAnterior;
                        $logMetadata['estudio_id_nuevo'] = $nuevoEstudioId;
                    }
                    
                    $logStmt->execute([
                        $audio['id'],
                        $estadoAnterior ?? 'NULL',
                        $nuevoEstado,
                        $estudioIdCambio ? 'recuperar_cambiar_estudio' : 'recuperar',
                        $userId,
                        $nuevoEstudioId, // Usar el nuevo estudio_id en el log
                        $workspacePanelId ?? $audio['workspace_panel_id'],
                        json_encode($logMetadata)
                    ]);
                } catch (Exception $e) {
                    error_log('Error creando log de recuperación: ' . $e->getMessage());
                }
            } else {
                // Si no cambió el estado ni el estudio_id, solo actualizar workspace_panel_id y recovered
                $updateQuery = "UPDATE audios_informe SET 
                    recovered = TRUE,
                    workspace_panel_id = ?,
                    recording_id = ?
                WHERE id = ?";
                
                $newRecordingId = $audio['recording_id'] ?? uniqid();
                $updateStmt = $db->prepare($updateQuery);
                $updateStmt->execute([
                    $workspacePanelId ?? $audio['workspace_panel_id'],
                    $newRecordingId,
                    $audio['id']
                ]);
            }
            
            // Determinar si ya fue enviado a FTP o transcripción
            $enviadoFtp = !empty($audio['fecha_envio_ftp']);
            $enviadoTranscripcion = !empty($audio['fecha_envio_transcripcion']);
            
            $recoveredAudios[] = [
                'audio_id' => $audio['id'],
                'informe_id' => isset($audio['informe_id']) ? (int)$audio['informe_id'] : null,
                'autor_id' => isset($audio['usuario_id']) ? (int)$audio['usuario_id'] : null,
                'autor_nombre' => $audio['autor_nombre'] ?? null,
                'autor_apellido' => $audio['autor_apellido'] ?? null,
                'autor_email' => $audio['autor_email'] ?? null,
                'backup_path' => $backupPath,
                'workspace_panel_id' => $workspacePanelId ?? $audio['workspace_panel_id'],
                'recording_id' => $audio['recording_id'] ?? uniqid(),
                'estudio_id' => $nuevoEstudioId, // Incluir el estudio_id (puede haber cambiado)
                'estudio_id_anterior' => $estudioIdCambio ? $estudioIdAnterior : null,
                'estado' => $nuevoEstado, // Incluir el estado (preservado o actualizado)
                'estado_anterior' => $estadoAnterior,
                'fecha_envio_ftp' => $audio['fecha_envio_ftp'],
                'fecha_envio_transcripcion' => $audio['fecha_envio_transcripcion'],
                'enviado_ftp' => $enviadoFtp,
                'enviado_transcripcion' => $enviadoTranscripcion
            ];
            
        } catch (Exception $e) {
            $errors[] = "Audio ID {$audio['id']}: " . $e->getMessage();
        }
    }
    
    if (empty($recoveredAudios) && !empty($errors)) {
        throw new Exception('No se pudo recuperar ningún audio: ' . implode('; ', $errors));
    }
    
    echo json_encode([
        'success' => true,
        'message' => count($recoveredAudios) . ' audio(s) recuperado(s) exitosamente',
        'data' => [
            'recovered_audios' => $recoveredAudios,
            'total_recovered' => count($recoveredAudios),
            'errors' => $errors
        ]
    ]);
    
} catch (Exception $e) {
    error_log('Error en api/audios/recover.php: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
