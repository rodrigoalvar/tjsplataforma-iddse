<?php
// API para listar informes médicos
// Habilitar logging de errores pero no mostrarlos al usuario
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Registrar errores fatales
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        error_log('[LIST_INFORMES] Error fatal: ' . $error['message'] . ' en ' . $error['file'] . ':' . $error['line']);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Error interno del servidor'
            ]);
        }
    }
});

// Configurar encabezados CORS
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/informes_list_common.php';
require_once __DIR__ . '/transcription_status_helpers.php';

try {
    // Cargar configuración de base de datos
    require_once __DIR__ . '/../../config/database.php';
    
    // Obtener parámetros de paginación y filtros
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    // Aceptar tanto 'per_page' como 'limit' para compatibilidad, valor por defecto 25
    $limit = isset($_GET['per_page']) ? max(1, min(100, intval($_GET['per_page']))) : 
             (isset($_GET['limit']) ? max(1, min(100, intval($_GET['limit']))) : 25);
    $offset = ($page - 1) * $limit;
    
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $status = isset($_GET['status']) ? trim($_GET['status']) : '';
    if ($status === '' && isset($_GET['estado'])) {
        $status = trim((string) $_GET['estado']);
    }
    $modality = isset($_GET['modality']) ? trim($_GET['modality']) : '';
    $fecha_inicio = isset($_GET['fecha_inicio']) ? trim($_GET['fecha_inicio']) : '';
    $fecha_fin = isset($_GET['fecha_fin']) ? trim($_GET['fecha_fin']) : '';
    
    // Parámetros de ordenamiento
    $sortBy = isset($_GET['sort_by']) ? trim($_GET['sort_by']) : null;
    $sortOrder = isset($_GET['sort_order']) ? strtoupper(trim($_GET['sort_order'])) : 'DESC';
    
    // Validar sortOrder
    if ($sortOrder !== 'ASC' && $sortOrder !== 'DESC') {
        $sortOrder = 'DESC';
    }
    
    // Conectar a la base de datos
    $db = getDBConnection();
    
    // Validar que la conexión a la base de datos sea exitosa
    if (!$db) {
        error_log('[LIST_INFORMES] Error: No se pudo conectar a la base de datos');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Error de conexión a la base de datos'
        ]);
        exit;
    }
    
    // Verificar sesión y permisos del usuario
    $user_id = null;
    $user_padre_id = null;
    $can_view_all = false;
    $has_gestion_informes = false;
    $can_send_to_pacs = false;
    $can_toggle_pacs_format = false;
    $has_filter_institutions = false;
    $allowed_institutions = [];
    $can_datos_cobranza = false;
    
    // Verificar si hay token de autenticación
    $token = null;
    try {
        // getallheaders() puede no estar disponible en algunos entornos
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (isset($headers['Authorization'])) {
                $authHeader = $headers['Authorization'];
                if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                    $token = $matches[1];
                }
            }
        } else {
            // Fallback: leer desde $_SERVER
            if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
                $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
                if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                    $token = $matches[1];
                }
            } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
                if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                    $token = $matches[1];
                }
            }
        }
    } catch (Exception $e) {
        error_log('[LIST_INFORMES] Error obteniendo headers: ' . $e->getMessage());
    }
    
    if ($token) {
        try {
            // Cargar la clase User
            require_once __DIR__ . '/../../classes/User.php';
            $user = new User();
            $user_data = $user->validateSession($token);
            
            if ($user_data && is_array($user_data)) {
                $user_id = $user_data['id'];
                
                // Obtener padre_id del usuario desde la base de datos
                try {
                    $stmt = $db->prepare("SELECT padre_id FROM usuarios WHERE id = ? AND activo = 1");
                    $stmt->execute([$user_id]);
                    $user_info = $stmt->fetch(PDO::FETCH_ASSOC);
                    $user_padre_id = $user_info ? $user_info['padre_id'] : null;
                } catch (Exception $e) {
                    error_log('[LIST_INFORMES] Error obteniendo padre_id del usuario: ' . $e->getMessage());
                    $user_padre_id = null;
                }
                
                $user_permisos = isset($user_data['permisos']) ? $user_data['permisos'] : [];
                
                $can_view_all = listInformes_userHasPermission($user_permisos, 'verTodosInformes');
                
                // Verificar permiso 'gestionInformes' (permite ver informes de hijos/padre)
                $has_gestion_informes = listInformes_userHasPermission($user_permisos, 'gestionInformes');
                
                // Verificar permiso 'enviar_pacs' (permite enviar informes a PACS)
                $can_send_to_pacs = listInformes_userHasPermission($user_permisos, 'enviar_pacs');
                
                // Selector PDF/IMG en Gestión Informes (Interfaz / GUI)
                $can_toggle_pacs_format = listInformes_userHasPermission($user_permisos, 'gui_toggle_formato_pacs');
                
                // Verificar si tiene permiso de filtro por instituciones
                $has_filter_institutions = listInformes_userHasPermission($user_permisos, 'filter_institutions');
                
                $can_datos_cobranza = listInformes_userHasPermission($user_permisos, 'datosCobranzaInformes');
            }
        } catch (Exception $e) {
            // Continuar sin permiso de ver todos
            error_log('[LIST_INFORMES] Error validando sesión: ' . $e->getMessage());
        }
    }
    
    // Construir filtro de jerarquía de usuarios
    try {
        error_log('[LIST_INFORMES] 🔍 Construyendo filtro - user_id: ' . ($user_id ?? 'NULL') . ', padre_id: ' . ($user_padre_id ?? 'NULL') . ', can_view_all: ' . ($can_view_all ? 'Sí' : 'No'));
        $hierarchyFilter = buildUserHierarchyFilter($db, $user_id, $user_padre_id, $can_view_all);
        $userFilterCondition = $hierarchyFilter['condition'];
        $params = $hierarchyFilter['params'];
        $condLen = strlen((string) $userFilterCondition);
        error_log('[LIST_INFORMES] ✅ Filtro construido - condición_len: ' . $condLen . ', params: ' . count($params));
    } catch (Exception $e) {
        error_log('[LIST_INFORMES] ❌ Error construyendo filtro de jerarquía: ' . $e->getMessage());
        error_log('[LIST_INFORMES] Stack trace: ' . $e->getTraceAsString());
        // Usar filtro por defecto (solo sus propios informes)
        $userFilterCondition = $user_id ? " AND i.usuario_id = :user_id" : "";
        $params = $user_id ? [':user_id' => $user_id] : [];
    }
    
    // Si no hay usuario autenticado y no tiene permiso para ver todos, devolver error
    if (!$user_id && !$can_view_all) {
        error_log('[LIST_INFORMES] ⚠️ Usuario no autenticado y sin permiso para ver todos');
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'No autenticado'
        ]);
        exit;
    }
    
    // Obtener instituciones permitidas si el usuario tiene el permiso filter_institutions
    if ($has_filter_institutions && $user_id) {
        try {
            $stmt = $db->prepare("SELECT instituciones_permitidas FROM usuarios WHERE id = ? AND activo = 1");
            $stmt->execute([$user_id]);
            $institucionesResult = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($institucionesResult && !empty($institucionesResult['instituciones_permitidas'])) {
                $allowed_institutions = json_decode($institucionesResult['instituciones_permitidas'], true) ?: [];
                // Limpiar espacios en blanco y convertir a mayúsculas para comparación
                $allowed_institutions = array_map(function($inst) {
                    return trim(strtoupper($inst));
                }, $allowed_institutions);
                error_log('[LIST_INFORMES] Usuario ' . $user_id . ' con filtro de instituciones - Instituciones permitidas: ' . implode(', ', $allowed_institutions));
            }
        } catch (Exception $e) {
            error_log('[LIST_INFORMES] Error obteniendo instituciones permitidas: ' . $e->getMessage());
        }
    }
    
    // Aplicar filtros adicionales
    if (!empty($search)) {
        $searchPattern = "%$search%";
        $params[':search_titulo'] = $searchPattern;
        $params[':search_patient_name'] = $searchPattern;
        $params[':search_patient_id'] = $searchPattern;
    }
    
    if (!empty($status) && $status !== 'incompletos') {
        $params[':status'] = $status;
    }
    
    if (!empty($modality)) {
        $params[':modality'] = $modality;
    }
    
    if (!empty($fecha_inicio)) {
        $params[':fecha_inicio'] = $fecha_inicio;
    }
    
    if (!empty($fecha_fin)) {
        $params[':fecha_fin'] = $fecha_fin;
    }
    
    // Filtro opcional por autor del informe (usuario_id), solo si el solicitante puede ver a ese usuario
    $filterUsuarioCondition = '';
    $filter_usuario_id_req = isset($_GET['filter_usuario_id']) ? (int) $_GET['filter_usuario_id'] : 0;
    if ($filter_usuario_id_req > 0 && $user_id) {
        $allowUsuarioFilter = false;
        if ($can_view_all) {
            $allowUsuarioFilter = true;
        } elseif ($user_padre_id) {
            $allowUsuarioFilter = ($filter_usuario_id_req === (int) $user_id);
        } else {
            $totalProcessedFu = 0;
            $descFu = getDescendantUserIds($db, (int) $user_id, [], 10, $totalProcessedFu, 1000);
            $allowedFu = array_values(array_unique(array_map('intval', array_merge([(int) $user_id], $descFu))));
            $allowUsuarioFilter = in_array($filter_usuario_id_req, $allowedFu, true);
        }
        if ($allowUsuarioFilter) {
            $filterUsuarioCondition = ' AND i.usuario_id = :filter_usuario_id';
            $params[':filter_usuario_id'] = $filter_usuario_id_req;
        } else {
            error_log('[LIST_INFORMES] filter_usuario_id rechazado (no autorizado): ' . $filter_usuario_id_req . ' para user ' . $user_id);
        }
    }
    
    // Verificar qué columnas PACS existen
    $hasPacsColumns = false;
    $hasPacsInstanceId = false;
    $hasPacsStudyId = false;
    $hasPacsSeriesId = false;
    $hasFechaEnviadoPacs = false;
    $hasAudioActivo = false;
    $hasTxTables = false;
    $hasAudioEstado = false;
    
    try {
        $checkColumnsQuery = "SHOW COLUMNS FROM informes";
        $checkColumnsStmt = $db->query($checkColumnsQuery);
        $columns = $checkColumnsStmt->fetchAll(PDO::FETCH_COLUMN);
        
        $hasPacsInstanceId = in_array('pacs_instance_id', $columns);
        $hasPacsStudyId = in_array('pacs_study_id', $columns);
        $hasPacsSeriesId = in_array('pacs_series_id', $columns);
        $hasFechaEnviadoPacs = in_array('fecha_enviado_pacs', $columns);
        $hasPacsColumns = $hasPacsInstanceId || $hasPacsStudyId || $hasPacsSeriesId || $hasFechaEnviadoPacs;
        $hasCobranzaCols = in_array('cobranza_regiones', $columns, true);
        
        // Verificar si existe tabla audios_informe y columna activo
        try {
            $checkAudiosTable = $db->query("SHOW TABLES LIKE 'audios_informe'");
            if ($checkAudiosTable->rowCount() > 0) {
                $checkActivoCol = $db->query("SHOW COLUMNS FROM audios_informe LIKE 'activo'");
                $hasAudioActivo = ($checkActivoCol->rowCount() > 0);
                $hasAudioEstado = informesAudioHasEstadoColumn($db);
            }
            $hasTxTables = informesTxTablesExist($db);
        } catch (Exception $e) {
            error_log('[LIST] Error verificando tabla audios_informe: ' . $e->getMessage());
        }
    } catch (Exception $e) {
        error_log('[LIST] Error verificando columnas PACS: ' . $e->getMessage());
        $hasCobranzaCols = false;
    }
    if (!isset($hasCobranzaCols)) {
        $hasCobranzaCols = false;
    }
    
    $countQuery = "SELECT COUNT(*) as total 
                   FROM informes i
                   WHERE 1=1";
    $countUserFilterCondition = $userFilterCondition;
    
    // Aplicar filtros
    $countQuery .= $countUserFilterCondition;
    
    if (!empty($search)) {
        $countQuery .= " AND (i.titulo LIKE :search_titulo OR i.patient_name LIKE :search_patient_name OR i.patient_id LIKE :search_patient_id)";
    }
    
    if (!empty($status) && $status !== 'incompletos') {
        $countQuery .= " AND i.estado = :status";
    } elseif ($status === 'incompletos') {
        $countQuery .= " AND EXISTS (
            SELECT 1 FROM study_flags sf
            WHERE sf.informes_incompletos = 1
              AND (
                (i.estudio_id IS NOT NULL AND i.estudio_id <> '' AND (sf.study_id = i.estudio_id OR sf.orthanc_id = i.estudio_id))
                OR (i.study_id IS NOT NULL AND i.study_id <> '' AND (sf.study_id = i.study_id OR sf.orthanc_id = i.study_id))
                OR (i.study_instance_uid IS NOT NULL AND i.study_instance_uid <> '' AND sf.study_instance_uid = i.study_instance_uid)
                OR (sf.informe_id IS NOT NULL AND sf.informe_id = i.id)
              )
        )";
    }
    
    if (!empty($modality)) {
        $countQuery .= " AND i.modality = :modality";
    }
    
    if (!empty($fecha_inicio)) {
        $countQuery .= " AND DATE(i.fecha_modificacion) >= :fecha_inicio";
    }
    
    if (!empty($fecha_fin)) {
        $countQuery .= " AND DATE(i.fecha_modificacion) <= :fecha_fin";
    }
    
    $countQuery .= $filterUsuarioCondition;
    
    // Log para debugging
    error_log('[LIST_INFORMES] Count Query listo (len=' . strlen($countQuery) . ', params=' . count($params) . ')');
    error_log('[LIST_INFORMES] User ID: ' . ($user_id ?? 'NULL') . ', Can View All: ' . ($can_view_all ? 'Yes' : 'No'));
    
    try {
        // Establecer timeout para la consulta
        $db->setAttribute(PDO::ATTR_TIMEOUT, 30);
        
        $countStmt = $db->prepare($countQuery);
        
        // Vincular parámetros para count
        // Si usamos subconsulta para conteo, agregar parámetros adicionales
        $countParams = $params;
        
        foreach ($countParams as $key => $value) {
            $paramType = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $countStmt->bindValue($key, $value, $paramType);
        }
        
        $startTime = microtime(true);
        $countStmt->execute();
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);
        error_log('[LIST_INFORMES] Count query ejecutada en ' . $duration . 'ms');
        
        $totalResults = $countStmt->fetch()['total'];
        error_log('[LIST_INFORMES] Total Results Counted: ' . $totalResults);
    } catch (PDOException $e) {
        error_log('[LIST_INFORMES] ❌ Error en count query: ' . $e->getMessage());
        error_log('[LIST_INFORMES] SQL State: ' . $e->getCode());
        throw $e;
    }
    
    // Construir SELECT dinámicamente según columnas disponibles
    $selectFields = [
        'i.id',
        'i.usuario_id',
        'i.estudio_id',
        'i.study_instance_uid',
        'i.study_id',
        'i.patient_id',
        'i.patient_name',
        'i.titulo',
        'i.estado',
        'i.origen',
        'i.firmado_por',
        'i.firmado_en',
        'i.pdf_path',
        'i.modality',
        'i.study_description',
        'i.fecha_creacion',
        'i.fecha_modificacion',
        'i.version'
    ];
    
    // Agregar columnas PACS solo si existen
    if ($hasPacsInstanceId) {
        $selectFields[] = 'i.pacs_instance_id';
    }
    if ($hasPacsStudyId) {
        $selectFields[] = 'i.pacs_study_id';
    }
    if ($hasPacsSeriesId) {
        $selectFields[] = 'i.pacs_series_id';
    }
    if ($hasFechaEnviadoPacs) {
        $selectFields[] = 'i.fecha_enviado_pacs';
    }
    if (!empty($hasCobranzaCols)) {
        $selectFields[] = 'i.cobranza_regiones';
        $selectFields[] = 'i.cobranza_estudio_planilla';
        $selectFields[] = 'i.cobranza_actualizado_en';
    }
    
    // Agregar campos de usuario y contadores
    $selectFields[] = "COALESCE(u.nombre, 'Usuario desconocido') as usuario_nombre";
    $selectFields[] = "COALESCE(u.apellido, '') as usuario_apellido";
    $selectFields[] = "u.email as usuario_email";
    $selectFields[] = "u.telefono as usuario_telefono";
    $selectFields[] = "u.matricula_profesional as usuario_matricula";
    $selectFields[] = "u.especialidad as usuario_especialidad";
    $selectFields[] = "u.rol as usuario_rol";
    // Agregar datos del médico informante (dueño del estudio - del primer informe)
    $selectFields[] = "COALESCE(
        (SELECT u2.nombre 
         FROM informes i2 
         LEFT JOIN usuarios u2 ON i2.usuario_id = u2.id
         WHERE (i2.estudio_id = i.estudio_id OR i2.study_instance_uid = i.study_instance_uid OR i2.study_id = i.study_id)
         ORDER BY i2.fecha_creacion ASC, i2.id ASC 
         LIMIT 1),
        u.nombre
    ) as medico_informante_nombre";
    $selectFields[] = "COALESCE(
        (SELECT u2.apellido 
         FROM informes i2 
         LEFT JOIN usuarios u2 ON i2.usuario_id = u2.id
         WHERE (i2.estudio_id = i.estudio_id OR i2.study_instance_uid = i.study_instance_uid OR i2.study_id = i.study_id)
         ORDER BY i2.fecha_creacion ASC, i2.id ASC 
         LIMIT 1),
        u.apellido
    ) as medico_informante_apellido";
    $selectFields[] = "COALESCE(
        (SELECT i2.medico_informante_rol 
         FROM informes i2 
         WHERE (i2.estudio_id = i.estudio_id OR i2.study_instance_uid = i.study_instance_uid OR i2.study_id = i.study_id)
         ORDER BY i2.fecha_creacion ASC, i2.id ASC 
         LIMIT 1),
        i.medico_informante_rol,
        (SELECT u2.rol 
         FROM informes i2 
         LEFT JOIN usuarios u2 ON i2.usuario_id = u2.id
         WHERE (i2.estudio_id = i.estudio_id OR i2.study_instance_uid = i.study_instance_uid OR i2.study_id = i.study_id)
         ORDER BY i2.fecha_creacion ASC, i2.id ASC 
         LIMIT 1),
        u.rol
    ) as medico_informante_rol";
    $selectFields[] = "COALESCE(audio_count.total_audios, 0) as total_audios";
    if ($hasTxTables) {
        $selectFields[] = "COALESCE(tx_status.audios_transcribed, 0) as audios_transcribed";
        $selectFields[] = "COALESCE(tx_status.audios_tx_pending, 0) as audios_tx_pending";
        $selectFields[] = "COALESCE(tx_status.audios_tx_stuck, 0) as audios_tx_stuck";
        $selectFields[] = "COALESCE(tx_status.audios_tx_failed, 0) as audios_tx_failed";
    }
    // Contar versiones desde informes_historial + 1 (la versión actual)
    $selectFields[] = "COALESCE(version_info.total_versiones, 1) as total_versiones";
    
    $txStatusJoin = '';
    if ($hasTxTables) {
        $txStatusSubquery = buildInformeTranscriptionStatusSubquery($hasAudioActivo, $hasAudioEstado);
        $txStatusJoin = "LEFT JOIN ({$txStatusSubquery}) tx_status ON i.id = tx_status.informe_id";
    }

    // Construir query completa - mostrar TODOS los informes (no solo últimas versiones)
    $dataQuery = "SELECT " . implode(', ', $selectFields) . "
                  FROM informes i
                  LEFT JOIN usuarios u ON i.usuario_id = u.id 
                  LEFT JOIN (
                      SELECT 
                          i_inner.id as informe_id,
                          COUNT(ai.id) as total_audios 
                      FROM informes i_inner
                      LEFT JOIN audios_informe ai ON i_inner.id = ai.informe_id" . 
                          ($hasAudioActivo ? " AND ai.activo = 1" : "") . "
                      GROUP BY i_inner.id
                  ) audio_count ON i.id = audio_count.informe_id
                  {$txStatusJoin}
                  LEFT JOIN (
                      SELECT 
                          ih.informe_id,
                          COUNT(*) + 1 as total_versiones
                      FROM informes_historial ih
                      GROUP BY ih.informe_id
                  ) version_info ON i.id = version_info.informe_id
                  WHERE 1=1";
    
    // Aplicar filtros
    $dataQuery .= $userFilterCondition;
    
    if (!empty($search)) {
        $dataQuery .= " AND (i.titulo LIKE :search_titulo OR i.patient_name LIKE :search_patient_name OR i.patient_id LIKE :search_patient_id)";
    }
    
    if (!empty($status) && $status !== 'incompletos') {
        $dataQuery .= " AND i.estado = :status";
    } elseif ($status === 'incompletos') {
        $dataQuery .= " AND EXISTS (
            SELECT 1 FROM study_flags sf
            WHERE sf.informes_incompletos = 1
              AND (
                (i.estudio_id IS NOT NULL AND i.estudio_id <> '' AND (sf.study_id = i.estudio_id OR sf.orthanc_id = i.estudio_id))
                OR (i.study_id IS NOT NULL AND i.study_id <> '' AND (sf.study_id = i.study_id OR sf.orthanc_id = i.study_id))
                OR (i.study_instance_uid IS NOT NULL AND i.study_instance_uid <> '' AND sf.study_instance_uid = i.study_instance_uid)
                OR (sf.informe_id IS NOT NULL AND sf.informe_id = i.id)
              )
        )";
    }
    
    if (!empty($modality)) {
        $dataQuery .= " AND i.modality = :modality";
    }
    
    if (!empty($fecha_inicio)) {
        $dataQuery .= " AND DATE(i.fecha_modificacion) >= :fecha_inicio";
    }
    
    if (!empty($fecha_fin)) {
        $dataQuery .= " AND DATE(i.fecha_modificacion) <= :fecha_fin";
    }
    
    $dataQuery .= $filterUsuarioCondition;
    
    // Aplicar ordenamiento y paginación
    if ($sortBy) {
        // Validar y mapear columnas de ordenamiento
        $allowedSortColumns = [
            'patient_name' => 'i.patient_name',
            'patient_id' => 'i.patient_id',
            'modality' => 'i.modality',
            'estado' => 'i.estado',
            'fecha_creacion' => 'i.fecha_creacion',
            'fecha_modificacion' => 'i.fecha_modificacion',
            'usuario_nombre' => 'u.nombre',
            'titulo' => 'i.titulo'
        ];
        
        if (isset($allowedSortColumns[$sortBy])) {
            $sortColumn = $allowedSortColumns[$sortBy];
            $dataQuery .= " ORDER BY {$sortColumn} {$sortOrder}, i.id DESC";
        } else {
            // Si la columna no es válida, usar ordenamiento por defecto (informes más nuevos primero)
            $dataQuery .= " ORDER BY 
                            i.fecha_modificacion DESC,
                            i.fecha_creacion DESC,
                            i.id DESC";
        }
    } else {
        // Ordenamiento por defecto: informes más nuevos primero (por fecha de modificación, luego creación)
        $dataQuery .= " ORDER BY 
                        i.fecha_modificacion DESC,
                        i.fecha_creacion DESC,
                        i.id DESC";
    }
    
    $dataQuery .= " LIMIT :limit OFFSET :offset";
    
    $dataStmt = $db->prepare($dataQuery);
    
    // Vincular parámetros comunes (los mismos que en countQuery)
    foreach ($params as $key => $value) {
        // PDO::PARAM_STR para strings, PDO::PARAM_INT para integers
        $paramType = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $dataStmt->bindValue($key, $value, $paramType);
    }
    
    // Vincular parámetros de paginación
    $dataStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    // Log para debugging
    error_log('[LIST_INFORMES] Data Query listo (len=' . strlen($dataQuery) . ', params=' . count($params) . ')');
    error_log('[LIST_INFORMES] Limit: ' . $limit . ', Offset: ' . $offset);
    
    try {
        $startTime = microtime(true);
        $dataStmt->execute();
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);
        error_log('[LIST_INFORMES] Data query ejecutada en ' . $duration . 'ms');
        
        $informes = $dataStmt->fetchAll();
    } catch (PDOException $e) {
        error_log('[LIST_INFORMES] ❌ Error en data query: ' . $e->getMessage());
        error_log('[LIST_INFORMES] SQL State: ' . $e->getCode());
        throw $e;
    }
    
    // Filtro por instituciones: solo restringe por asignaciones del propio usuario.
    // Con "Ver Todos" ese post-filtro vaciaba la lista al mostrar informes de otros médicos.
    if ($has_filter_institutions && !empty($allowed_institutions) && !$can_view_all) {
        try {
            // Preferir filtro local por tablas de asignaciones para evitar timeouts a Orthanc.
            $studyIdsOnPage = [];
            foreach ($informes as $informe) {
                $sid = $informe['study_id'] ?? $informe['study_instance_uid'] ?? $informe['estudio_id'] ?? null;
                if ($sid !== null && trim((string)$sid) !== '') {
                    $studyIdsOnPage[] = trim((string)$sid);
                }
            }
            $studyIdsOnPage = array_values(array_unique($studyIdsOnPage));

            $allowedStudyMap = [];
            if (!empty($studyIdsOnPage) && $user_id) {
                $studyPh = implode(',', array_fill(0, count($studyIdsOnPage), '?'));
                $instPh = implode(',', array_fill(0, count($allowed_institutions), '?'));

                // Asignaciones directas
                $checkAssignments = $db->query("SHOW TABLES LIKE 'study_assignments'");
                if ($checkAssignments && $checkAssignments->rowCount() > 0) {
                    $q = "SELECT DISTINCT study_id
                          FROM study_assignments
                          WHERE user_id = ?
                            AND status = 'active'
                            AND study_id IN ($studyPh)
                            AND institution_name IS NOT NULL
                            AND UPPER(TRIM(institution_name)) IN ($instPh)";
                    $st = $db->prepare($q);
                    $st->execute(array_merge([$user_id], $studyIdsOnPage, $allowed_institutions));
                    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $sid) {
                        $allowedStudyMap[(string)$sid] = true;
                    }
                }

                // Derivaciones (si existe columna institution_name)
                $checkSubassign = $db->query("SHOW TABLES LIKE 'study_subassignments'");
                if ($checkSubassign && $checkSubassign->rowCount() > 0) {
                    $hasInstCol = $db->query("SHOW COLUMNS FROM study_subassignments LIKE 'institution_name'");
                    if ($hasInstCol && $hasInstCol->rowCount() > 0) {
                        $q = "SELECT DISTINCT study_id
                              FROM study_subassignments
                              WHERE subassigned_to_user_id = ?
                                AND status = 'active'
                                AND study_id IN ($studyPh)
                                AND institution_name IS NOT NULL
                                AND UPPER(TRIM(institution_name)) IN ($instPh)";
                        $st = $db->prepare($q);
                        $st->execute(array_merge([$user_id], $studyIdsOnPage, $allowed_institutions));
                        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $sid) {
                            $allowedStudyMap[(string)$sid] = true;
                        }
                    }
                }
            }

            $filteredInformes = [];
            foreach ($informes as $informe) {
                $studyId = $informe['study_id'] ?? $informe['study_instance_uid'] ?? $informe['estudio_id'] ?? null;
                if ($studyId === null || trim((string)$studyId) === '') {
                    continue;
                }
                if (!isset($allowedStudyMap[(string)$studyId])) {
                    continue;
                }
                $filteredInformes[] = $informe;
            }
            
            $totalBeforeFilter = count($informes);
            $informes = $filteredInformes;
            // NO actualizar totalResults aquí porque filteredInformes solo contiene los de la página actual
            // El totalResults ya fue calculado correctamente antes con el countQuery
            // Solo actualizar si necesitamos recalcular el total con el filtro de instituciones
            // Por ahora, mantener el totalResults original ya que el filtro de instituciones
            // se aplica después de la consulta y solo afecta a los resultados mostrados
            error_log('[LIST_INFORMES] Filtro de instituciones aplicado (local): ' . count($filteredInformes) . ' informes de ' . $totalBeforeFilter . ' en esta página (totalResults: ' . $totalResults . ')');
        } catch (Exception $e) {
            error_log('[LIST_INFORMES] Error aplicando filtro de instituciones: ' . $e->getMessage());
            // En caso de error, continuar sin filtrar
        }
    }
    
    // Procesar los informes para agregar valores por defecto y información adicional
    foreach ($informes as &$informe) {
        // Agregar valores por defecto para campos PACS si no existen
        if (!$hasPacsInstanceId) {
            $informe['pacs_instance_id'] = null;
        }
        if (!$hasPacsStudyId) {
            $informe['pacs_study_id'] = null;
        }
        if (!$hasPacsSeriesId) {
            $informe['pacs_series_id'] = null;
        }
        if (!$hasFechaEnviadoPacs) {
            $informe['fecha_enviado_pacs'] = null;
        }
        if (empty($hasCobranzaCols)) {
            $informe['cobranza_regiones'] = null;
            $informe['cobranza_estudio_planilla'] = null;
            $informe['cobranza_actualizado_en'] = null;
        }
        
        // Agregar información adicional
        $informe['tiene_versiones'] = $informe['total_versiones'] > 1;
        $informe['origen'] = $informe['origen'] ?? 'plataforma';
        $informe['editable'] = ($informe['origen'] !== 'externo');
        $informe['sin_medico_asignado'] = empty($informe['usuario_id']);
        $estadoBadges = [
            'borrador' => 'secondary',
            'transcripto' => 'info',
            'revisado' => 'warning',
            'firmado' => 'primary',
            'finalizado' => 'success',
        ];
        $informe['estado_badge'] = $estadoBadges[$informe['estado'] ?? ''] ?? 'secondary';

        if ($hasTxTables) {
            normalizeInformeTxFields($informe);
        } else {
            applyDefaultInformeTxFields($informe);
        }
    }
    unset($informe); // Liberar la referencia
    
    // Calcular paginación
    $totalPages = $totalResults > 0 ? ceil($totalResults / $limit) : 1;
    
    // Preparar respuesta
    $response = [
        'success' => true,
        'data' => [
            'informes' => $informes,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'total_results' => $totalResults,
                'per_page' => $limit,
                'has_next' => $page < $totalPages,
                'has_prev' => $page > 1
            ],
            'filters' => [
                'search' => $search,
                'status' => $status,
                'modality' => $modality,
                'fecha_inicio' => $fecha_inicio,
                'fecha_fin' => $fecha_fin,
                'filter_usuario_id' => ($filter_usuario_id_req > 0 && $filterUsuarioCondition !== '') ? $filter_usuario_id_req : null
            ],
            'user_permissions' => [
                'can_view_all' => $can_view_all,
                'can_send_to_pacs' => $can_send_to_pacs,
                'can_toggle_pacs_format' => $can_toggle_pacs_format,
                'user_id' => $user_id,
                'can_datos_cobranza' => !empty($can_datos_cobranza),
                'cobranza_columns_installed' => !empty($hasCobranzaCols)
            ]
        ]
    ];
    
    echo json_encode($response);
    
} catch (PDOException $e) {
    error_log('[LIST_INFORMES] Error PDO: ' . $e->getMessage());
    error_log('[LIST_INFORMES] SQL State: ' . $e->getCode());
    error_log('[LIST_INFORMES] Data query fallida (len=' . strlen((string)($dataQuery ?? '')) . ', params=' . count($params ?? []) . ')');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error de base de datos: ' . $e->getMessage(),
        'error_code' => $e->getCode(),
        'sql_state' => $e->getCode()
    ]);
} catch (Exception $e) {
    error_log('[LIST_INFORMES] Error general: ' . $e->getMessage());
    error_log('[LIST_INFORMES] Stack trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error del servidor: ' . $e->getMessage()
    ]);
}
?>
