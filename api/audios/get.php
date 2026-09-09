<?php
/**
 * API para obtener audios de un informe
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

require_once '../../classes/User.php';
require_once '../../config/database.php';

try {
    // Validar sesión (opcional para pruebas)
    $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
        $sessionToken = substr($sessionToken, 7);
    }
    
    // Si no hay token, continuar sin validación (para pruebas)
    $userData = null;
    if ($sessionToken) {
        try {
            $user = new User();
            $userData = $user->validateSession($sessionToken);
            
            if (!$userData) {
        http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Sesión inválida']);
                exit();
            }
        } catch (Exception $e) {
            // Si falla la validación, continuar sin ella
            $userData = null;
        }
    }
    
    // Obtener parámetros
    $informeId = $_GET['informe_id'] ?? null;
    $estudioId = $_GET['estudio_id'] ?? null;
    $patientId = $_GET['patient_id'] ?? null;
    $sessionId = $_GET['session_id'] ?? null;
    $studyId = $_GET['study_id'] ?? null; // Orthanc Study ID (para filtrar audios móviles)
    
    // Validar que haya al menos un parámetro válido
    if (!$informeId && !$estudioId && !$sessionId) {
        throw new Exception('ID de informe, estudio o sesión móvil requerido');
    }
    
    // Conectar a la base de datos
    $db = getDBConnection();
    
    // Si se proporciona informe_id, obtener estudio_id y patient_id del informe
    if ($informeId && !$estudioId) {
        $informeQuery = "SELECT estudio_id, patient_id FROM informes WHERE id = ?";
        $informeStmt = $db->prepare($informeQuery);
        $informeStmt->execute([$informeId]);
        $informeData = $informeStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$informeData) {
            throw new Exception('Informe no encontrado');
        }
        
        $estudioId = $informeData['estudio_id'];
        $patientId = $informeData['patient_id'];
    }
    
    // Construir query según los parámetros proporcionados
    $query = "SELECT 
                ai.id, ai.nombre_archivo, ai.ruta_archivo, ai.nombre_original, 
                ai.duracion_segundos, ai.tamano_bytes, ai.tipo_grabacion, 
                ai.fecha_creacion, ai.transcripcion_texto, ai.informe_id,
                ai.estudio_id, ai.mobile_session_id,
                ai.estado, ai.fecha_envio_ftp, ai.fecha_envio_transcripcion,
                i.version, i.titulo as informe_titulo,
                -- Obtener la transcripción más reciente de ai_transcriptions si existe
                (SELECT at.transcription_text FROM ai_transcriptions at 
                 WHERE at.audio_id = ai.id 
                   AND at.status = 'completed'
                 ORDER BY at.created_at DESC LIMIT 1) as transcription_from_ai,
                -- Obtener whisper_response para segments
                (SELECT at.whisper_response FROM ai_transcriptions at 
                 WHERE at.audio_id = ai.id 
                   AND at.status = 'completed'
                 ORDER BY at.created_at DESC LIMIT 1) as whisper_response
              FROM audios_informe ai
              LEFT JOIN informes i ON ai.informe_id = i.id
              WHERE ai.activo = 1";
    
    $params = [];
    $conditions = [];
    
    // Prioridad 1: Si hay informe_id, filtrar solo por informe_id (más específico)
    if ($informeId) {
        $conditions[] = "ai.informe_id = ?";
        $params[] = $informeId;
    }
    // Prioridad 2: Si hay session_id (para audios móviles)
    // Solo devolver los que el usuario explícitamente envió al workspace (estado = 'listo_workspace')
    // Los que solo están como respaldo (estado = 'en_papelera') NO se muestran en el workspace.
    elseif ($sessionId) {
        $conditions[] = "ai.mobile_session_id = ?";
        $params[] = $sessionId;
        $conditions[] = "ai.estado = 'listo_workspace'";
        
        // Si también hay study_id, filtrar adicionalmente por ese estudio
        if ($studyId) {
            $conditions[] = "ai.estudio_id = ?";
            $params[] = $studyId;
        }
    }
    // Prioridad 3: Si hay estudio_id (sin informe_id ni session_id)
    elseif ($estudioId) {
        $conditions[] = "ai.estudio_id = ?";
        $params[] = $estudioId;
    }
    
    // Agregar condiciones a la query
    if (!empty($conditions)) {
        $query .= " AND " . implode(" AND ", $conditions);
    }
    
    // Filtro adicional por patient_id si se proporciona (solo si no hay informe_id)
    if ($patientId && !$informeId) {
        $query .= " AND EXISTS (SELECT 1 FROM informes WHERE estudio_id = ai.estudio_id AND patient_id = ?)";
        $params[] = $patientId;
    }
    
    $query .= " ORDER BY ai.fecha_creacion ASC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $audios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    // Formatear datos
    $formattedAudios = [];
    foreach ($audios as $audio) {
        // Priorizar transcripción de ai_transcriptions, luego audios_informe
        $transcripcionFromAi = $audio['transcription_from_ai'] ?? null;
        $transcripcionFromAudio = $audio['transcripcion_texto'] ?? null;
        $transcripcion = $transcripcionFromAi ?? $transcripcionFromAudio;
        
        // Procesar whisper_response para extraer segments
        $whisperResponse = null;
        $segments = null;
        if (!empty($audio['whisper_response'])) {
            $whisperResponse = json_decode($audio['whisper_response'], true);
            if ($whisperResponse && isset($whisperResponse['segments'])) {
                $segments = $whisperResponse['segments'];
            }
        }
        
        $formattedAudios[] = [
            'id'                       => $audio['id'],
            'informe_id'               => $audio['informe_id'],
            'estudio_id'               => $audio['estudio_id'],
            'version'                  => $audio['version'],
            'informe_titulo'           => $audio['informe_titulo'],
            'nombre_archivo'           => $audio['nombre_archivo'],
            'ruta_archivo'             => $audio['ruta_archivo'],
            'nombre_original'          => $audio['nombre_original'],
            'duracion_segundos'        => $audio['duracion_segundos'],
            'tamano_bytes'             => $audio['tamano_bytes'],
            'tipo_grabacion'           => $audio['tipo_grabacion'],
            'fecha_creacion'           => $audio['fecha_creacion'],
            'transcripcion'            => $transcripcion,
            'whisper_response'         => $whisperResponse,
            'segments'                 => $segments,
            'url_completa'             => 'uploads/audios/' . $audio['nombre_archivo'],
            // Campos de estado para que workspace determine ftpSent/transcripcionSent
            'estado'                   => $audio['estado'] ?? null,
            'enviado_ftp'              => !empty($audio['fecha_envio_ftp']),
            'enviado_transcripcion'    => !empty($audio['fecha_envio_transcripcion']),
            'fecha_envio_ftp'          => $audio['fecha_envio_ftp'] ?? null,
            'fecha_envio_transcripcion'=> $audio['fecha_envio_transcripcion'] ?? null,
        ];
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Audios obtenidos correctamente',
        'data' => $formattedAudios,
        'total' => count($formattedAudios)
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>