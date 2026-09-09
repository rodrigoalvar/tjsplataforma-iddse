<?php
/**
 * API para subir estudios DICOM a PACS (Orthanc)
 * Módulo PACS Manager - Sistema TJSMEDICAL
 * 
 * Sube archivos DICOM individuales o múltiples (ZIP) a Orthanc
 * Endpoint: POST /instances
 * Requiere permiso: pacs_manager
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/php_errors.log');
// Timeout extendido para subida de archivos grandes
ini_set('max_execution_time', 300); // 5 minutos
set_time_limit(300);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../OrthancPacsSender.php';
require_once __DIR__ . '/../../middleware/permissions.php';
require_once __DIR__ . '/../auth/validate-session-simple.php';

// Verificar autenticación
$sessionValid = validateSessionSimple();
if (!$sessionValid['success']) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'No autenticado'
    ]);
    exit;
}

// Verificar permiso pacs_manager
$permissionManager = new PermissionManager();
$hasPermission = $permissionManager->hasPermission('pacs_manager');

if (!$hasPermission) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'No tienes permisos para gestionar estudios PACS'
    ]);
    exit;
}

try {
    error_log('[PACS_MANAGER][UPLOAD] ===== INICIANDO SUBIDA =====');
    
    // Verificar que se enviaron archivos
    // Cuando se envía 'files[]' desde JavaScript, PHP lo recibe como 'files' con estructura de array
    $files = null;
    if (isset($_FILES['files'])) {
        $files = $_FILES['files'];
    }
    
    if (!$files || empty($files['name']) || 
        (is_array($files['name']) && count(array_filter($files['name'])) === 0) ||
        (!is_array($files['name']) && empty($files['name']))) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'No se proporcionaron archivos para subir'
        ]);
        exit;
    }
    
    $uploadResults = [];
    $pacsSender = new OrthancPacsSender();
    
    // Helper functions para obtener información (usando reflexión para acceder a método privado)
    $getStudyInfo = function($studyId) use ($pacsSender) {
        $reflection = new ReflectionClass($pacsSender);
        $method = $reflection->getMethod('makeRequestWithRetry');
        $method->setAccessible(true);
        return $method->invoke($pacsSender, '/studies/' . urlencode($studyId), 'GET', null, 10);
    };
    
    $getPatientInfo = function($patientId) use ($pacsSender) {
        $reflection = new ReflectionClass($pacsSender);
        $method = $reflection->getMethod('makeRequestWithRetry');
        $method->setAccessible(true);
        return $method->invoke($pacsSender, '/patients/' . urlencode($patientId), 'GET', null, 10);
    };
    
    // Manejar múltiples archivos o uno solo
    // Si 'name' es array, son múltiples archivos; si es string, es un solo archivo
    $fileCount = is_array($files['name']) ? count($files['name']) : 1;
    error_log('[PACS_MANAGER][UPLOAD] Archivos recibidos: ' . $fileCount);
    
    for ($i = 0; $i < $fileCount; $i++) {
        $fileName = is_array($files['name']) ? $files['name'][$i] : $files['name'];
        $fileTmpName = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
        $fileError = is_array($files['error']) ? $files['error'][$i] : $files['error'];
        $fileSize = is_array($files['size']) ? $files['size'][$i] : $files['size'];
        $fileType = is_array($files['type']) ? $files['type'][$i] : $files['type'];
        
        error_log('[PACS_MANAGER][UPLOAD] Procesando archivo: ' . $fileName . ' (' . $fileSize . ' bytes)');
        
        // Validar error de subida
        if ($fileError !== UPLOAD_ERR_OK) {
            $errorMsg = 'Error al subir archivo: ';
            switch ($fileError) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $errorMsg .= 'El archivo excede el tamaño máximo permitido';
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $errorMsg .= 'El archivo se subió parcialmente';
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $errorMsg .= 'No se subió ningún archivo';
                    break;
                case UPLOAD_ERR_NO_TMP_DIR:
                    $errorMsg .= 'Falta la carpeta temporal';
                    break;
                case UPLOAD_ERR_CANT_WRITE:
                    $errorMsg .= 'Error al escribir el archivo en disco';
                    break;
                case UPLOAD_ERR_EXTENSION:
                    $errorMsg .= 'Una extensión de PHP detuvo la subida';
                    break;
                default:
                    $errorMsg .= 'Error desconocido (' . $fileError . ')';
            }
            
            $uploadResults[] = [
                'success' => false,
                'file_name' => $fileName,
                'error' => $errorMsg
            ];
            continue;
        }
        
        // Validar tamaño (máximo 500MB por archivo)
        $maxFileSize = 500 * 1024 * 1024; // 500MB
        if ($fileSize > $maxFileSize) {
            $uploadResults[] = [
                'success' => false,
                'file_name' => $fileName,
                'error' => 'El archivo excede el tamaño máximo de 500MB'
            ];
            continue;
        }
        
        // Validar que el archivo existe
        if (!file_exists($fileTmpName)) {
            $uploadResults[] = [
                'success' => false,
                'file_name' => $fileName,
                'error' => 'El archivo temporal no existe'
            ];
            continue;
        }
        
        // Leer contenido del archivo
        $fileContent = file_get_contents($fileTmpName);
        if ($fileContent === false) {
            $uploadResults[] = [
                'success' => false,
                'file_name' => $fileName,
                'error' => 'No se pudo leer el contenido del archivo'
            ];
            continue;
        }
        
        // Determinar si es ZIP o DICOM individual
        $isZip = false;
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($fileExtension === 'zip') {
            $isZip = true;
            error_log('[PACS_MANAGER][UPLOAD] Archivo ZIP detectado: ' . $fileName);
        } else {
            // Validar que sea un archivo DICOM (verificar magic bytes)
            // DICOM files start with "DICM" at offset 128
            if (strlen($fileContent) >= 132) {
                $dicomPrefix = substr($fileContent, 128, 4);
                if ($dicomPrefix !== 'DICM') {
                    error_log('[PACS_MANAGER][UPLOAD] ⚠️ Archivo no parece ser DICOM válido: ' . $fileName);
                    // Continuar de todas formas, Orthanc validará
                }
            }
        }
        
        // Subir a Orthanc
        try {
            error_log('[PACS_MANAGER][UPLOAD] Subiendo a Orthanc: ' . $fileName);
            $result = $pacsSender->uploadDicomInstance($fileContent, $isZip);
            
            if ($result['success']) {
                error_log('[PACS_MANAGER][UPLOAD] ✅ Archivo subido exitosamente: ' . $fileName);
                
                // Obtener detalles del estudio si se subió exitosamente
                $studyDetails = null;
                if (!empty($result['study_id'])) {
                    try {
                        // Usar funciones helper con reflexión
                        $studyInfo = $getStudyInfo($result['study_id']);
                        
                        if ($studyInfo && isset($studyInfo['success']) && $studyInfo['success'] && isset($studyInfo['data'])) {
                            $studyData = $studyInfo['data'];
                            
                            // Obtener información del paciente
                            $patientInfo = null;
                            if (isset($studyData['ParentPatient'])) {
                                $patientResponse = $getPatientInfo($studyData['ParentPatient']);
                                
                                if ($patientResponse && isset($patientResponse['success']) && $patientResponse['success'] && isset($patientResponse['data'])) {
                                    $patientInfo = $patientResponse['data'];
                                }
                                
                                if ($patientResponse && isset($patientResponse['data'])) {
                                    $patientInfo = $patientResponse['data'];
                                }
                            }
                            
                            // Construir detalles del estudio
                            $studyDetails = [
                                'study_id' => $result['study_id'],
                                'study_instance_uid' => $studyData['MainDicomTags']['StudyInstanceUID'] ?? '',
                                'study_date' => $studyData['MainDicomTags']['StudyDate'] ?? '',
                                'study_time' => $studyData['MainDicomTags']['StudyTime'] ?? '',
                                'study_description' => $studyData['MainDicomTags']['StudyDescription'] ?? '',
                                'accession_number' => $studyData['MainDicomTags']['AccessionNumber'] ?? '',
                                'referring_physician' => $studyData['MainDicomTags']['ReferringPhysicianName'] ?? '',
                                'institution_name' => $studyData['MainDicomTags']['InstitutionName'] ?? '',
                                'patient' => [
                                    'patient_id' => $patientInfo['MainDicomTags']['PatientID'] ?? 
                                                  ($studyData['PatientMainDicomTags']['PatientID'] ?? ''),
                                    'patient_name' => $patientInfo['MainDicomTags']['PatientName'] ?? 
                                                     ($studyData['PatientMainDicomTags']['PatientName'] ?? ''),
                                    'patient_birth_date' => $patientInfo['MainDicomTags']['PatientBirthDate'] ?? 
                                                          ($studyData['PatientMainDicomTags']['PatientBirthDate'] ?? ''),
                                    'patient_sex' => $patientInfo['MainDicomTags']['PatientSex'] ?? 
                                                    ($studyData['PatientMainDicomTags']['PatientSex'] ?? '')
                                ],
                                'series_count' => count($studyData['Series'] ?? []),
                                'instances_count' => isset($studyData['Instances']) ? count($studyData['Instances']) : 0
                            ];
                        }
                    } catch (Exception $e) {
                        error_log('[PACS_MANAGER][UPLOAD] ⚠️ Error obteniendo detalles del estudio: ' . $e->getMessage());
                        // Continuar sin detalles, no es crítico
                    }
                }
                
                $uploadResults[] = [
                    'success' => true,
                    'file_name' => $fileName,
                    'instance_id' => $result['instance_id'] ?? null,
                    'study_id' => $result['study_id'] ?? null,
                    'patient_id' => $result['patient_id'] ?? null,
                    'study_details' => $studyDetails,
                    'message' => 'Archivo subido exitosamente'
                ];
            } else {
                error_log('[PACS_MANAGER][UPLOAD] ❌ Error al subir archivo: ' . $fileName . ' - ' . ($result['error'] ?? 'Error desconocido'));
                $uploadResults[] = [
                    'success' => false,
                    'file_name' => $fileName,
                    'error' => $result['error'] ?? 'Error desconocido al subir archivo'
                ];
            }
        } catch (Exception $e) {
            error_log('[PACS_MANAGER][UPLOAD] ❌ Excepción al subir archivo: ' . $fileName . ' - ' . $e->getMessage());
            $uploadResults[] = [
                'success' => false,
                'file_name' => $fileName,
                'error' => 'Error al subir archivo: ' . $e->getMessage()
            ];
        }
        
        // Limpiar archivo temporal
        @unlink($fileTmpName);
    }
    
    // Calcular estadísticas
    $successCount = count(array_filter($uploadResults, function($r) { return $r['success']; }));
    $failureCount = count($uploadResults) - $successCount;
    
    error_log('[PACS_MANAGER][UPLOAD] ===== SUBIDA COMPLETADA =====');
    error_log('[PACS_MANAGER][UPLOAD] Exitosos: ' . $successCount . ', Fallidos: ' . $failureCount);
    
    // Retornar resultados
    $allSuccess = $failureCount === 0;
    http_response_code($allSuccess ? 200 : ($successCount > 0 ? 207 : 500)); // 207 Multi-Status si hay éxitos y fallos
    
    echo json_encode([
        'success' => $allSuccess,
        'message' => $allSuccess 
            ? "Todos los archivos se subieron exitosamente ($successCount archivo(s))"
            : "Subida completada: $successCount exitoso(s), $failureCount fallido(s)",
        'total_files' => count($uploadResults),
        'success_count' => $successCount,
        'failure_count' => $failureCount,
        'results' => $uploadResults
    ]);
    
} catch (Exception $e) {
    error_log('[PACS_MANAGER][UPLOAD] ❌ Excepción: ' . $e->getMessage());
    error_log('[PACS_MANAGER][UPLOAD] Trace: ' . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al procesar la subida: ' . $e->getMessage()
    ]);
}
