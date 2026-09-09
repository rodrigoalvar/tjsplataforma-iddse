<?php
/**
 * API Endpoint para obtener audios recuperables de un estudio
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Retorna audios recuperables de un estudio específico:
 * - 'guardado_informe': Se cargan automáticamente al workspace
 * - 'en_papelera' o NULL: Se ofrecen en modal para recuperar
 * - 'eliminado': NO se incluyen (solo se ven en papelera)
 */

header('Content-Type: application/json');
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
    // Validar sesión - Cookie primero (igual que save-config.php y otros endpoints)
    $sessionToken = null;
    if (isset($_COOKIE['session_token']) && !empty($_COOKIE['session_token'])) {
        $sessionToken = trim($_COOKIE['session_token']);
    } elseif (isset($_COOKIE['sessionToken']) && !empty($_COOKIE['sessionToken'])) {
        $sessionToken = trim($_COOKIE['sessionToken']);
    } elseif (isset($_GET['session_token']) && !empty($_GET['session_token'])) {
        $sessionToken = trim($_GET['session_token']);
    } elseif (isset($_POST['session_token']) && !empty($_POST['session_token'])) {
        $sessionToken = trim($_POST['session_token']);
    } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'];
        $sessionToken = strpos($auth, 'Bearer ') === 0 ? trim(substr($auth, 7)) : trim($auth);
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
    
    // Obtener parámetros
    $studyId  = $_GET['study_id']  ?? $_POST['study_id']  ?? null;
    $daysBack = intval($_GET['days_back'] ?? $_POST['days_back'] ?? 30); // Por defecto 30 días

    // IDs adicionales del mismo estudio (para cubrir audios móviles vs. workspace)
    // Los audios móviles usan orthancStudyId; los del workspace usan studyInstanceUID.
    $extraStudyIds = $_GET['extra_study_ids'] ?? $_POST['extra_study_ids'] ?? [];
    if (!is_array($extraStudyIds)) {
        // Soportar formato extra_study_ids como string CSV además del array
        $extraStudyIds = array_filter(array_map('trim', explode(',', $extraStudyIds)));
    }

    if (!$studyId) {
        throw new Exception('study_id es requerido');
    }

    // Construir lista única de todos los IDs del estudio a buscar
    $allStudyIds = array_values(array_unique(array_filter(array_merge([$studyId], $extraStudyIds))));
    
    // Conectar a la base de datos
    $db = getDBConnection();
    
    // ── Construir WHERE dinámico para todos los IDs del estudio ──────────────────
    // Los audios del workspace se guardan con studyInstanceUID (DICOM UID).
    // Los audios móviles se guardan con orthancStudyId (UUID de Orthanc).
    // Necesitamos cubrir ambos casos sin depender del JOIN con la tabla estudios.
    $idPlaceholders = implode(',', array_fill(0, count($allStudyIds), '?'));

    $query = "SELECT 
        ai.id,
        ai.informe_id,
        ai.usuario_id,
        ai.estudio_id,
        ai.nombre_archivo,
        ai.nombre_original,
        ai.ruta_archivo,
        ai.backup_path,
        ai.duracion_segundos,
        ai.tamano_bytes,
        ai.tipo_mime,
        ai.estado,
        ai.workspace_panel_id,
        ai.recording_id,
        ai.recovered,
        ai.mobile_session_id,
        ai.fecha_creacion,
        ai.fecha_eliminacion,
        ai.fecha_envio_ftp,
        ai.fecha_envio_transcripcion,
        u.nombre as autor_nombre,
        u.apellido as autor_apellido,
        u.email as autor_email,
        COALESCE(e.patient_id_pacs, inf.patient_id, ms.patient_id) as patient_id,
        COALESCE(e.patient_name_pacs, inf.patient_name, ms.patient_name) as patient_name,
        COALESCE(e.study_description, inf.study_description, ms.study_description) as study_description
    FROM audios_informe ai
    LEFT JOIN usuarios u ON ai.usuario_id = u.id
    LEFT JOIN estudios e ON e.id = (
        SELECT e2.id
        FROM estudios e2
        WHERE ai.estudio_id = e2.orthanc_study_id
           OR ai.estudio_id = e2.study_instance_uid
           OR ai.estudio_id = CONVERT(e2.id, CHAR)
        ORDER BY
            (ai.estudio_id = e2.orthanc_study_id) DESC,
            (ai.estudio_id = e2.study_instance_uid) DESC,
            (ai.estudio_id = CONVERT(e2.id, CHAR)) DESC,
            e2.id DESC
        LIMIT 1
    )
    LEFT JOIN informes inf ON ai.informe_id = inf.id
    LEFT JOIN mobile_sessions ms ON ai.mobile_session_id = ms.session_id
    WHERE " . ($hasGlobalAudioPermission ? "1=1" : "ai.usuario_id = ?") . "
    AND (
        ai.estado = 'guardado_informe'
        OR ai.estado = 'listo_workspace'
        OR ai.estado = 'en_papelera'
        OR ai.estado = 'enviado_ftp'
        OR ai.estado = 'enviado_transcripcion'
        OR ai.estado IS NULL
    )
    AND ai.estado != 'eliminado'
    AND ai.fecha_creacion >= DATE_SUB(NOW(), INTERVAL ? DAY)
    AND (
        ai.estudio_id IN ($idPlaceholders)
        OR e.orthanc_study_id IN ($idPlaceholders)
        OR e.study_instance_uid IN ($idPlaceholders)
        OR CONVERT(e.id, CHAR) IN ($idPlaceholders)
    )
    ORDER BY ai.fecha_creacion DESC";

    $stmt = $db->prepare($query);
    if (!$stmt) {
        $errorInfo = $db->errorInfo();
        error_log('Error preparando query en get-recoverable.php: ' . implode(', ', $errorInfo));
        throw new Exception('Error preparando consulta: ' . implode(', ', $errorInfo));
    }
    
    try {
        // Parámetros:
        // - sin permiso global: usuario_id, daysBack, luego allStudyIds × 4
        // - con permiso global: daysBack, luego allStudyIds × 4
        $idsRepeated = array_merge($allStudyIds, $allStudyIds, $allStudyIds, $allStudyIds);
        $params = $hasGlobalAudioPermission
            ? array_merge([$daysBack], $idsRepeated)
            : array_merge([$userId, $daysBack], $idsRepeated);
        $stmt->execute($params);
    } catch (PDOException $e) {
        error_log('Error ejecutando query en get-recoverable.php: ' . $e->getMessage() . ' | Params: ' . json_encode($params));
        throw new Exception('Error ejecutando consulta: ' . $e->getMessage());
    }
    
    $audios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatear datos para respuesta
    $formattedAudios = [];
    foreach ($audios as $audio) {
        // Verificar que el archivo de backup existe
        $backupPath = $audio['backup_path'] ?? $audio['ruta_archivo'];
        if ($backupPath) {
            $fullBackupPath = __DIR__ . '/../../' . ltrim($backupPath, '/');
            $fileExists = file_exists($fullBackupPath);
        } else {
            $fileExists = false;
            $fullBackupPath = null;
        }
        
        // Determinar si ya fue enviado a FTP o transcripción
        $enviadoFtp = !empty($audio['fecha_envio_ftp']);
        $enviadoTranscripcion = !empty($audio['fecha_envio_transcripcion']);
        
        $formattedAudios[] = [
            'id' => $audio['id'],
            'audio_id' => $audio['id'],
            'informe_id' => isset($audio['informe_id']) ? (int)$audio['informe_id'] : null,
            'autor_id' => isset($audio['usuario_id']) ? (int)$audio['usuario_id'] : null,
            'autor_nombre' => $audio['autor_nombre'] ?? null,
            'autor_apellido' => $audio['autor_apellido'] ?? null,
            'autor_email' => $audio['autor_email'] ?? null,
            'estudio_id' => $audio['estudio_id'],
            'nombre_archivo' => $audio['nombre_archivo'],
            'nombre_original' => $audio['nombre_original'],
            'backup_path' => $backupPath,
            'duracion_segundos' => floatval($audio['duracion_segundos'] ?? 0),
            'tamano_bytes' => intval($audio['tamano_bytes'] ?? 0),
            'tipo_mime' => $audio['tipo_mime'] ?? 'audio/webm',
            'estado' => $audio['estado'],
            'workspace_panel_id' => $audio['workspace_panel_id'],
            'recording_id' => $audio['recording_id'],
            'recovered' => (bool)$audio['recovered'],
            'mobile_session_id' => $audio['mobile_session_id'] ?? null,
            'fecha_creacion' => $audio['fecha_creacion'],
            'fecha_eliminacion' => $audio['fecha_eliminacion'],
            'fecha_envio_ftp' => $audio['fecha_envio_ftp'],
            'fecha_envio_transcripcion' => $audio['fecha_envio_transcripcion'],
            'enviado_ftp' => $enviadoFtp,
            'enviado_transcripcion' => $enviadoTranscripcion,
            'patient_id' => $audio['patient_id'],
            'patient_name' => $audio['patient_name'],
            'study_description' => $audio['study_description'],
            'file_exists' => $fileExists,
            'file_size' => $fileExists && $fullBackupPath ? filesize($fullBackupPath) : 0
        ];
    }
    
    // Retornar 200 OK incluso si no hay audios (comportamiento normal)
    echo json_encode([
        'success' => true,
        'message' => count($formattedAudios) > 0 ? 'Audios recuperables obtenidos' : 'No hay audios recuperables para este estudio',
        'data' => [
            'audios' => $formattedAudios,
            'total' => count($formattedAudios),
            'study_id' => $studyId
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    error_log('Error PDO en api/audios/get-recoverable.php: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error de base de datos',
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('Error en api/audios/get-recoverable.php: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
