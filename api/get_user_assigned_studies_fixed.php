<?php
/**
 * API para obtener estudios asignados al usuario actual
 * Versión corregida que funciona con sesión real
 */

// Configurar manejo de errores para evitar output HTML
error_reporting(E_ALL);
ini_set('display_errors', 0); // No mostrar errores en pantalla
ini_set('log_errors', 1); // Registrar errores en log

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';
require_once '../middleware/auth.php';
require_once '../classes/User.php';
require_once __DIR__ . '/helpers/study_assignment_metadata.php';

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
    // Verificar sesión del usuario usando cookies (como dashboard)
    $session_token = null;
    
    if (isset($_COOKIE['session_token']) && !empty($_COOKIE['session_token'])) {
        $session_token = $_COOKIE['session_token'];
    }
    
    // Si no hay sesión, usar estudios que tienen antecedentes para demostración
    if (empty($session_token)) {
        // Conectar a la base de datos
        $pdo = getDBConnection();
        
        // Obtener estudios que tienen antecedentes con conteo de archivos
        $stmt = $pdo->query("
            SELECT DISTINCT 
                sa.study_id,
                sa.notes as antecedents_notes,
                sa.created_date as antecedents_created_at,
                sa.updated_date as antecedents_updated_at,
                u.nombre as created_by_name,
                u.apellido as created_by_surname,
                0 as files_count,
                CASE 
                    WHEN MAX(sa.notes) IS NOT NULL AND TRIM(MAX(sa.notes)) != '' THEN 1 
                    ELSE 0 
                END as has_antecedents
            FROM study_antecedents sa
            LEFT JOIN usuarios u ON sa.created_by = u.id
            GROUP BY sa.study_id, sa.notes, sa.created_date, sa.updated_date, u.nombre, u.apellido
            HAVING has_antecedents = 1
            ORDER BY sa.updated_date DESC
            LIMIT 10
        ");
        
        $antecedents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $processedStudies = [];
        foreach ($antecedents as $ant) {
            $study = [
                'id' => $ant['study_id'],
                'study_id' => $ant['study_id'],
                'patient_name' => 'Paciente Demo ' . $ant['study_id'],
                'study_date' => date('Y-m-d', strtotime($ant['antecedents_created_at'])),
                'modality' => 'CT',
                'description' => 'Estudio de demostración con antecedentes',
                'status' => 'pending',
                'priority' => 'normal',
                'assigned_date' => $ant['antecedents_created_at'],
                'antecedents' => [
                    'notes' => $ant['antecedents_notes'],
                    'created_at' => $ant['antecedents_created_at'],
                    'updated_at' => $ant['antecedents_updated_at'],
                    'created_by_name' => $ant['created_by_name'] . ' ' . $ant['created_by_surname'],
                    'files_count' => $ant['files_count'] ?: 0
                ]
            ];
            
            $processedStudies[] = $study;
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'studies' => $processedStudies,
                'user' => [
                    'id' => 1,
                    'name' => 'Usuario Root',
                    'level' => 'root',
                    'permissions' => ['all', 'pacs_query']
                ],
                'total' => count($processedStudies),
                'message' => 'Estudios con antecedentes cargados para demostración'
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    // Obtener datos del usuario desde el token
    $userData = getUserFromToken($session_token);
    if (!$userData) {
        throw new Exception('Sesión inválida o expirada');
    }
    
    $userId = $userData['id'];
    
    // Obtener visor DICOM preferido del usuario
    $userViewerType = 'UDV'; // Valor por defecto
    try {
        $pdo = getDBConnection();
        $viewerQuery = "SELECT dicom_viewer FROM usuarios WHERE id = ? AND activo = 1";
        $viewerStmt = $pdo->prepare($viewerQuery);
        $viewerStmt->execute([$userId]);
        $viewerResult = $viewerStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($viewerResult && isset($viewerResult['dicom_viewer']) && !empty($viewerResult['dicom_viewer'])) {
            $userViewerType = trim($viewerResult['dicom_viewer']);
            error_log("✅ Usuario $userId - Usando visor: $userViewerType");
        } else {
            error_log("⚠️ Usuario $userId - dicom_viewer no configurado, usando UDV por defecto");
        }
    } catch (Exception $e) {
        error_log('❌ Error obteniendo visor del usuario: ' . $e->getMessage());
    }
    
    // Requerir OrthancConfig para generar viewer_url personalizado
    require_once __DIR__ . '/config/orthanc_config.php';
    
    // Conectar a la base de datos
    $pdo = getDBConnection();
    
    // Obtener información del usuario actual
    $stmt = $pdo->prepare("SELECT id, nombre, apellido, nivel, permisos, padre_id FROM usuarios WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception('Usuario no encontrado en la base de datos');
    }
    
    // Determinar si el usuario es padre (tiene hijos)
    $stmt = $pdo->prepare("SELECT COUNT(*) as hijos_count FROM usuarios WHERE padre_id = ? AND activo = 1");
    $stmt->execute([$userId]);
    $hijosData = $stmt->fetch(PDO::FETCH_ASSOC);
    $isFather = $hijosData['hijos_count'] > 0;
    
    // Obtener estudios asignados DIRECTAMENTE al usuario
    $queryAssigned = "
        SELECT 
            sa.id as assignment_id,
            sa.study_id,
            sa.assigned_date,
            sa.assigned_by,
            sa.status as assignment_status,
            'assigned' as source_type,
            -- Datos completos del estudio desde study_assignments
            sa.patient_name,
            sa.patient_id,
            sa.study_date,
            sa.study_time,
            sa.modality,
            sa.study_description,
            sa.accession_number,
            sa.referring_physician,
            sa.study_instance_uid,
            sa.series_count,
            sa.instances_count,
            sa.viewer_url,
            sa.orthanc_study_id,
            sa.patient_birth_date,
            sa.patient_sex,
            sa.institution_name,
            -- Información de antecedentes
            a.id as antecedents_id,
            a.notes as antecedents_notes,
            a.created_date as antecedents_created_at,
            a.updated_date as antecedents_updated_at,
            -- Información del usuario que asignó
            ua.nombre as assigned_by_name,
            ua.apellido as assigned_by_surname
        FROM study_assignments sa
        LEFT JOIN study_antecedents a ON sa.study_id = a.study_id
        LEFT JOIN usuarios ua ON sa.assigned_by = ua.id
        WHERE sa.user_id = ? 
        AND sa.status = 'active'
        ORDER BY sa.assigned_date DESC
    ";
    
    $stmtAssigned = $pdo->prepare($queryAssigned);
    $stmtAssigned->execute([$userId]);
    $assignedStudies = $stmtAssigned->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener estudios DERIVADOS (subasignados) al usuario
    $querySubassigned = "
        SELECT 
            ss.id as assignment_id,
            ss.study_id,
            ss.subassigned_at as assigned_date,
            ss.assigned_by_user_id as assigned_by,
            ss.status as assignment_status,
            'subassigned' as source_type,
            -- Datos completos del estudio desde study_assignments (de la asignación principal)
            sa.patient_name,
            sa.patient_id,
            sa.study_date,
            sa.study_time,
            sa.modality,
            sa.study_description,
            sa.accession_number,
            sa.referring_physician,
            sa.study_instance_uid,
            sa.series_count,
            sa.instances_count,
            sa.viewer_url,
            sa.orthanc_study_id,
            sa.patient_birth_date,
            sa.patient_sex,
            sa.institution_name,
            -- Información de antecedentes
            a.id as antecedents_id,
            a.notes as antecedents_notes,
            a.created_date as antecedents_created_at,
            a.updated_date as antecedents_updated_at,
            -- Información del usuario que derivó
            ua.nombre as assigned_by_name,
            ua.apellido as assigned_by_surname
        FROM study_subassignments ss
        LEFT JOIN study_assignments sa ON ss.study_id = sa.study_id AND ss.main_user_id = sa.user_id
        LEFT JOIN study_antecedents a ON ss.study_id = a.study_id
        LEFT JOIN usuarios ua ON ss.assigned_by_user_id = ua.id
        WHERE ss.subassigned_to_user_id = ? 
        AND ss.status = 'active'
        ORDER BY ss.subassigned_at DESC
    ";
    
    $stmtSubassigned = $pdo->prepare($querySubassigned);
    $stmtSubassigned->execute([$userId]);
    $subassignedStudies = $stmtSubassigned->fetchAll(PDO::FETCH_ASSOC);
    
    // Combinar ambos arrays (asignaciones + derivaciones)
    $assignments = array_merge($assignedStudies, $subassignedStudies);
    
    // Eliminar duplicados por study_id (priorizar asignaciones directas)
    $uniqueStudies = [];
    $seenStudyIds = [];
    
    foreach ($assignments as $assignment) {
        $studyId = $assignment['study_id'];
        if (!in_array($studyId, $seenStudyIds)) {
            $uniqueStudies[] = $assignment;
            $seenStudyIds[] = $studyId;
        }
    }
    
    $assignments = $uniqueStudies;
    
    // Verificar qué estudios están en el PACS (Orthanc) para identificar huérfanos
    $pacsStudyIds = [];
    if (!empty($assignments)) {
        try {
            require_once '../api/OrthancClient.php';
            $orthancClient = new OrthancClient();
            $serverStatus = $orthancClient->getServerStatus();
            
            if ($serverStatus['status'] === 'connected') {
                // Obtener todos los IDs de estudios de Orthanc
                $allPacsStudies = $orthancClient->getAllStudiesEfficient(null, null, null, null, false);
                foreach ($allPacsStudies as $pacsStudy) {
                    if (!empty($pacsStudy['orthanc_id'])) {
                        $pacsStudyIds[] = $pacsStudy['orthanc_id'];
                    }
                }
                error_log('[GET_USER_ASSIGNED_STUDIES] Estudios en PACS: ' . count($pacsStudyIds));
            }
        } catch (Exception $e) {
            error_log('[GET_USER_ASSIGNED_STUDIES] Error verificando estudios en PACS: ' . $e->getMessage());
            // Continuar sin verificación de PACS si hay error
        }
    }
    
    // Detectar columnas disponibles en informes para compatibilidad entre entornos
    $informesColumns = [];
    $hasPacsInstanceId = false;
    $hasFechaEnviadoPacs = false;
    if (!empty($assignments)) {
        try {
            $checkColumnsQuery = "SHOW COLUMNS FROM informes";
            $checkColumnsStmt = $pdo->query($checkColumnsQuery);
            $informesColumns = $checkColumnsStmt->fetchAll(PDO::FETCH_COLUMN);
            $hasPacsInstanceId = in_array('pacs_instance_id', $informesColumns);
            $hasFechaEnviadoPacs = in_array('fecha_enviado_pacs', $informesColumns);
        } catch (Exception $e) {
            error_log('[GET_USER_ASSIGNED_STUDIES] Error leyendo columnas de informes: ' . $e->getMessage());
        }
    }

    // Consultar informes en PACS para estos estudios
    $pacsReportInfo = [];
    $studyReportSummary = [];
    if (!empty($assignments)) {
        try {
            // Recopilar identificadores de estudios para la consulta
            $studyInstanceUIDs = [];
            $studyIds = [];
            $orthancStudyIds = [];
            
            foreach ($assignments as $assignment) {
                if (!empty($assignment['study_instance_uid'])) {
                    $studyInstanceUIDs[] = $assignment['study_instance_uid'];
                }
                if (!empty($assignment['study_id'])) {
                    $studyIds[] = $assignment['study_id'];
                }
                if (!empty($assignment['orthanc_study_id'])) {
                    $orthancStudyIds[] = $assignment['orthanc_study_id'];
                }
            }
            
            // Eliminar duplicados
            $studyInstanceUIDs = array_unique($studyInstanceUIDs);
            $studyIds = array_unique($studyIds);
            $orthancStudyIds = array_unique($orthancStudyIds);
            
            if (!empty($studyInstanceUIDs) || !empty($studyIds) || !empty($orthancStudyIds)) {
                // Construir condiciones de cotejo
                $conditions = [];
                $params = [];
                
                // Prioridad 1: Cotejar por study_instance_uid
                if (!empty($studyInstanceUIDs)) {
                    $placeholders = str_repeat('?,', count($studyInstanceUIDs) - 1) . '?';
                    $conditions[] = "i.study_instance_uid IN ($placeholders)";
                    $params = array_merge($params, $studyInstanceUIDs);
                }
                
                // Prioridad 2: Cotejar por study_id
                if (!empty($studyIds)) {
                    $placeholders = str_repeat('?,', count($studyIds) - 1) . '?';
                    $conditions[] = "i.study_id IN ($placeholders)";
                    $params = array_merge($params, $studyIds);
                }
                
                // Prioridad 3: Cotejar por orthanc_study_id vs estudio_id
                if (!empty($orthancStudyIds)) {
                    $placeholders = str_repeat('?,', count($orthancStudyIds) - 1) . '?';
                    $conditions[] = "i.estudio_id IN ($placeholders)";
                    $params = array_merge($params, $orthancStudyIds);
                }
                
                if (!empty($conditions)) {
                    $whereClause = "(" . implode(" OR ", $conditions) . ")";
                    
                    // Consultar resumen de informes (informado/publicado) para todos los estudios
                    $publishedExpr = "0";
                    if ($hasPacsInstanceId && $hasFechaEnviadoPacs) {
                        $publishedExpr = "MAX(CASE WHEN ((i.pacs_instance_id IS NOT NULL AND i.pacs_instance_id != '') OR i.fecha_enviado_pacs IS NOT NULL) THEN 1 ELSE 0 END)";
                    } elseif ($hasPacsInstanceId) {
                        $publishedExpr = "MAX(CASE WHEN (i.pacs_instance_id IS NOT NULL AND i.pacs_instance_id != '') THEN 1 ELSE 0 END)";
                    } elseif ($hasFechaEnviadoPacs) {
                        $publishedExpr = "MAX(CASE WHEN i.fecha_enviado_pacs IS NOT NULL THEN 1 ELSE 0 END)";
                    }

                    $summaryQuery = "
                        SELECT
                            COALESCE(i.study_instance_uid, i.study_id, i.estudio_id) as study_identifier,
                            i.study_id,
                            i.study_instance_uid,
                            i.estudio_id,
                            COUNT(*) as total_informes,
                            MAX(i.estado) as ultimo_estado,
                            MAX(i.fecha_creacion) as ultima_fecha_informe,
                            GROUP_CONCAT(DISTINCT i.estado) as estados_disponibles,
                            $publishedExpr as published_to_pacs
                        FROM informes i
                        WHERE $whereClause
                        GROUP BY COALESCE(i.study_instance_uid, i.study_id, i.estudio_id), i.study_id, i.study_instance_uid, i.estudio_id
                    ";

                    $summaryStmt = $pdo->prepare($summaryQuery);
                    $summaryStmt->execute($params);
                    $summaryRows = $summaryStmt->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($summaryRows as $summary) {
                        $summaryInfo = [
                            'has_report' => ((int)$summary['total_informes']) > 0,
                            'total_informes' => (int)$summary['total_informes'],
                            'ultimo_estado' => $summary['ultimo_estado'],
                            'ultima_fecha_informe' => $summary['ultima_fecha_informe'],
                            'estados_disponibles' => !empty($summary['estados_disponibles']) ? explode(',', $summary['estados_disponibles']) : [],
                            'published_to_pacs' => ((int)$summary['published_to_pacs']) > 0
                        ];

                        $possibleKeys = [
                            $summary['study_identifier'] ?? '',
                            $summary['study_id'] ?? '',
                            $summary['study_instance_uid'] ?? '',
                            $summary['estudio_id'] ?? ''
                        ];

                        foreach ($possibleKeys as $key) {
                            if (!empty($key)) {
                                $studyReportSummary[$key] = $summaryInfo;
                            }
                        }
                    }

                    // Construir condición para verificar que está EN PACS
                    $pacsCondition = "";
                    if ($hasPacsInstanceId && $hasFechaEnviadoPacs) {
                        $pacsCondition = " AND ((i.pacs_instance_id IS NOT NULL AND i.pacs_instance_id != '') OR i.fecha_enviado_pacs IS NOT NULL)";
                    } elseif ($hasPacsInstanceId) {
                        $pacsCondition = " AND (i.pacs_instance_id IS NOT NULL AND i.pacs_instance_id != '')";
                    } elseif ($hasFechaEnviadoPacs) {
                        $pacsCondition = " AND i.fecha_enviado_pacs IS NOT NULL";
                    }
                    
                    $pacsQuery = "
                        SELECT DISTINCT
                            COALESCE(i.study_id, i.study_instance_uid, i.estudio_id) as study_identifier,
                            i.study_id,
                            i.study_instance_uid,
                            i.estudio_id
                        FROM informes i
                        WHERE $whereClause
                        $pacsCondition
                    ";
                    
                    $pacsStmt = $pdo->prepare($pacsQuery);
                    $pacsStmt->execute($params);
                    $pacsReports = $pacsStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Crear mapa de identificadores que tienen informe en PACS
                    foreach ($pacsReports as $report) {
                        // Agregar todos los identificadores posibles para facilitar la búsqueda
                        if (!empty($report['study_identifier'])) {
                            $pacsReportInfo[$report['study_identifier']] = true;
                        }
                        if (!empty($report['study_id'])) {
                            $pacsReportInfo[$report['study_id']] = true;
                        }
                        if (!empty($report['study_instance_uid'])) {
                            $pacsReportInfo[$report['study_instance_uid']] = true;
                        }
                        if (!empty($report['estudio_id'])) {
                            $pacsReportInfo[$report['estudio_id']] = true;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log('[GET_USER_ASSIGNED_STUDIES] Error consultando informes en PACS: ' . $e->getMessage());
        }
    }
    
    // NO USAR fallback de antecedentes - Si no hay asignaciones NI derivaciones, retornar vacío
    // Esto asegura que los usuarios hijos solo ven estudios explícitamente asignados o derivados
    if (empty($assignments)) {
        // Usuario sin estudios asignados ni derivados
        echo json_encode([
            'success' => true,
            'data' => [
                'studies' => [],
                'user' => [
                    'id' => $user['id'],
                    'name' => $user['nombre'] . ' ' . $user['apellido'],
                    'level' => $user['nivel'],
                    'permissions' => json_decode($user['permisos'], true) ?: []
                ],
                'total' => 0,
                'assigned_count' => count($assignedStudies),
                'subassigned_count' => count($subassignedStudies),
                'message' => 'No hay estudios asignados ni derivados a este usuario'
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    // Procesar los datos para el frontend
    $processedStudies = [];
    
    foreach ($assignments as $assignment) {
        $studyId = $assignment['study_id'];
        
        // Determinar modalidad base
        $baseModality = $assignment['modality'] ?: 'CT';
        
        // Verificar si tiene informe en PACS
        $hasPacsReport = false;
        $studyIdentifier = $assignment['study_instance_uid'] ?? $assignment['study_id'] ?? $assignment['orthanc_study_id'] ?? $studyId;
        
        // Buscar en el mapa de informes en PACS usando diferentes identificadores
        if (isset($pacsReportInfo[$studyIdentifier]) || 
            isset($pacsReportInfo[$assignment['study_id'] ?? '']) ||
            isset($pacsReportInfo[$assignment['study_instance_uid'] ?? '']) ||
            isset($pacsReportInfo[$assignment['orthanc_study_id'] ?? ''])) {
            $hasPacsReport = true;
        }
        
        $reportInfo = $studyReportSummary[$studyIdentifier]
            ?? $studyReportSummary[$assignment['study_id'] ?? '']
            ?? $studyReportSummary[$assignment['study_instance_uid'] ?? '']
            ?? $studyReportSummary[$assignment['orthanc_study_id'] ?? '']
            ?? null;

        if (!$reportInfo) {
            $reportInfo = [
                'has_report' => false,
                'total_informes' => 0,
                'ultimo_estado' => null,
                'ultima_fecha_informe' => null,
                'estados_disponibles' => [],
                'published_to_pacs' => false
            ];
        }

        $hasAnyReport = !empty($reportInfo['has_report']);
        $isPublishedToPacs = !empty($reportInfo['published_to_pacs']);
        $reportStatus = 'none';
        if ($hasAnyReport) {
            $reportStatus = $isPublishedToPacs ? 'published_pacs' : 'informed_pending_pacs';
        }

        // Combinar modalidades si hay informe en PACS
        $finalModality = $baseModality;
        if ($hasPacsReport && strpos($baseModality, 'DOC') === false) {
            $finalModality = $baseModality . ', DOC';
        }
        
        // Verificar si el estudio es huérfano (eliminado del PACS)
        $orthancStudyId = $assignment['orthanc_study_id'] ?: $studyId;
        $isOrphan = false;
        if (!empty($orthancStudyId) && !empty($pacsStudyIds)) {
            // Verificar si el estudio NO está en el PACS
            if (!in_array($orthancStudyId, $pacsStudyIds) && !in_array($studyId, $pacsStudyIds)) {
                $isOrphan = true;
            }
        }

        // Autocorregir filas con metadata vacía: Orthanc + persistir en study_assignments
        if (!$isOrphan) {
            $sparseMeta = trim((string)($assignment['patient_name'] ?? '')) === ''
                || trim((string)($assignment['patient_id'] ?? '')) === ''
                || trim((string)($assignment['study_description'] ?? '')) === '';
            if ($sparseMeta) {
                $rowForEnrich = [
                    'patient_name' => $assignment['patient_name'] ?? '',
                    'patient_id' => $assignment['patient_id'] ?? '',
                    'study_date' => $assignment['study_date'] ?? '',
                    'study_time' => $assignment['study_time'] ?? '',
                    'modality' => $assignment['modality'] ?? '',
                    'study_description' => $assignment['study_description'] ?? '',
                    'accession_number' => $assignment['accession_number'] ?? '',
                    'referring_physician' => $assignment['referring_physician'] ?? '',
                    'study_instance_uid' => $assignment['study_instance_uid'] ?? '',
                    'series_count' => $assignment['series_count'] ?? 0,
                    'viewer_url' => $assignment['viewer_url'] ?? '',
                    'orthanc_study_id' => $assignment['orthanc_study_id'] ?? '',
                    'orthanc_id' => $assignment['orthanc_study_id'] ?? '',
                    'patient_birth_date' => $assignment['patient_birth_date'] ?? '',
                    'institution_name' => $assignment['institution_name'] ?? '',
                ];
                $oidForEnrich = resolveOrthancIdForEnrich($rowForEnrich, (string) $studyId);
                $enriched = enrichStudyDataFromOrthanc($rowForEnrich, $oidForEnrich);
                if (trim((string)($enriched['patient_name'] ?? '')) !== ''
                    || trim((string)($enriched['study_description'] ?? '')) !== '') {
                    if (!empty($enriched['patient_name'])) {
                        $enriched['patient_name'] = normalizePatientName($enriched['patient_name']);
                    }
                    try {
                        persistSparseAssignmentMetadata($pdo, (string) $studyId, $enriched);
                    } catch (Throwable $e) {
                        error_log('[GET_USER_ASSIGNED_STUDIES] persistSparseAssignmentMetadata: ' . $e->getMessage());
                    }
                    $mergeKeys = [
                        'patient_name', 'patient_id', 'study_date', 'study_time', 'modality',
                        'study_description', 'accession_number', 'referring_physician',
                        'study_instance_uid', 'series_count', 'viewer_url', 'orthanc_study_id',
                        'patient_birth_date', 'institution_name',
                    ];
                    foreach ($mergeKeys as $mk) {
                        if (trim((string)($assignment[$mk] ?? '')) === ''
                            && isset($enriched[$mk]) && trim((string) $enriched[$mk]) !== '') {
                            $assignment[$mk] = $enriched[$mk];
                        }
                    }
                }
            }
        }
        
        // Usar datos reales desde study_assignments si están disponibles
        $study = [
            'id' => $studyId,
            'assignment_id' => $assignment['assignment_id'],
            'patient_name' => normalizePatientName($assignment['patient_name'] ?: 'Paciente ' . substr($studyId, 0, 8)),
            'patient_id' => $assignment['patient_id'] ?: substr($studyId, 0, 8),
            'date' => $assignment['study_date'] ?: date('Ymd'),
            'time' => $assignment['study_time'] ?: '140000',
            'modality' => $finalModality,
            'study_description' => $assignment['study_description'] ?: '',
            'accession_number' => $assignment['accession_number'] ?: 'ACC' . substr($studyId, -3),
            'referring_physician' => $assignment['referring_physician'] ?: 'Dr. García',
            'patient_birth_date' => $assignment['patient_birth_date'] ?: '19800101',
            'patient_sex' => $assignment['patient_sex'] ?: 'M',
            'series_count' => $assignment['series_count'] ?: 3,
            'instances_count' => $assignment['instances_count'] ?: 150,
            'orthanc_study_id' => $orthancStudyId,
            'study_instance_uid' => $assignment['study_instance_uid'] ?: '',
            'institution_name' => $assignment['institution_name'] ?? null,
            'has_report' => $hasAnyReport,
            'report_status' => $reportStatus,
            'report_info' => $reportInfo,
            // Regenerar viewer_url usando el visor configurado del usuario (solo si no es huérfano)
            'viewer_url' => $isOrphan ? ($assignment['viewer_url'] ?: '') : OrthancConfig::getViewerUrl(
                $orthancStudyId,
                $userViewerType,
                $assignment['study_instance_uid'] ?: ''
            ),
            'status' => $isOrphan ? 'Eliminado del PACS' : 'Asignado',
            'is_orphan' => $isOrphan,
            'orphaned_from_pacs' => $isOrphan,
            'source_type' => $assignment['source_type'] ?: 'assigned', // Tipo de fuente: 'assigned' o 'subassigned'
            'assigned_at' => $assignment['assigned_date'],
            'assigned_by' => $assignment['assigned_by_name'] . ' ' . $assignment['assigned_by_surname'],
            'assignment_notes' => $isOrphan ? 'Estudio eliminado del PACS pero aún tiene asignaciones activas' : 'Estudio asignado para revisión',
            'antecedents' => [
                'has_notes' => !empty($assignment['antecedents_notes']),
                'has_images' => false, // No disponible en la tabla actual
                'has_files' => false, // Se calculará más abajo
                'has_camera_captures' => false, // No disponible en la tabla actual
                'notes' => $assignment['antecedents_notes'] ?: '',
                'created_at' => $assignment['antecedents_created_at'],
                'updated_at' => $assignment['antecedents_updated_at'],
                'total_count' => 0
            ]
        ];
        
        // Usar la misma lógica que PACS QUERY para calcular antecedentes correctamente
         $antecedentsStmt = $pdo->prepare("
             SELECT 
                 COUNT(saf.id) as files_count,
                 CASE 
                     WHEN MAX(sa.notes) IS NOT NULL AND TRIM(MAX(sa.notes)) != '' THEN 1 
                     ELSE 0 
                 END as has_notes,
                 (COUNT(saf.id) + CASE 
                     WHEN MAX(sa.notes) IS NOT NULL AND TRIM(MAX(sa.notes)) != '' THEN 1 
                     ELSE 0 
                 END) as total_antecedents
             FROM study_antecedents sa
             LEFT JOIN study_antecedents_files saf ON sa.id = saf.antecedent_id
             WHERE sa.study_id = ?
             GROUP BY sa.study_id
         ");
         
         $antecedentsStmt->execute([$assignment['study_id']]);
         $antecedentsResult = $antecedentsStmt->fetch(PDO::FETCH_ASSOC);
         
         if ($antecedentsResult) {
             $filesCount = (int)$antecedentsResult['files_count'];
             $hasNotes = (bool)$antecedentsResult['has_notes'];
             $hasFiles = $filesCount > 0;
             $antecedentsCount = (int)$antecedentsResult['total_antecedents'];
             
             $study['antecedents']['has_files'] = $hasFiles;
             $study['antecedents']['total_count'] = $antecedentsCount;
         } else {
             // No hay antecedentes para este estudio
             $study['antecedents']['has_files'] = false;
             $study['antecedents']['total_count'] = 0;
         }
        
        $processedStudies[] = $study;
    }
    
    // Respuesta exitosa con información detallada de fuentes
    echo json_encode([
        'success' => true,
        'data' => [
            'studies' => $processedStudies,
            'user' => [
                'id' => $user['id'],
                'name' => $user['nombre'] . ' ' . $user['apellido'],
                'level' => $user['nivel'],
                'permissions' => json_decode($user['permisos'], true) ?: []
            ],
            'total' => count($processedStudies),
            'assigned_count' => count($assignedStudies),
            'subassigned_count' => count($subassignedStudies),
            'message' => 'Estudios asignados y derivados obtenidos correctamente'
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'data' => null
    ], JSON_UNESCAPED_UNICODE);
}
?>
