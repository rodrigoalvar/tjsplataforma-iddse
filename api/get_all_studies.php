<?php
// Configurar manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 0); // No mostrar errores en pantalla
ini_set('log_errors', 1);

// Limpiar cualquier output previo
if (ob_get_level()) {
    ob_end_clean();
}
ob_start(); // Capturar cualquier salida no deseada

// Registrar función para capturar errores fatales
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        error_log('[GET_ALL_STUDIES] Error fatal: ' . $error['message'] . ' en ' . $error['file'] . ':' . $error['line']);
        
        // Limpiar cualquier salida
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Enviar respuesta JSON de error
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
        }
        
        echo json_encode([
            'success' => false,
            'error' => 'Error interno del servidor',
            'debug' => [
                'type' => 'Fatal Error',
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line']
            ]
        ]);
        exit;
    }
});

// Enviar headers solo si no se han enviado ya
if (!headers_sent()) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

/**
 * Normaliza el nombre del paciente corrigiendo caracteres problemáticos
 * - Reemplaza comillas dobles (") por apóstrofes (')
 * - Limpia espacios múltiples
 * - Mantiene el formato DICOM estándar
 */
function normalizePatientName($name) {
    if (empty($name)) {
        return $name;
    }
    
    // Reemplazar comillas dobles por apóstrofes (corrección común)
    $name = str_replace('"', "'", $name);
    
    // Limpiar espacios múltiples
    $name = preg_replace('/\s+/', ' ', $name);
    
    // Trim espacios al inicio y final
    $name = trim($name);
    
    return $name;
}

try {
    // Días con muchos estudios: evitar timeout de PHP-FPM/nginx (502) en consultas pesadas
    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }
    @ini_set('max_execution_time', '300');
    $memLimit = ini_get('memory_limit');
    if ($memLimit !== false && (int)$memLimit > 0 && (int)$memLimit < 384) {
        @ini_set('memory_limit', '512M');
    }

    require_once 'OrthancClient.php';
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../classes/User.php';
    
    // Log de configuración de Orthanc
    require_once __DIR__ . '/config/orthanc_config.php';
    $orthancConfig = OrthancConfig::getConfig();
    error_log('[GET_ALL_STUDIES] Configuración Orthanc: ' . json_encode([
        'host' => $orthancConfig['server']['host'],
        'port' => $orthancConfig['server']['port'],
        'protocol' => $orthancConfig['server']['protocol'],
        'url' => OrthancConfig::getServerUrl()
    ]));
    
    $orthancClient = new OrthancClient();
    
    // Verificar conexión
    $serverStatus = $orthancClient->getServerStatus();
    error_log('[GET_ALL_STUDIES] Estado del servidor: ' . json_encode($serverStatus));
    
    if ($serverStatus['status'] !== 'connected') {
        throw new Exception('No se puede conectar al servidor Orthanc: ' . ($serverStatus['message'] ?? 'Error desconocido'));
    }
    
    // Verificar permisos del usuario actual
    $currentUserId = null;
    $user_permisos = [];
    $userLevel = '';
    $hasPacsQuery = false;
    $allowedStudyIds = []; // IDs de estudios permitidos para usuarios sin PACS QUERY
    
    // Verificar sesión
    $session_token = null;
    if (isset($_COOKIE['session_token']) && !empty($_COOKIE['session_token'])) {
        $session_token = $_COOKIE['session_token'];
    }
    
    if ($session_token) {
        try {
            $user = new User();
            $user_data = $user->validateSession($session_token);
            
            if ($user_data) {
                $currentUserId = $user_data['id'];
                
                // Verificar permisos
                $user_permisos = $user_data['permisos'] ?? [];
                if (is_string($user_permisos)) {
                    $user_permisos = json_decode($user_permisos, true) ?: [];
                }
                if (!is_array($user_permisos)) {
                    $user_permisos = [];
                }
                
                error_log('[GET_ALL_STUDIES] Usuario ID: ' . $currentUserId . ', Nivel: ' . ($user_data['nivel'] ?? 'N/A'));
                error_log('[GET_ALL_STUDIES] Permisos del usuario: ' . json_encode($user_permisos));
                
                // Usuarios root tienen acceso automático al PACS
                $userLevel = $user_data['nivel'] ?? '';
                if ($userLevel === 'root') {
                    $hasPacsQuery = true;
                    error_log('[GET_ALL_STUDIES] Usuario ROOT detectado - hasPacsQuery = true (acceso automático)');
                } else {
                    $hasPacsQuery = in_array('all', $user_permisos) || 
                                    in_array('pacs_query', $user_permisos);
                    error_log('[GET_ALL_STUDIES] hasPacsQuery: ' . ($hasPacsQuery ? 'true' : 'false'));
                }
                
                // Verificar si tiene permiso de filtro por instituciones
                // Usuarios root NO tienen filtro de instituciones (acceso completo)
                $hasFilterInstitutions = false;
                $allowedInstitutions = [];
                
                if ($userLevel !== 'root' && in_array('filter_institutions', $user_permisos) && $currentUserId) {
                    $hasFilterInstitutions = true;
                    // Obtener instituciones permitidas del usuario
                    $pdo = getDBConnection();
                    $institucionesQuery = "SELECT instituciones_permitidas FROM usuarios WHERE id = ? AND activo = 1";
                    $institucionesStmt = $pdo->prepare($institucionesQuery);
                    $institucionesStmt->execute([$currentUserId]);
                    $institucionesResult = $institucionesStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($institucionesResult && !empty($institucionesResult['instituciones_permitidas'])) {
                        $allowedInstitutions = json_decode($institucionesResult['instituciones_permitidas'], true) ?: [];
                        // Limpiar espacios en blanco y convertir a mayúsculas para comparación
                        $allowedInstitutions = array_map(function($inst) {
                            return trim(strtoupper($inst));
                        }, $allowedInstitutions);
                        error_log("Usuario $currentUserId con filtro de instituciones - Instituciones permitidas: " . implode(', ', $allowedInstitutions));
                    }
                }
                
                // Si NO tiene PACS QUERY, obtener estudios asignados/derivados
                if (!$hasPacsQuery && $currentUserId) {
                    $pdo = getDBConnection();
                    
                    // Obtener estudios asignados directamente
                    $assignedQuery = "SELECT DISTINCT study_id FROM study_assignments 
                                      WHERE user_id = ? AND status = 'active'";
                    $stmt = $pdo->prepare($assignedQuery);
                    $stmt->execute([$currentUserId]);
                    $assignedStudies = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    // Obtener estudios derivados (subasignados)
                    $subassignedQuery = "SELECT DISTINCT study_id FROM study_subassignments 
                                         WHERE subassigned_to_user_id = ? AND status = 'active'";
                    $stmt = $pdo->prepare($subassignedQuery);
                    $stmt->execute([$currentUserId]);
                    $subassignedStudies = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    // Combinar ambos arrays
                    $allowedStudyIds = array_unique(array_merge($assignedStudies, $subassignedStudies));
                    
                    error_log("Usuario $currentUserId sin PACS QUERY - Estudios permitidos: " . count($allowedStudyIds));
                }
            }
        } catch (Exception $e) {
            error_log('Error verificando sesión: ' . $e->getMessage());
        }
    }
    
    // Obtener parámetros de filtro
    $dateFrom = $_GET['dateFrom'] ?? null;
    $dateTo = $_GET['dateTo'] ?? null;
    $patientId = $_GET['patientId'] ?? null;
    $modality = $_GET['modality'] ?? null;
    // Igual que idimagenes: cargar modalidades desde cada serie (más peticiones HTTP).
    // Por defecto false para no penalizar el listado general; el modal "Adjuntar informe" pide loadModalities=1.
    $loadModalities = isset($_GET['loadModalities'])
        && ($_GET['loadModalities'] === '1' || $_GET['loadModalities'] === 'true' || $_GET['loadModalities'] === true);
    
    error_log('[GET_ALL_STUDIES] Parámetros de filtro: ' . json_encode([
        'dateFrom' => $dateFrom,
        'dateTo' => $dateTo,
        'patientId' => $patientId,
        'modality' => $modality,
        'loadModalities' => $loadModalities
    ]));
    
    // Usar el método eficiente para obtener estudios con filtros
    // loadDetails true cuando loadModalities: rellena modality como en tjsidimagenes (consulta /series por estudio)
    // true: workaround Orthanc día único (from=to) que en este flujo provocaba 502; pacs-manager no lo usa.
    $studies = $orthancClient->getAllStudiesEfficient($dateFrom, $dateTo, $patientId, $modality, $loadModalities, true);
    
    error_log('[GET_ALL_STUDIES] Estudios obtenidos del PACS: ' . count($studies));

    $mixedSearchApplied = false;
    $mixedSearch = isset($_GET['mixed_search']) && ($_GET['mixed_search'] === '1' || $_GET['mixed_search'] === 'true');
    $remoteNodeIdMix = (int) ($_GET['remote_node_id'] ?? 0);
    $canMixedSearch = $mixedSearch && $remoteNodeIdMix > 0 && $hasPacsQuery && $session_token
        && ($userLevel === 'root' || in_array('all', $user_permisos, true) || in_array('estudios_mixed_search', $user_permisos, true));
    if ($canMixedSearch) {
        require_once __DIR__ . '/helpers/estudios_mixed_remote.php';
        $pdoMix = getDBConnection();
        $studies = estudios_mixed_merge_remote_into_local($studies, $pdoMix, $remoteNodeIdMix, $dateFrom, $dateTo, $patientId, 200);
        $mixedSearchApplied = true;
        error_log('[GET_ALL_STUDIES] Búsqueda mixta aplicada (nodo remoto ' . $remoteNodeIdMix . '): total filas ' . count($studies));
    }
    
    // Obtener IDs de estudios que están en el PACS para identificar huérfanos
    $pacsStudyIds = [];
    $pacsStudyInstanceUIDs = [];
    foreach ($studies as $study) {
        if (!empty($study['orthanc_id'])) {
            $pacsStudyIds[] = $study['orthanc_id'];
        }
        if (!empty($study['study_instance_uid'])) {
            $pacsStudyInstanceUIDs[] = $study['study_instance_uid'];
        }
    }
    
    // Obtener estudios huérfanos (eliminados del PACS pero que siguen en asignaciones)
    $orphanedStudies = [];
    $pacsStudyIdSet = !empty($pacsStudyIds) ? array_flip($pacsStudyIds) : [];
    try {
        $pdo = getDBConnection();
        
        // Construir consulta para obtener estudios huérfanos de study_assignments
        // Solo estudios que NO están en el PACS
        $orphanQuery = "
            SELECT DISTINCT
                sa.study_id,
                sa.orthanc_study_id,
                sa.study_instance_uid,
                sa.patient_name,
                sa.patient_id,
                sa.patient_birth_date,
                sa.patient_sex,
                sa.study_date,
                sa.study_time,
                sa.modality,
                sa.study_description,
                sa.accession_number,
                sa.referring_physician,
                sa.series_count,
                sa.instances_count,
                sa.viewer_url,
                sa.institution_name
            FROM study_assignments sa
            WHERE sa.status = 'active'
        ";
        
        $orphanParams = [];
        $orphanConditions = [];
        
        // Filtrar estudios que NO están en el PACS
        if (!empty($pacsStudyIds)) {
            $placeholders = str_repeat('?,', count($pacsStudyIds) - 1) . '?';
            $orphanConditions[] = "(sa.study_id NOT IN ($placeholders) AND (sa.orthanc_study_id IS NULL OR sa.orthanc_study_id NOT IN ($placeholders)))";
            $orphanParams = array_merge($orphanParams, $pacsStudyIds, $pacsStudyIds);
        } else {
            // Si no hay estudios en PACS, todos los de study_assignments son potencialmente huérfanos
            // Pero solo si tienen orthanc_study_id (fueron asignados desde PACS)
            $orphanConditions[] = "sa.orthanc_study_id IS NOT NULL";
        }
        
        // Aplicar filtros de fecha si están presentes
        if ($dateFrom) {
            $orphanConditions[] = "sa.study_date >= ?";
            $orphanParams[] = $dateFrom;
        }
        if ($dateTo) {
            $orphanConditions[] = "sa.study_date <= ?";
            $orphanParams[] = $dateTo;
        }
        
        // Aplicar filtro de patient_id si está presente
        if ($patientId) {
            $orphanConditions[] = "sa.patient_id LIKE ?";
            $orphanParams[] = '%' . $patientId . '%';
        }
        
        // Aplicar filtro de modalidad si está presente
        if ($modality && $modality !== 'all') {
            $orphanConditions[] = "sa.modality LIKE ?";
            $orphanParams[] = '%' . $modality . '%';
        }
        
        // Si el usuario NO tiene PACS QUERY, solo mostrar estudios asignados/derivados
        if (!$hasPacsQuery && !empty($allowedStudyIds)) {
            $placeholders = str_repeat('?,', count($allowedStudyIds) - 1) . '?';
            $orphanConditions[] = "sa.study_id IN ($placeholders)";
            $orphanParams = array_merge($orphanParams, $allowedStudyIds);
        } elseif (!$hasPacsQuery && empty($allowedStudyIds)) {
            // Usuario sin PACS QUERY y sin estudios asignados = no mostrar huérfanos
            $orphanConditions[] = "1 = 0"; // Condición imposible
        }
        
        // Aplicar filtro de instituciones si está presente
        if ($hasFilterInstitutions && !empty($allowedInstitutions)) {
            $institutionPlaceholders = str_repeat('?,', count($allowedInstitutions) - 1) . '?';
            $orphanConditions[] = "(sa.institution_name IS NULL OR UPPER(TRIM(sa.institution_name)) IN ($institutionPlaceholders))";
            $orphanParams = array_merge($orphanParams, $allowedInstitutions);
        }
        
        if (!empty($orphanConditions)) {
            $orphanQuery .= " AND " . implode(" AND ", $orphanConditions);
        }
        
        $orphanQuery .= " ORDER BY sa.study_date DESC, sa.study_time DESC";
        
        $orphanStmt = $pdo->prepare($orphanQuery);
        $orphanStmt->execute($orphanParams);
        $orphanedStudiesData = $orphanStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Convertir estudios huérfanos al formato esperado
        foreach ($orphanedStudiesData as $orphan) {
            $orphanStudyId = $orphan['orthanc_study_id'] ?? $orphan['study_id'];
            
            // Verificar que realmente no esté en el PACS (doble verificación)
            $inPacs = $pacsStudyIdSet !== [] && isset($pacsStudyIdSet[$orphanStudyId]);
            if (!empty($orphanStudyId) && !$inPacs) {
                $orphanedStudies[] = [
                    'orthanc_id' => $orphanStudyId,
                    'study_id' => $orphanStudyId,
                    'study_instance_uid' => $orphan['study_instance_uid'] ?? '',
                    'patient_name' => normalizePatientName($orphan['patient_name'] ?? ''),
                    'patient_id' => $orphan['patient_id'] ?? '',
                    'patient_birth_date' => $orphan['patient_birth_date'] ?? '',
                    'patient_sex' => $orphan['patient_sex'] ?? '',
                    'study_date' => $orphan['study_date'] ?? '',
                    'study_time' => $orphan['study_time'] ?? '',
                    'modality' => $orphan['modality'] ?? '',
                    'study_description' => $orphan['study_description'] ?? '',
                    'accession_number' => $orphan['accession_number'] ?? '',
                    'referring_physician' => $orphan['referring_physician'] ?? '',
                    'institution_name' => $orphan['institution_name'] ?? '',
                    'series_count' => (int)($orphan['series_count'] ?? 0),
                    'instances_count' => (int)($orphan['instances_count'] ?? 0),
                    'viewer_url' => $orphan['viewer_url'] ?? '',
                    '_is_orphan' => true, // Flag para identificar estudios huérfanos
                    '_orphaned_from_pacs' => true
                ];
            }
        }
        
        error_log('[GET_ALL_STUDIES] Estudios huérfanos encontrados: ' . count($orphanedStudies));
        
    } catch (Exception $e) {
        error_log('[GET_ALL_STUDIES] Error obteniendo estudios huérfanos: ' . $e->getMessage());
        // Continuar sin estudios huérfanos si hay error
    }
    
    // Combinar estudios del PACS con estudios huérfanos
    $allStudies = array_merge($studies, $orphanedStudies);
    
    error_log('[GET_ALL_STUDIES] Total estudios antes de filtrar: ' . count($allStudies) . ' (PACS: ' . count($studies) . ', Huérfanos: ' . count($orphanedStudies) . ')');
    
    // Obtener visor del usuario si está autenticado
    $userViewerType = 'UDV'; // Valor por defecto
    if ($currentUserId) {
        try {
            $pdo = getDBConnection();
            $viewerQuery = "SELECT dicom_viewer FROM usuarios WHERE id = ? AND activo = 1";
            $viewerStmt = $pdo->prepare($viewerQuery);
            $viewerStmt->execute([$currentUserId]);
            $viewerResult = $viewerStmt->fetch(PDO::FETCH_ASSOC);
            
            error_log("🔍 Debug - Usuario ID: $currentUserId, dicom_viewer obtenido: " . ($viewerResult['dicom_viewer'] ?? 'NULL'));
            
            if ($viewerResult && isset($viewerResult['dicom_viewer'])) {
                // Si el valor es NULL o está vacío, usar el valor por defecto
                if (!empty($viewerResult['dicom_viewer']) && trim($viewerResult['dicom_viewer']) !== '') {
                    $userViewerType = trim($viewerResult['dicom_viewer']);
                    error_log("✅ Usando visor del usuario: $userViewerType");
                } else {
                    error_log("⚠️ dicom_viewer está vacío o NULL para usuario $currentUserId, usando UDV por defecto");
                }
            } else {
                error_log("⚠️ No se encontró registro de usuario $currentUserId para obtener dicom_viewer");
            }
        } catch (Exception $e) {
            error_log('❌ Error obteniendo visor del usuario: ' . $e->getMessage());
        }
    } else {
        error_log("⚠️ No hay currentUserId, usando UDV por defecto");
    }
    
    error_log("🎯 Visor final que se usará: $userViewerType");
    
    // Requerir OrthancConfig para generar viewer_url personalizado
    require_once __DIR__ . '/config/orthanc_config.php';
    require_once __DIR__ . '/StudyRoutingService.php';

    // Study routing (R2 manifest / ZIP) — precarga contexto R2
    $pdoRouting = getDBConnection();
    $effectiveStudyRouting = 'local';
    if ($pdoRouting) {
        $effectiveStudyRouting = StudyRoutingService::getEffectiveMode(
            $pdoRouting,
            $currentUserId ? (int)$currentUserId : null,
            $user_permisos,
            (string)$userLevel
        );
    }
    $allRoutingIds = [];
    foreach ($allStudies as $_s) {
        if (!empty($_s['orthanc_id'])) {
            $allRoutingIds[] = $_s['orthanc_id'];
        }
    }
    $r2RoutingBatch = ($pdoRouting && $allRoutingIds !== [])
        ? StudyRoutingService::batchLoadR2Context($pdoRouting, $allRoutingIds)
        : [];
    
    // Obtener información de informes para cada estudio
    // Usar study_instance_uid para vincular con la tabla de informes
    $studyInstanceUIDs = [];
    $studyMapping = []; // Mapear orthanc_id -> study_instance_uid
    $orthancStudyIds = []; // IDs de Orthanc para cotejo adicional
    
    foreach ($allStudies as $study) {
        if (!empty($study['study_instance_uid'])) {
            $studyInstanceUIDs[] = $study['study_instance_uid'];
            $studyMapping[$study['orthanc_id']] = $study['study_instance_uid'];
        }
        if (!empty($study['orthanc_id'])) {
            $orthancStudyIds[] = $study['orthanc_id'];
        }
    }
    
    /** Informes en BD (todos): badge, contadores, tooltips */
    $reportInfo = [];
    /** Claves (estudio_id / study_instance_uid / study_id) con al menos un informe ya publicado en PACS (modalidad DOC) */
    $pacsPublishedKeys = [];
    /** Condición SQL extra para informes enviados a PACS (vacío si no hay columnas o sin datos) */
    $pacsCondition = '';
    
    if (!empty($studyInstanceUIDs) || !empty($orthancStudyIds)) {
        try {
            $pdo = getDBConnection();
            
            // Verificar qué columnas PACS existen
            $checkColumnsQuery = "SHOW COLUMNS FROM informes";
            $checkColumnsStmt = $pdo->query($checkColumnsQuery);
            $columns = $checkColumnsStmt->fetchAll(PDO::FETCH_COLUMN);
            
            $hasPacsInstanceId = in_array('pacs_instance_id', $columns);
            $hasFechaEnviadoPacs = in_array('fecha_enviado_pacs', $columns);
            
            // Construir condición para verificar que está EN PACS (solo para DOC / published_to_pacs)
            if ($hasPacsInstanceId && $hasFechaEnviadoPacs) {
                $pacsCondition = " AND ((i.pacs_instance_id IS NOT NULL AND i.pacs_instance_id != '') OR i.fecha_enviado_pacs IS NOT NULL)";
            } elseif ($hasPacsInstanceId) {
                $pacsCondition = " AND (i.pacs_instance_id IS NOT NULL AND i.pacs_instance_id != '')";
            } elseif ($hasFechaEnviadoPacs) {
                $pacsCondition = " AND i.fecha_enviado_pacs IS NOT NULL";
            }
            
            $uniqueOrthancIds = array_values(array_unique(array_filter($orthancStudyIds)));
            $reportChunkSize = 250;

            $mergeReportRowAll = function (array $report) use (&$reportInfo) {
                $total = (int)$report['total_informes'];
                $reportDataItem = [
                    'has_report' => $total > 0,
                    'total_informes' => $total,
                    'ultimo_estado' => $report['ultimo_estado'],
                    'ultima_fecha_informe' => $report['ultima_fecha_informe'],
                    'estados_disponibles' => !empty($report['estados_disponibles']) ? explode(',', $report['estados_disponibles']) : []
                ];
                if (!empty($report['estudio_id'])) {
                    $reportInfo[$report['estudio_id']] = $reportDataItem;
                }
                if (!empty($report['study_instance_uid'])) {
                    $reportInfo[$report['study_instance_uid']] = $reportDataItem;
                }
                if (!empty($report['study_id'])) {
                    $reportInfo[$report['study_id']] = $reportDataItem;
                }
            };

            $markPacsPublishedKeys = function (array $report) use (&$pacsPublishedKeys) {
                foreach (['estudio_id', 'study_instance_uid', 'study_id'] as $col) {
                    if (!empty($report[$col])) {
                        $pacsPublishedKeys[$report[$col]] = true;
                    }
                }
            };

            if (!empty($uniqueOrthancIds)) {
                foreach (array_chunk($uniqueOrthancIds, $reportChunkSize) as $idChunk) {
                    $uidChunk = [];
                    foreach ($idChunk as $oid) {
                        if (!empty($studyMapping[$oid])) {
                            $uidChunk[] = $studyMapping[$oid];
                        }
                    }
                    $uidChunk = array_values(array_unique($uidChunk));

                    $conditions = [];
                    $params = [];
                    if (!empty($uidChunk)) {
                        $placeholders = str_repeat('?,', count($uidChunk) - 1) . '?';
                        $conditions[] = "i.study_instance_uid IN ($placeholders)";
                        $params = array_merge($params, $uidChunk);
                    }
                    if (in_array('study_id', $columns)) {
                        $placeholders = str_repeat('?,', count($idChunk) - 1) . '?';
                        $conditions[] = "i.study_id IN ($placeholders)";
                        $params = array_merge($params, $idChunk);
                    }
                    $placeholders = str_repeat('?,', count($idChunk) - 1) . '?';
                    $conditions[] = "i.estudio_id IN ($placeholders)";
                    $params = array_merge($params, $idChunk);

                    if (empty($conditions)) {
                        continue;
                    }
                    $whereClause = "(" . implode(" OR ", $conditions) . ")";
                    $queryBase = "
                        SELECT 
                            i.estudio_id,
                            i.study_instance_uid,
                            i.study_id,
                            COUNT(*) as total_informes,
                            MAX(i.estado) as ultimo_estado,
                            MAX(i.fecha_creacion) as ultima_fecha_informe,
                            GROUP_CONCAT(DISTINCT i.estado) as estados_disponibles
                        FROM informes i
                        WHERE $whereClause
                        %s
                        GROUP BY i.estudio_id, i.study_instance_uid, i.study_id
                    ";
                    // 1) Todos los informes (badge / estado en dashboard)
                    $stmt = $pdo->prepare(sprintf($queryBase, ''));
                    $stmt->execute($params);
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $report) {
                        $mergeReportRowAll($report);
                    }
                    // 2) Solo filas publicadas en PACS (DOC en modalidad)
                    if ($pacsCondition !== '') {
                        $stmtP = $pdo->prepare(sprintf($queryBase, $pacsCondition));
                        $stmtP->execute($params);
                        foreach ($stmtP->fetchAll(PDO::FETCH_ASSOC) as $report) {
                            $markPacsPublishedKeys($report);
                        }
                    }
                }
            } else {
                $uniqueStudyUids = array_values(array_unique(array_filter($studyInstanceUIDs)));
                foreach (array_chunk($uniqueStudyUids, $reportChunkSize) as $uidChunk) {
                    if (empty($uidChunk)) {
                        continue;
                    }
                    $placeholders = str_repeat('?,', count($uidChunk) - 1) . '?';
                    $whereClause = "i.study_instance_uid IN ($placeholders)";
                    $params = $uidChunk;
                    $queryBase = "
                        SELECT 
                            i.estudio_id,
                            i.study_instance_uid,
                            i.study_id,
                            COUNT(*) as total_informes,
                            MAX(i.estado) as ultimo_estado,
                            MAX(i.fecha_creacion) as ultima_fecha_informe,
                            GROUP_CONCAT(DISTINCT i.estado) as estados_disponibles
                        FROM informes i
                        WHERE $whereClause
                        %s
                        GROUP BY i.estudio_id, i.study_instance_uid, i.study_id
                    ";
                    $stmt = $pdo->prepare(sprintf($queryBase, ''));
                    $stmt->execute($params);
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $report) {
                        $mergeReportRowAll($report);
                    }
                    if ($pacsCondition !== '') {
                        $stmtP = $pdo->prepare(sprintf($queryBase, $pacsCondition));
                        $stmtP->execute($params);
                        foreach ($stmtP->fetchAll(PDO::FETCH_ASSOC) as $report) {
                            $markPacsPublishedKeys($report);
                        }
                    }
                }
            }

            if (!empty($uniqueOrthancIds)) {
                foreach (array_chunk($uniqueOrthancIds, $reportChunkSize) as $idChunk) {
                    $audioPlaceholders = str_repeat('?,', count($idChunk) - 1) . '?';
                    $audioQuery = "
                        SELECT 
                            estudio_id,
                            COUNT(*) as total_audios
                        FROM audios_informe 
                        WHERE estudio_id IN ($audioPlaceholders) AND activo = TRUE
                        GROUP BY estudio_id
                    ";
                    $audioStmt = $pdo->prepare($audioQuery);
                    $audioStmt->execute($idChunk);
                    $audioData = $audioStmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($audioData as $audio) {
                        if (isset($reportInfo[$audio['estudio_id']])) {
                            $reportInfo[$audio['estudio_id']]['total_audios'] = (int)$audio['total_audios'];
                        } else {
                            $reportInfo[$audio['estudio_id']] = [
                                'has_report' => false,
                                'total_informes' => 0,
                                'ultimo_estado' => null,
                                'ultima_fecha_informe' => null,
                                'estados_disponibles' => [],
                                'total_audios' => (int)$audio['total_audios']
                            ];
                        }
                    }
                }
            }
            
        } catch (Exception $e) {
            // Si hay error en la consulta de informes, continuar sin esa información
            error_log('Error consultando informes: ' . $e->getMessage());
        }
    }
    
    // Formatear datos para el dashboard
    $formattedStudies = [];
    $skippedCount = 0;
    $skippedReasons = ['no_id' => 0, 'no_pacs_query' => 0, 'institution_filter' => 0];
    
    foreach ($allStudies as $study) {
        $isRemoteOnly = !empty($study['em_remote_only']);
        if (!$isRemoteOnly && (!isset($study['orthanc_id']) || !$study['orthanc_id'])) {
            $skippedReasons['no_id']++;
            continue; // Saltar estudios sin ID válido
        }

        $studyId = $isRemoteOnly ? trim((string) ($study['study_instance_uid'] ?? '')) : $study['orthanc_id'];
        if ($isRemoteOnly && $studyId === '') {
            $skippedReasons['no_id']++;
            continue;
        }

        $isOrphan = isset($study['_is_orphan']) && $study['_is_orphan'] === true;
        
        // Si el usuario NO tiene PACS QUERY, filtrar solo estudios permitidos
        // (Los estudios huérfanos ya fueron filtrados en la consulta)
        if (!$hasPacsQuery && !empty($allowedStudyIds) && is_array($allowedStudyIds)) {
            if (!in_array($studyId, $allowedStudyIds)) {
                $skippedReasons['no_pacs_query']++;
                continue; // Saltar estudios no asignados/derivados
            }
        } elseif (!$hasPacsQuery && (empty($allowedStudyIds) || !is_array($allowedStudyIds))) {
            // Usuario sin PACS QUERY y sin estudios asignados = no mostrar nada
            $skippedReasons['no_pacs_query']++;
            continue;
        }
        
        // Si tiene permiso de filtro por instituciones, aplicar el filtro
        if ($hasFilterInstitutions && !empty($allowedInstitutions) && is_array($allowedInstitutions)) {
            $studyInstitution = isset($study['institution_name']) ? trim(strtoupper($study['institution_name'])) : '';
            if (empty($studyInstitution) || !in_array($studyInstitution, $allowedInstitutions)) {
                $skippedReasons['institution_filter']++;
                continue; // Saltar estudios de instituciones no permitidas
            }
        }
        
        $studyInstanceUID = $study['study_instance_uid'] ?? '';
        $studyIdLegacy = $study['study_id'] ?? '';

        // Resolver bloque report_info por cualquier identificador conocido
        $reportBlock = null;
        foreach ([$studyInstanceUID, $studyId, $studyIdLegacy] as $rk) {
            if ($rk !== null && $rk !== '' && isset($reportInfo[$rk])) {
                $reportBlock = $reportInfo[$rk];
                break;
            }
        }

        $hasReportAny = $reportBlock && ((int)($reportBlock['total_informes'] ?? 0)) > 0;

        // Informe publicado en PACS (modalidad DOC): columnas PACS presentes; si no hay columnas, compatibilidad con comportamiento previo
        $hasReportInPacs = false;
        if ($pacsCondition === '') {
            $hasReportInPacs = $hasReportAny;
        } else {
            foreach ([$studyInstanceUID, $studyId, $studyIdLegacy] as $pk) {
                if ($pk !== null && $pk !== '' && isset($pacsPublishedKeys[$pk])) {
                    $hasReportInPacs = true;
                    break;
                }
            }
        }
        
        // Determinar modalidad base
        $baseModality = $study['modality'] ?? '';
        
        // Combinar modalidades si hay informe publicado en PACS (o sin columnas PACS: cualquier informe)
        $finalModality = $baseModality;
        if ($hasReportInPacs && !empty($baseModality) && strpos($baseModality, 'DOC') === false) {
            $finalModality = $baseModality . ', DOC';
        }
        
        // Para estudios huérfanos, usar viewer_url de la base de datos si está disponible
        $viewerUrl = '';
        if ($isOrphan && !empty($study['viewer_url'])) {
            $viewerUrl = $study['viewer_url'];
        } else {
            $viewerUrl = $isRemoteOnly ? '' : OrthancConfig::getViewerUrl($studyId, $userViewerType, $studyInstanceUID);
        }

        $localDownloadUrl = $isRemoteOnly ? '' : StudyRoutingService::buildLocalOrthancArchiveUrl(
            $studyId,
            (string)($study['patient_id'] ?? ''),
            (string)($study['patient_name'] ?? ''),
            (string)($study['study_date'] ?? ''),
            (string)($study['study_description'] ?? '')
        );
        $studyRoutingMeta = [
            'mode_effective' => $effectiveStudyRouting,
            'source_viewer' => 'local',
            'source_download' => 'local',
            'delivery' => null,
            'fallback' => null,
        ];
        if (!$isOrphan && !$isRemoteOnly && $pdoRouting) {
            $r2row = $r2RoutingBatch[$studyId] ?? [
                'orthanc_study_id' => $studyId,
                'study_instance_uid' => $studyInstanceUID,
                'r2_status' => 'none',
                'r2_manifest_path' => null,
                'r2_zip_key' => null,
            ];
            $resolved = StudyRoutingService::resolve(
                $pdoRouting,
                $studyId,
                $studyInstanceUID,
                $viewerUrl,
                $localDownloadUrl,
                $effectiveStudyRouting,
                $r2row
            );
            $viewerUrl = $resolved['viewer_url'];
            $localDownloadUrl = $resolved['download_url'];
            $studyRoutingMeta = array_merge($studyRoutingMeta, $resolved['study_routing']);
            $studyRoutingMeta['mode_effective'] = $effectiveStudyRouting;
        }
        
        $formattedStudies[] = [
            'id' => $studyId, // ID único usando orthanc_id
            'date' => $study['study_date'] ?? '',
            'time' => $study['study_time'] ?? '',
            'patient_name' => normalizePatientName($study['patient_name'] ?? ''),
            'patient_id' => $study['patient_id'] ?? '',
            'patient_birth_date' => $study['patient_birth_date'] ?? '',
            'patient_sex' => $study['patient_sex'] ?? '',
            'modality' => $finalModality,
            'study_description' => $study['study_description'] ?? '',
            'study_instance_uid' => $study['study_instance_uid'] ?? '',
            'status' => $isRemoteOnly ? 'Solo en PACS remoto' : ($isOrphan ? 'Eliminado del PACS' : 'Completado'),
            'orthanc_study_id' => $isRemoteOnly ? '' : $studyId,
            'study_id' => $studyId,
            'orthanc_id' => $isRemoteOnly ? '' : $studyId,
            'em_remote_only' => $isRemoteOnly,
            'em_remote_node_id' => $isRemoteOnly ? (int) ($study['em_remote_node_id'] ?? 0) : null,
            'viewer_url' => $viewerUrl,
            'download_url' => $localDownloadUrl,
            'study_routing' => $studyRoutingMeta,
            'accession_number' => $study['accession_number'] ?? '',
            'referring_physician' => $study['referring_physician'] ?? '',
            'institution_name' => $study['institution_name'] ?? '',
            'series_count' => $study['series_count'] ?? 0,
            'instances_count' => $study['instances_count'] ?? 0,
            
            // Flag para identificar estudios huérfanos
            'is_orphan' => $isOrphan,
            'orphaned_from_pacs' => $isOrphan,
            
            // Información de informes y audios
            'has_report' => $hasReportAny,
            'report_info' => $reportBlock ? array_merge($reportBlock, [
                'published_to_pacs' => $hasReportInPacs,
                'has_report_in_pacs' => $hasReportInPacs
            ]) : [
                'has_report' => false,
                'total_informes' => 0,
                'ultimo_estado' => null,
                'ultima_fecha_informe' => null,
                'estados_disponibles' => [],
                'total_audios' => 0,
                'published_to_pacs' => false,
                'has_report_in_pacs' => false
            ]
        ];
    }
    
    // Ordenar por fecha descendente
    usort($formattedStudies, function($a, $b) {
        return strcmp($b['date'], $a['date']);
    });
    
    error_log('[GET_ALL_STUDIES] Estudios después de filtrar: ' . count($formattedStudies));
    if (isset($skippedReasons)) {
        error_log('[GET_ALL_STUDIES] Estudios saltados: ' . json_encode($skippedReasons));
    }
    
    // Limpiar cualquier output no deseado antes de enviar JSON
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Asegurar que los headers estén enviados
    if (!headers_sent()) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
    }
    
    $response = [
        'success' => true,
        'data' => $formattedStudies,
        'total_studies' => count($formattedStudies),
        'server_status' => $serverStatus
    ];
    
    // Agregar información de debug si está disponible
    if (isset($hasPacsQuery)) {
        $response['debug'] = [
            'hasPacsQuery' => $hasPacsQuery,
            'hasFilterInstitutions' => isset($hasFilterInstitutions) ? $hasFilterInstitutions : false,
            'allowedInstitutions_count' => isset($allowedInstitutions) && is_array($allowedInstitutions) ? count($allowedInstitutions) : 0,
            'allowedStudyIds_count' => isset($allowedStudyIds) && is_array($allowedStudyIds) ? count($allowedStudyIds) : 0,
            'pacs_studies_count' => count($studies),
            'orphaned_studies_count' => isset($orphanedStudies) ? count($orphanedStudies) : 0,
            'skipped_reasons' => isset($skippedReasons) ? $skippedReasons : [],
            'mixed_search_applied' => !empty($mixedSearchApplied),
        ];
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    // Limpiar cualquier salida no deseada
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Log del error
    error_log('[GET_ALL_STUDIES] Exception: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    
    // Enviar respuesta de error solo si los headers no se han enviado
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
    }
    
    echo json_encode([
        'success' => false,
        'error' => 'Error al obtener estudios: ' . $e->getMessage(),
        'debug' => [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]
    ]);
    exit;
} catch (Error $e) {
    // Limpiar cualquier salida no deseada
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Log del error
    error_log('[GET_ALL_STUDIES] Fatal Error: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    
    // Enviar respuesta de error solo si los headers no se han enviado
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
    }
    
    echo json_encode([
        'success' => false,
        'error' => 'Error fatal: ' . $e->getMessage(),
        'debug' => [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]
    ]);
    exit;
}
?>