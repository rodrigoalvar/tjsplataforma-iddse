<?php
/**
 * API para obtener estudios de pacientes desde PACS
 * Versión ultra-robusta que SIEMPRE devuelve JSON válido
 */

// ============================================
// PRIMER PASO: DESACTIVAR TODOS LOS ERRORES
// ============================================
@error_reporting(0);
@ini_set('display_errors', 0);
@ini_set('log_errors', 1);
@ini_set('html_errors', 0);

// ============================================
// SEGUNDO PASO: HEADERS JSON
// ============================================
@header('Content-Type: application/json; charset=utf-8');
@header('Access-Control-Allow-Origin: *');
@header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
@header('Access-Control-Allow-Headers: Content-Type');

// ============================================
// TERCER PASO: BUFFER MÚLTIPLE
// ============================================
while (@ob_get_level()) {
    @ob_end_clean();
}
@ob_start();
@ob_start();

// ============================================
// FUNCIÓN PARA ENVIAR RESPUESTA JSON DE ERROR
// ============================================
function sendJsonError($error, $code = 500) {
    while (@ob_get_level()) {
        @ob_end_clean();
    }
    @http_response_code($code);
    echo json_encode([
        'success' => false,
        'error' => $error
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ============================================
// CUARTO PASO: CARGAR ARCHIVOS NECESARIOS
// ============================================
try {
    // Cargar OrthancClient
    if (!class_exists('OrthancClient')) {
        $orthancPath = __DIR__ . '/OrthancClient.php';
        if (!file_exists($orthancPath)) {
            sendJsonError('Archivo OrthancClient.php no encontrado', 500);
        }
        @require_once $orthancPath;
    }
    
    if (!class_exists('OrthancClient')) {
        sendJsonError('Clase OrthancClient no encontrada', 500);
    }
} catch (Throwable $e) {
    sendJsonError('Error cargando OrthancClient: ' . $e->getMessage(), 500);
}

// ============================================
// QUINTO PASO: VALIDAR PARÁMETROS
// ============================================
if (!isset($_GET['patient_id']) || empty(trim($_GET['patient_id']))) {
    sendJsonError('ID de paciente requerido', 400);
}

$patientId = trim($_GET['patient_id']);
$searchType = isset($_GET['search_type']) ? trim($_GET['search_type']) : 'documento';

// Validar searchType
if (!in_array($searchType, ['documento', 'id_interno', 'idpaciente'])) {
    $searchType = 'documento'; // Por defecto
}

// ============================================
// SEXTO PASO: PROCESAR BÚSQUEDA
// ============================================
try {
    // Conectar a BD para buscar por idinterno si es necesario
    $db = null;
    $pacienteData = null;
    
    if ($searchType === 'id_interno') {
        // Búsqueda por ID interno: buscar en tabla pacientes primero
        $dbConfigPaths = [
            __DIR__ . '/../config/database.php',
            __DIR__ . '/../../config/database.php'
        ];
        
        foreach ($dbConfigPaths as $path) {
            if (file_exists($path)) {
                @require_once $path;
                if (function_exists('getDBConnection')) {
                    $db = @getDBConnection();
                    if ($db !== null) {
                        break;
                    }
                }
            }
        }
        
        if (!$db) {
            sendJsonError('No se pudo conectar a la base de datos para buscar por ID interno', 500);
        }
        
        // Buscar paciente por idinterno (solo si está activo)
        try {
            $pacienteQuery = "SELECT id_interno, idpaciente, nombre, telefono, email, domicilio, activo 
                            FROM pacientes 
                            WHERE id_interno = :id_interno AND activo = 1 
                            LIMIT 1";
            
            $pacienteStmt = $db->prepare($pacienteQuery);
            $pacienteStmt->execute([':id_interno' => $patientId]);
            $pacienteData = $pacienteStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$pacienteData) {
                // Verificar si existe pero está inactivo
                $checkInactivoQuery = "SELECT id_interno, activo FROM pacientes WHERE id_interno = :id_interno LIMIT 1";
                $checkInactivoStmt = $db->prepare($checkInactivoQuery);
                $checkInactivoStmt->execute([':id_interno' => $patientId]);
                $pacienteInactivo = $checkInactivoStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($pacienteInactivo && $pacienteInactivo['activo'] == 0) {
                    sendJsonError('El paciente con ID Interno ' . $patientId . ' está inactivo y no puede buscar estudios.', 403);
                } else {
                    sendJsonError('No se encontró un paciente activo con ID Interno: ' . $patientId, 404);
                }
            }
            
            if (empty($pacienteData['idpaciente'])) {
                sendJsonError('El paciente encontrado no tiene ID PACS asociado. Contacte al administrador.', 404);
            }
            
            // IMPORTANTE: Usar el idpaciente para buscar en PACS
            $patientId = $pacienteData['idpaciente'];
            
        } catch (PDOException $e) {
            @error_log('Error BD buscando paciente por idinterno: ' . $e->getMessage());
            sendJsonError('Error al buscar paciente en la base de datos', 500);
        }
    } else {
        // Búsqueda por documento (idpaciente): verificar si el paciente está activo en la BD
        $dbConfigPaths = [
            __DIR__ . '/../config/database.php',
            __DIR__ . '/../../config/database.php'
        ];
        
        foreach ($dbConfigPaths as $path) {
            if (file_exists($path)) {
                @require_once $path;
                if (function_exists('getDBConnection')) {
                    $db = @getDBConnection();
                    if ($db !== null) {
                        break;
                    }
                }
            }
        }
        
        if ($db) {
            try {
                // Verificar si el paciente existe en la tabla y está activo
                $checkPacienteQuery = "SELECT id_interno, idpaciente, nombre, activo 
                                     FROM pacientes 
                                     WHERE idpaciente = :idpaciente 
                                     LIMIT 1";
                $checkPacienteStmt = $db->prepare($checkPacienteQuery);
                $checkPacienteStmt->execute([':idpaciente' => $patientId]);
                $pacienteCheck = $checkPacienteStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($pacienteCheck) {
                    // Si el paciente existe en la BD, verificar que esté activo
                    if ($pacienteCheck['activo'] == 0) {
                        sendJsonError('El paciente con ID PACS ' . $patientId . ' está inactivo y no puede buscar estudios.', 403);
                    }
                    // Si está activo, continuar con la búsqueda normalmente
                }
                // Si no existe en la BD, permitir búsqueda directa en PACS (paciente nuevo)
            } catch (PDOException $e) {
                @error_log('Error verificando paciente activo por documento: ' . $e->getMessage());
                // Continuar con la búsqueda si hay error (no bloquear)
            }
        }
    }
    
    // ============================================
    // SÉPTIMO PASO: BUSCAR EN PACS
    // ============================================
    // Ahora $patientId contiene el idpaciente (ya sea el original o el obtenido de la BD)
    $orthancClient = new OrthancClient();
    
    // Verificar conexión con Orthanc
    $serverStatus = $orthancClient->getServerStatus();
    if ($serverStatus['status'] !== 'connected') {
        sendJsonError('No se puede conectar al servidor Orthanc: ' . ($serverStatus['message'] ?? 'Error desconocido'), 500);
    }
    
    // Buscar estudios por PatientID en PACS (siempre usando idpaciente)
    $studies = $orthancClient->findStudiesByPatientId($patientId);
    
    // Obtener nombre del paciente
    $patientName = '';
    $isOrthancNameAnonymized = false;
    if (!empty($studies)) {
        $orthancPatientName = $studies[0]['patient_name'] ?? '';
        if (!empty($orthancPatientName) && preg_match('/^Anonymized/i', $orthancPatientName)) {
            $isOrthancNameAnonymized = true;
        } elseif (!empty($orthancPatientName) && strlen(trim($orthancPatientName)) > 3) {
            $patientName = $orthancPatientName;
        }
    }
    
    // Si buscamos por idinterno y tenemos datos del paciente, usar su nombre
    if ($searchType === 'id_interno' && $pacienteData && !empty($pacienteData['nombre'])) {
        $patientName = $pacienteData['nombre'];
    }
    
    // Buscar informes con PDF (solo si tenemos BD)
    $informesWithPdf = [];
    if (!$db) {
        $dbConfigPaths = [
            __DIR__ . '/../config/database.php',
            __DIR__ . '/../../config/database.php'
        ];
        foreach ($dbConfigPaths as $path) {
            if (file_exists($path)) {
                @require_once $path;
                if (function_exists('getDBConnection')) {
                    $db = @getDBConnection();
                    if ($db !== null) break;
                }
            }
        }
    }
    
    if ($db) {
        try {
            $checkColumn = $db->query("SHOW COLUMNS FROM informes LIKE 'pdf_path'");
            if ($checkColumn && $checkColumn->rowCount() > 0) {
                // Verificar si existen columnas PACS
                $hasPacsInstanceId = false;
                $hasPacsSeriesId = false;
                $hasPacsStudyId = false;
                $hasFechaEnviadoPacs = false;
                try {
                    $pacsColumns = $db->query("SHOW COLUMNS FROM informes WHERE Field IN ('pacs_instance_id', 'pacs_series_id', 'pacs_study_id', 'fecha_enviado_pacs')");
                    if ($pacsColumns) {
                        $pacsColumnsData = $pacsColumns->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($pacsColumnsData as $col) {
                            if ($col['Field'] === 'pacs_instance_id') $hasPacsInstanceId = true;
                            if ($col['Field'] === 'pacs_series_id') $hasPacsSeriesId = true;
                            if ($col['Field'] === 'pacs_study_id') $hasPacsStudyId = true;
                            if ($col['Field'] === 'fecha_enviado_pacs') $hasFechaEnviadoPacs = true;
                        }
                    }
                } catch (Exception $e) {
                    @error_log('Error verificando columnas PACS: ' . $e->getMessage());
                }
                
                // Construir query base
                $informesQuery = "SELECT 
                                i.id, i.titulo, i.estado, i.modality,
                                i.fecha_creacion, i.fecha_modificacion,
                                i.pdf_path, i.study_id, i.patient_id, i.patient_name,
                                DATE(i.fecha_creacion) as informe_fecha";
                
                // Agregar columnas PACS si existen
                if ($hasPacsInstanceId) {
                    $informesQuery .= ", i.pacs_instance_id";
                }
                if ($hasPacsSeriesId) {
                    $informesQuery .= ", i.pacs_series_id";
                }
                if ($hasPacsStudyId) {
                    $informesQuery .= ", i.pacs_study_id";
                }
                if ($hasFechaEnviadoPacs) {
                    $informesQuery .= ", i.fecha_enviado_pacs";
                }
                
                $informesQuery .= " FROM informes i
                              WHERE i.patient_id = :patient_id
                                AND i.pdf_path IS NOT NULL
                                AND i.pdf_path != ''
                                AND i.estado = 'finalizado'";
                
                // Solo incluir informes que están en PACS
                // Un informe está en PACS si tiene alguna referencia PACS en BD.
                if ($hasPacsInstanceId || $hasPacsSeriesId || $hasPacsStudyId || $hasFechaEnviadoPacs) {
                    $pacsConditions = [];
                    if ($hasPacsInstanceId) {
                        $pacsConditions[] = "(i.pacs_instance_id IS NOT NULL AND i.pacs_instance_id != '')";
                    }
                    if ($hasPacsSeriesId) {
                        $pacsConditions[] = "(i.pacs_series_id IS NOT NULL AND i.pacs_series_id != '')";
                    }
                    if ($hasPacsStudyId) {
                        $pacsConditions[] = "(i.pacs_study_id IS NOT NULL AND i.pacs_study_id != '')";
                    }
                    if ($hasFechaEnviadoPacs) {
                        $pacsConditions[] = "i.fecha_enviado_pacs IS NOT NULL";
                    }
                    if (!empty($pacsConditions)) {
                        $informesQuery .= " AND (" . implode(" OR ", $pacsConditions) . ")";
                    }
                }
                
                $informesQuery .= " ORDER BY i.fecha_creacion DESC";
                
                $informesStmt = $db->prepare($informesQuery);
                $informesStmt->execute([':patient_id' => $patientId]);
                $informesData = $informesStmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($informesData as $informe) {
                    $informeDate = !empty($informe['informe_fecha']) 
                        ? date('Ymd', strtotime($informe['informe_fecha']))
                        : date('Ymd');
                    
                    $informesWithPdf[] = [
                        'orthanc_id' => null,
                        'study_id' => $informe['study_id'] ?? null,
                        'informe_id' => $informe['id'],
                        'study_date' => $informeDate,
                        'study_time' => !empty($informe['fecha_creacion']) 
                            ? date('His', strtotime($informe['fecha_creacion']))
                            : '120000',
                        'modality' => $informe['modality'] ?? 'DOC',
                        'study_description' => $informe['titulo'] ?? 'Informe Médico',
                        'patient_name' => $informe['patient_name'] ?? $patientName,
                        'patient_id' => $informe['patient_id'] ?? $patientId,
                        'pdf_path' => $informe['pdf_path'],
                        'viewer_url' => null,
                        'is_informe' => true
                    ];
                }
            }
        } catch (Exception $e) {
            @error_log('Error obteniendo informes: ' . $e->getMessage());
            // Continuar sin informes
        }
    }
    
    // Asociar informes con estudios (ahora guardamos TODOS los informes, no solo el más reciente)
    $informesMap = [];
    foreach ($informesWithPdf as $informe) {
        $studyId = $informe['study_id'] ?? null;
        if ($studyId) {
            if (!isset($informesMap[$studyId])) {
                $informesMap[$studyId] = [];
            }
            $informesMap[$studyId][] = $informe;
        }
    }
    
    // Ordenar informes por fecha (más reciente primero) dentro de cada estudio
    foreach ($informesMap as $studyId => &$informes) {
        usort($informes, function($a, $b) {
            $dateA = $a['study_date'] ?? '';
            $dateB = $b['study_date'] ?? '';
            if ($dateA === $dateB) {
                $timeA = $a['study_time'] ?? '';
                $timeB = $b['study_time'] ?? '';
                return strcmp($timeB, $timeA); // Más reciente primero
            }
            return strcmp($dateB, $dateA); // Más reciente primero
        });
    }
    unset($informes);
    
    // Agregar información de informe a estudios
    foreach ($studies as &$study) {
        $orthancId = $study['orthanc_id'] ?? null;
        if ($orthancId && isset($informesMap[$orthancId]) && !empty($informesMap[$orthancId])) {
            $informesDelEstudio = $informesMap[$orthancId];
            $study['has_informe'] = true;
            
            // Si hay un solo informe, mantener compatibilidad con formato anterior
            if (count($informesDelEstudio) === 1) {
                $informe = $informesDelEstudio[0];
                $study['informe_pdf_path'] = $informe['pdf_path'];
                $study['informe_titulo'] = $informe['study_description'] ?? 'Informe Médico';
                $study['informe_id'] = $informe['informe_id'];
            } else {
                // Múltiples informes: usar el más reciente como principal pero incluir todos
                $informePrincipal = $informesDelEstudio[0];
                $study['informe_pdf_path'] = $informePrincipal['pdf_path'];
                $study['informe_titulo'] = $informePrincipal['study_description'] ?? 'Informe Médico';
                $study['informe_id'] = $informePrincipal['informe_id'];
            }
            
            // Agregar array con todos los informes (para múltiples informes)
            $study['informes'] = array_map(function($inf) {
                return [
                    'pdf_path' => $inf['pdf_path'],
                    'titulo' => $inf['study_description'] ?? 'Informe Médico',
                    'informe_id' => $inf['informe_id'],
                    'fecha' => $inf['study_date'] ?? '',
                    'hora' => $inf['study_time'] ?? ''
                ];
            }, $informesDelEstudio);
        } else {
            $study['has_informe'] = false;
        }
    }
    unset($study);
    
    // Combinar estudios con informes sin estudio asociado
    $informesWithoutStudy = array_filter($informesWithPdf, function($informe) use ($studies) {
        $studyId = $informe['study_id'] ?? null;
        if (!$studyId) return true;
        foreach ($studies as $study) {
            if (($study['orthanc_id'] ?? null) === $studyId) {
                return false;
            }
        }
        return true;
    });
    
    $allStudies = array_merge($studies, array_values($informesWithoutStudy));

    $qaMeta = null;
    $qaFile = __DIR__ . '/../modules/qa-publicacion/QaPublicationService.php';
    if ($db && is_file($qaFile)) {
        require_once $qaFile;
        if (class_exists('QaPublicationService')) {
            foreach ($allStudies as &$qaStudy) {
                $orthancForMixed = (string) ($qaStudy['orthanc_id'] ?? '');
                if ($orthancForMixed !== '') {
                    $mixed = QaPublicationService::detectMixedSeries($orthancForMixed);
                    $qaStudy['qa_mixed_series'] = !empty($mixed['mixed']);
                }
            }
            unset($qaStudy);
            $qaMeta = QaPublicationService::applyToPortal($db, $patientId, $allStudies);
        }
    }
    
    // Obtener nombre del paciente de informes si está anonimizado
    if ($isOrthancNameAnonymized && $db) {
        try {
            $informesNameQuery = "SELECT DISTINCT patient_name 
                                  FROM informes 
                                  WHERE patient_id = :patient_id 
                                    AND patient_name IS NOT NULL 
                                    AND patient_name != '' 
                                    AND patient_name NOT LIKE 'Anonymized%'
                                  ORDER BY fecha_creacion DESC 
                                  LIMIT 1";
            $informesNameStmt = $db->prepare($informesNameQuery);
            $informesNameStmt->execute([':patient_id' => $patientId]);
            $informeNameResult = $informesNameStmt->fetch(PDO::FETCH_ASSOC);
            if ($informeNameResult && !empty($informeNameResult['patient_name']) && 
                strlen(trim($informeNameResult['patient_name'])) > 3) {
                $patientName = $informeNameResult['patient_name'];
            }
        } catch (Exception $e) {
            @error_log('Error obteniendo nombre de informes: ' . $e->getMessage());
        }
    }
    
    if (empty($patientName) && $isOrthancNameAnonymized && !empty($studies)) {
        $patientName = $studies[0]['patient_name'] ?? '';
    }
    
    // ============================================
    // OCTAVO PASO: ENVIAR RESPUESTA JSON
    // ============================================
    while (@ob_get_level()) {
        @ob_end_clean();
    }
    
    @http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => $allStudies,
        'patient_id' => $patientId,
        'patient_name' => $patientName,
        'total_studies' => count($studies),
        'total_informes' => count($informesWithPdf),
        'total_all' => count($allStudies),
        'qa' => $qaMeta,
        'server_status' => $serverStatus,
        'search_type' => $searchType,
        'paciente_data' => $pacienteData ? [
            'id_interno' => $pacienteData['id_interno'],
            'nombre' => $pacienteData['nombre'],
            'telefono' => $pacienteData['telefono'],
            'email' => $pacienteData['email']
        ] : null
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    exit;
    
} catch (Throwable $e) {
    @error_log('Error en get_patient_studies.php: ' . $e->getMessage());
    @error_log('Stack trace: ' . $e->getTraceAsString());
    sendJsonError('Error interno del servidor: ' . $e->getMessage(), 500);
}
?>
