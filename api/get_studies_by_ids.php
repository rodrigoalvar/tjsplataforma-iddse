<?php
/**
 * API para obtener estudios completos desde Orthanc por IDs específicos
 * Útil para cargar estudios incompletos directamente por ID
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    ob_end_clean();
    exit();
}

try {
    require_once __DIR__ . '/OrthancClient.php';
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../classes/User.php';
    
    $orthancClient = new OrthancClient();
    
    // Obtener IDs de estudios desde parámetros
    $studyIds = [];
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $idsParam = $_GET['ids'] ?? '';
        if ($idsParam) {
            $studyIds = explode(',', $idsParam);
            $studyIds = array_map('trim', $studyIds);
            $studyIds = array_filter($studyIds); // Eliminar vacíos
        }
    } else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $studyIds = $data['ids'] ?? [];
    }
    
    if (empty($studyIds)) {
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'data' => []
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    // Obtener datos completos de cada estudio desde Orthanc
    $studies = [];
    $pdo = getDBConnection();
    
    // Verificar sesión para obtener user_id (para flags)
    $currentUserId = null;
    $session_token = $_COOKIE['session_token'] ?? null;
    
    if ($session_token) {
        try {
            $user = new User();
            $user_data = $user->validateSession($session_token);
            if ($user_data) {
                $currentUserId = $user_data['id'];
            }
        } catch (Exception $e) {
            error_log('Error verificando sesión: ' . $e->getMessage());
        }
    }
    
    foreach ($studyIds as $studyId) {
        try {
            // Obtener detalles del estudio desde Orthanc
            $studyDetails = $orthancClient->getStudyDetails($studyId);
            
            if (!$studyDetails) {
                error_log("Estudio no encontrado en Orthanc: $studyId");
                continue;
            }
            
            // Obtener información de informes (similar a get_all_studies.php)
            $hasReport = false;
            $reportInfoForStudy = null;
            $studyInstanceUID = $studyDetails['study_instance_uid'] ?? '';
            
            if ($studyInstanceUID) {
                try {
                    $query = "
                        SELECT 
                            COALESCE(study_instance_uid, study_id, estudio_id) as study_identifier,
                            COUNT(*) as total_informes,
                            MAX(estado) as ultimo_estado,
                            MAX(fecha_creacion) as ultima_fecha_informe,
                            GROUP_CONCAT(DISTINCT estado) as estados_disponibles
                        FROM informes 
                        WHERE study_instance_uid = ? OR study_id = ?
                        GROUP BY study_identifier
                    ";
                    $stmt = $pdo->prepare($query);
                    $stmt->execute([$studyInstanceUID, $studyId]);
                    $reportData = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($reportData) {
                        $hasReport = true;
                        $reportInfoForStudy = [
                            'has_report' => true,
                            'total_informes' => (int)$reportData['total_informes'],
                            'ultimo_estado' => $reportData['ultimo_estado'],
                            'ultima_fecha_informe' => $reportData['ultima_fecha_informe'],
                            'estados_disponibles' => explode(',', $reportData['estados_disponibles'])
                        ];
                    }
                } catch (Exception $e) {
                    error_log('Error obteniendo información de informes: ' . $e->getMessage());
                }
            }
            
            // Obtener flags (prioridad, informes_incompletos)
            $informesIncompletosFlag = null;
            if ($currentUserId) {
                try {
                    // Buscar flag por orthanc_id o study_instance_uid
                    $flagStmt = $pdo->prepare("
                        SELECT informes_incompletos, prioridad, nota 
                        FROM study_flags 
                        WHERE (study_id = ? OR orthanc_id = ? OR study_instance_uid = ?) AND user_id = ?
                        AND (informes_incompletos = 1 OR prioridad != 'normal' OR prioridad IS NOT NULL)
                    ");
                    $flagStmt->execute([$studyId, $studyId, $studyInstanceUID, $currentUserId]);
                    $flag = $flagStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (empty($flag)) {
                        // Buscar flags de informes incompletos de cualquier usuario (deben ser visibles para todos)
                        $incompleteStmt = $pdo->prepare("
                            SELECT informes_incompletos, prioridad, nota 
                            FROM study_flags 
                            WHERE (study_id = ? OR orthanc_id = ? OR study_instance_uid = ?) 
                            AND informes_incompletos = 1
                            LIMIT 1
                        ");
                        $incompleteStmt->execute([$studyId, $studyId, $studyInstanceUID]);
                        $incompleteFlag = $incompleteStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($incompleteFlag) {
                            $flag = [
                                'informes_incompletos' => (bool)$incompleteFlag['informes_incompletos'],
                                'prioridad' => $incompleteFlag['prioridad'] ?? 'normal',
                                'nota' => $incompleteFlag['nota']
                            ];
                        } else {
                            // Si no hay incompletos, buscar prioridad de cualquier usuario
                            $flagStmt = $pdo->prepare("
                                SELECT prioridad FROM study_flags 
                                WHERE (study_id = ? OR orthanc_id = ? OR study_instance_uid = ?) 
                                AND prioridad IS NOT NULL AND prioridad != 'normal'
                                ORDER BY CASE WHEN prioridad = 'urgente' THEN 1 WHEN prioridad = 'promesa' THEN 2 WHEN prioridad = 'pendiente' THEN 3 ELSE 4 END
                                LIMIT 1
                            ");
                            $flagStmt->execute([$studyId, $studyId, $studyInstanceUID]);
                            $priorityFlag = $flagStmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($priorityFlag) {
                                $flag = [
                                    'informes_incompletos' => false,
                                    'prioridad' => $priorityFlag['prioridad'],
                                    'nota' => null
                                ];
                            }
                        }
                    }
                    
                    if (!empty($flag)) {
                        $informesIncompletosFlag = [
                            'informes_incompletos' => (bool)$flag['informes_incompletos'],
                            'prioridad' => $flag['prioridad'] ?? 'normal',
                            'nota' => $flag['nota']
                        ];
                    }
                } catch (Exception $e) {
                    error_log('Error obteniendo flag: ' . $e->getMessage());
                }
            }
            
            // Formatear estudio (similar a get_all_studies.php)
            $formattedStudy = [
                'id' => $studyId,
                'date' => $studyDetails['study_date'] ?? '',
                'time' => $studyDetails['study_time'] ?? '',
                'patient_name' => $studyDetails['patient_name'] ?? '',
                'patient_id' => $studyDetails['patient_id'] ?? '',
                'patient_birth_date' => $studyDetails['patient_birth_date'] ?? '',
                'patient_sex' => $studyDetails['patient_sex'] ?? '',
                'modality' => $studyDetails['modality'] ?? '',
                'study_description' => $studyDetails['study_description'] ?? '',
                'study_instance_uid' => $studyInstanceUID,
                'status' => 'Completado',
                'orthanc_study_id' => $studyId,
                'study_id' => $studyId,
                'viewer_url' => $studyDetails['viewer_url'] ?? '',
                'accession_number' => $studyDetails['accession_number'] ?? '',
                'referring_physician' => $studyDetails['referring_physician'] ?? '',
                'series_count' => $studyDetails['series_count'] ?? 0,
                'instances_count' => $studyDetails['instances_count'] ?? 0,
                'has_report' => $hasReport,
                'report_info' => $reportInfoForStudy ?: [
                    'has_report' => false,
                    'total_informes' => 0,
                    'ultimo_estado' => null,
                    'ultima_fecha_informe' => null,
                    'estados_disponibles' => []
                ],
                'informes_incompletos' => $informesIncompletosFlag,
                'prioridad' => ($informesIncompletosFlag && isset($informesIncompletosFlag['prioridad']) && $informesIncompletosFlag['prioridad'] !== 'normal') 
                            ? $informesIncompletosFlag['prioridad'] 
                            : 'normal'
            ];
            
            $studies[] = $formattedStudy;
            
        } catch (Exception $e) {
            error_log("Error obteniendo estudio $studyId: " . $e->getMessage());
            // Continuar con el siguiente estudio
            continue;
        }
    }
    
    ob_end_clean();
    echo json_encode([
        'success' => true,
        'data' => $studies
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    ob_end_clean();
    error_log('Error en get_studies_by_ids.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error obteniendo estudios: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
