<?php
/**
 * API Endpoint para listar audios de papelera con filtros
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
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
    
    // Verificar permiso para acceder a papelera (con manejo de errores)
    $hasPapeleraAccess = false;
    $canSeeAllAudios = false;
    $isRoot = false;
    
    try {
        $permissionManager = new PermissionManager();
        $hasPapeleraAccess = $permissionManager->hasPermission('papelera_audios', $userData['id']);
        $canSeeAllAudios = $permissionManager->hasPermission('papelera_audios_all', $userData['id']);
        $isRoot = ($userData['nivel'] ?? 'user') === 'root';
    } catch (Exception $e) {
        error_log("Error verificando permisos de papelera: " . $e->getMessage());
        // Si el sistema de permisos no está configurado, permitir acceso como fallback
        // Solo verificar si es root
        $isRoot = ($userData['nivel'] ?? 'user') === 'root';
        $hasPapeleraAccess = true; // Fallback: permitir acceso si el sistema de permisos falla
    }
    
    // Si no tiene permiso y no es root, denegar acceso
    if (!$hasPapeleraAccess && !$isRoot) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'No tiene permiso para acceder a la papelera de audios']);
        exit();
    }
    
    // Obtener parámetros de filtro
    $studyId = $_GET['study_id'] ?? $_POST['study_id'] ?? null;
    $estados = $_GET['estados'] ?? $_POST['estados'] ?? null; // Array de estados
    $fechaDesde = $_GET['fecha_desde'] ?? $_POST['fecha_desde'] ?? null;
    $fechaHasta = $_GET['fecha_hasta'] ?? $_POST['fecha_hasta'] ?? null;
    $busqueda = $_GET['busqueda'] ?? $_POST['busqueda'] ?? null;
    $page = intval($_GET['page'] ?? $_POST['page'] ?? 1);
    $limit = intval($_GET['limit'] ?? $_POST['limit'] ?? 50);
    $offset = ($page - 1) * $limit;
    
    // IDs adicionales del mismo estudio (audios móviles usan orthancStudyId;
    // audios de micrófono usan studyInstanceUID).
    $extraStudyIds = $_GET['extra_study_ids'] ?? $_POST['extra_study_ids'] ?? [];
    if (!is_array($extraStudyIds)) {
        $extraStudyIds = array_filter(array_map('trim', explode(',', $extraStudyIds)));
    }

    // Procesar estados si viene como string separado por comas
    if ($estados && is_string($estados)) {
        $estados = explode(',', $estados);
        $estados = array_map('trim', $estados);
    }
    
    // Conectar a la base de datos
    $db = getDBConnection();
    
    // Construir query con filtros
    // Si tiene permiso para ver todos o es root, no filtrar por usuario
    if ($canSeeAllAudios || $isRoot) {
        $whereConditions = ['1=1']; // Ver todos los audios
        $params = [];
    } else {
        $whereConditions = ['ai.usuario_id = ?'];
        $params = [$userData['id']];
    }
    
    if ($studyId) {
        // Construir lista única de todos los IDs del estudio para cubrir ambos tipos de audio:
        // - Audios de micrófono: estudio_id = studyInstanceUID (DICOM UID)
        // - Audios móviles:      estudio_id = orthancStudyId   (UUID de Orthanc)
        $allStudyIds = array_values(array_unique(array_filter(array_merge([$studyId], $extraStudyIds))));
        $idPlaceholders = implode(',', array_fill(0, count($allStudyIds), '?'));

        // También buscar vía JOIN con estudios (por si el estudio_id es un ID numérico)
        $whereConditions[] = "(
            ai.estudio_id IN ($idPlaceholders)
            OR EXISTS (
                SELECT 1 FROM estudios e
                WHERE (ai.estudio_id = e.orthanc_study_id
                       OR ai.estudio_id = CONVERT(e.id, CHAR)
                       OR ai.estudio_id = e.study_instance_uid)
                  AND (e.orthanc_study_id IN ($idPlaceholders)
                       OR e.study_instance_uid IN ($idPlaceholders)
                       OR CONVERT(e.id, CHAR) IN ($idPlaceholders))
            )
        )";
        // Parámetros: allStudyIds × 5 (1 IN directo + 4 IN en la subconsulta)
        $studyParams = array_merge(
            $allStudyIds,           // ai.estudio_id IN (...)
            $allStudyIds,           // e.orthanc_study_id IN (...)
            $allStudyIds,           // e.study_instance_uid IN (...)
            $allStudyIds            // CONVERT(e.id, CHAR) IN (...)
        );
        $params = array_merge($params, $studyParams);
    }
    
    if ($estados && is_array($estados) && !empty($estados)) {
        $estadosValidos = ['en_papelera', 'enviado_ftp', 'enviado_transcripcion', 'guardado_informe', 'eliminado'];
        $estadosFiltrados = array_intersect($estados, $estadosValidos);
        if (!empty($estadosFiltrados)) {
            $placeholders = str_repeat('?,', count($estadosFiltrados) - 1) . '?';
            $whereConditions[] = "ai.estado IN ($placeholders)";
            $params = array_merge($params, $estadosFiltrados);
        }
    } else {
        // Si no se especifican estados, mostrar todos los audios (incluyendo NULL)
        // No agregar filtro de estado
    }
    
    if ($fechaDesde) {
        $whereConditions[] = 'DATE(ai.fecha_creacion) >= ?';
        $params[] = $fechaDesde;
    }
    
    if ($fechaHasta) {
        $whereConditions[] = 'DATE(ai.fecha_creacion) <= ?';
        $params[] = $fechaHasta;
    }
    
    if ($busqueda) {
        // Búsqueda mejorada usando subconsultas para evitar problemas con LEFT JOIN en WHERE
        // Buscar en múltiples campos incluyendo estudio_id directamente
        $searchTerm = '%' . $busqueda . '%';
        
        // Usar subconsultas para buscar en estudios e informes sin depender del JOIN en WHERE
        $whereConditions[] = '(ai.nombre_archivo LIKE ? 
                               OR ai.nombre_original LIKE ? 
                               OR ai.estudio_id LIKE ?
                               OR EXISTS (
                                   SELECT 1 FROM estudios e 
                                   WHERE (ai.estudio_id = e.orthanc_study_id 
                                          OR ai.estudio_id = CONVERT(e.id, CHAR)
                                          OR ai.estudio_id = e.study_instance_uid)
                                   AND (e.patient_name_pacs LIKE ? 
                                        OR e.patient_id_pacs LIKE ?
                                        OR e.study_description LIKE ?)
                               )
                               OR EXISTS (
                                   SELECT 1 FROM informes inf 
                                   WHERE ai.informe_id = inf.id
                                   AND (inf.patient_name LIKE ? 
                                        OR inf.patient_id LIKE ?
                                        OR inf.study_description LIKE ?)
                               ))';
        $params[] = $searchTerm; // nombre_archivo
        $params[] = $searchTerm; // nombre_original
        $params[] = $searchTerm; // estudio_id
        $params[] = $searchTerm; // e.patient_name_pacs
        $params[] = $searchTerm; // e.patient_id_pacs
        $params[] = $searchTerm; // e.study_description
        $params[] = $searchTerm; // inf.patient_name
        $params[] = $searchTerm; // inf.patient_id
        $params[] = $searchTerm; // inf.study_description
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Query para contar total (sin JOIN para evitar filtrado)
    $countQuery = "SELECT COUNT(*) as total
                   FROM audios_informe ai
                   WHERE $whereClause";
    
    $countStmt = $db->prepare($countQuery);
    $countStmt->execute($params);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Query para obtener datos con JOIN mejorado
    // Intentar múltiples formas de hacer match con el estudio
    $query = "SELECT 
        ai.id,
        ai.estudio_id,
        ai.informe_id,
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
        ai.fecha_creacion,
        ai.fecha_modificacion,
        ai.fecha_eliminacion,
        ai.fecha_envio_ftp,
        ai.fecha_envio_transcripcion,
        ai.fecha_guardado_informe,
        COALESCE(e.patient_id_pacs, inf.patient_id, ms.patient_id) as patient_id,
        COALESCE(e.patient_name_pacs, inf.patient_name, ms.patient_name) as patient_name,
        COALESCE(e.study_description, inf.study_description, ms.study_description) as study_description,
        COALESCE(e.modality, inf.modality, ms.modality) as modality,
        e.study_instance_uid as estudios_uid,
        e.orthanc_study_id as estudios_orthanc_id,
        u.nombre as usuario_nombre,
        u.email as usuario_email,
        SUBSTRING_INDEX(u.email, '@', 1) as usuario_login
    FROM audios_informe ai
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
    LEFT JOIN usuarios u ON ai.usuario_id = u.id
    WHERE $whereClause
    ORDER BY ai.fecha_creacion DESC
    LIMIT ? OFFSET ?";
    
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $audios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatear datos para respuesta
    $formattedAudios = [];
    foreach ($audios as $audio) {
        $backupPath = $audio['backup_path'] ?? $audio['ruta_archivo'];
        $fullBackupPath = __DIR__ . '/../../' . ltrim($backupPath, '/');
        $fileExists = file_exists($fullBackupPath);
        
        $formattedAudios[] = [
            'id' => $audio['id'],
            'audio_id' => $audio['id'],
            'estudio_id' => $audio['estudio_id'],
            'informe_id' => $audio['informe_id'],
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
            'fecha_creacion' => $audio['fecha_creacion'],
            'fecha_modificacion' => $audio['fecha_modificacion'],
            'fecha_eliminacion' => $audio['fecha_eliminacion'],
            'fecha_envio_ftp' => $audio['fecha_envio_ftp'],
            'fecha_envio_transcripcion' => $audio['fecha_envio_transcripcion'],
            'fecha_guardado_informe' => $audio['fecha_guardado_informe'],
            'patient_id' => $audio['patient_id'],
            'patient_name' => $audio['patient_name'],
            'study_description' => $audio['study_description'],
            'modality' => $audio['modality'],
            'estudios_uid' => $audio['estudios_uid'] ?? null,
            'estudios_orthanc_id' => $audio['estudios_orthanc_id'] ?? null,
            'usuario_nombre' => $audio['usuario_nombre'] ?? null,
            'usuario_email' => $audio['usuario_email'] ?? null,
            'usuario_login' => $audio['usuario_login'] ?? null,
            'file_exists' => $fileExists,
            'file_size' => $fileExists ? filesize($fullBackupPath) : 0
        ];
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Lista de audios obtenida',
        'data' => [
            'audios' => $formattedAudios,
            'pagination' => [
                'total' => intval($total),
                'page' => $page,
                'limit' => $limit,
                'total_pages' => ceil($total / $limit)
            ]
        ]
    ]);
    
} catch (Exception $e) {
    error_log('Error en api/audios/papelera-list.php: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
