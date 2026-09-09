<?php
/**
 * API para editar estudios en PACS (Orthanc)
 * Módulo PACS Manager - Sistema TJSMEDICAL
 * 
 * Actualiza tags DICOM de un estudio usando PATCH /studies/{id}/tags
 * Requiere permiso: pacs_manager
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/php_errors.log');
// Aumentar timeout para operaciones que pueden tardar (modificación de pacientes con muchos estudios)
ini_set('max_execution_time', 600); // 10 minutos
set_time_limit(600);

// Log de inicio para verificar que el script se está ejecutando
error_log('[PACS_MANAGER][EDIT] ===== SCRIPT INICIADO =====');
error_log('[PACS_MANAGER][EDIT] REQUEST_METHOD: ' . ($_SERVER['REQUEST_METHOD'] ?? 'NO_SET'));

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
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/lib/PacsStudyModifyLog.php';
require_once __DIR__ . '/lib/PacsModifyPostProcessor.php';

// Función para validar sesión sin hacer echo (misma que en list.php)
function validateSessionSimpleSilent() {
    try {
        $session_token = null;
        
        if (isset($_COOKIE['session_token']) && !empty($_COOKIE['session_token'])) {
            $session_token = $_COOKIE['session_token'];
        }
        
        if (empty($session_token)) {
            // Modo desarrollo: devolver usuario root
            return [
                'success' => true,
                'message' => 'Modo desarrollo - acceso sin sesión',
                'user' => [
                    'id' => 1,
                    'nombre' => 'Usuario',
                    'apellido' => 'Root',
                    'nivel' => 'root',
                    'especialidad' => null,
                    'permisos' => ['all', 'pacs_query', 'dashboard', 'estudios', 'informes', 'gestionInformes', 'usuarios', 'plantillas', 'visor', 'pacs_manager']
                ]
            ];
        }
        
        $user = new User();
        $user_data = $user->validateSession($session_token);
        
        if (!$user_data) {
            // Modo desarrollo si la validación falla
            return [
                'success' => true,
                'message' => 'Modo desarrollo - sesión inválida',
                'user' => [
                    'id' => 1,
                    'nombre' => 'Usuario',
                    'apellido' => 'Root',
                    'nivel' => 'root',
                    'permisos' => ['all', 'pacs_query', 'dashboard', 'estudios', 'informes', 'gestionInformes', 'usuarios', 'plantillas', 'visor', 'pacs_manager']
                ]
            ];
        }
        
        // Obtener permisos del usuario
        $permissions = [];
        if (!empty($user_data['permisos'])) {
            $permissions = json_decode($user_data['permisos'], true) ?: [];
        }
        
        // Si no hay permisos, asignar permisos por defecto según el nivel
        if (empty($permissions)) {
            switch ($user_data['nivel']) {
                case 'root':
                    $permissions = ['all'];
                    break;
                case 'admin':
                    $permissions = ['dashboard', 'estudios', 'pacs_query', 'informes', 'gestionInformes', 'usuarios', 'plantillas', 'visor'];
                    break;
                case 'user':
                    $permissions = ['dashboard', 'informes', 'grabacion'];
                    break;
                default:
                    $permissions = ['dashboard'];
            }
        }
        
        return [
            'success' => true,
            'message' => 'Sesión válida',
            'user' => [
                'id' => $user_data['id'],
                'nombre' => $user_data['nombre'],
                'apellido' => $user_data['apellido'],
                'nivel' => $user_data['nivel'],
                'especialidad' => $user_data['especialidad'] ?? null,
                'permisos' => $permissions
            ]
        ];
    } catch (Exception $e) {
        error_log('Error en validateSessionSimpleSilent: ' . $e->getMessage());
        return [
            'success' => true,
            'message' => 'Modo desarrollo - error manejado',
            'user' => [
                'id' => 1,
                'nombre' => 'Usuario',
                'apellido' => 'Root',
                'nivel' => 'root',
                'especialidad' => null,
                'permisos' => ['all', 'pacs_query', 'dashboard', 'estudios', 'informes', 'gestionInformes', 'usuarios', 'plantillas', 'visor', 'pacs_manager']
            ]
        ];
    }
}

// Verificar autenticación
try {
    $sessionValid = validateSessionSimpleSilent();
    if (!$sessionValid || !isset($sessionValid['success']) || !$sessionValid['success']) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'No autenticado'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Obtener ID de usuario de la sesión
    $currentUserId = null;
    if (isset($sessionValid['user']['id'])) {
        $currentUserId = $sessionValid['user']['id'];
    }
    
    // Verificar permiso pacs_manager
    $permissionManager = new PermissionManager();
    
    // Si tenemos el ID del usuario, pasarlo directamente
    if ($currentUserId) {
        $hasPermission = $permissionManager->hasPermission('pacs_manager', $currentUserId);
    } else {
        // Intentar obtenerlo automáticamente
        $hasPermission = $permissionManager->hasPermission('pacs_manager');
    }
} catch (Throwable $e) {
    error_log('[PACS_MANAGER][EDIT] Error verificando permisos: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error verificando permisos: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Verificar permiso pacs_manager (ya verificado arriba en el try-catch)
if (!$hasPermission) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'No tienes permisos para gestionar estudios PACS'
    ]);
    exit;
}

// Obtener datos del POST
$rawInput = file_get_contents('php://input');
error_log('[PACS_MANAGER][EDIT] Raw input recibido: ' . substr($rawInput, 0, 500));

$input = json_decode($rawInput, true);

if (!$input) {
    $jsonError = json_last_error_msg();
    error_log('[PACS_MANAGER][EDIT] Error decodificando JSON: ' . $jsonError);
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Datos inválidos: ' . $jsonError
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$studyId = $input['study_id'] ?? null;
$tags = $input['tags'] ?? [];
$deleteOriginalRequested = !isset($input['delete_original']) || !in_array($input['delete_original'], [false, 0, '0', 'false'], true);

// Logs detallados para diagnóstico
error_log('[PACS_MANAGER][EDIT] ===== INICIO EDICIÓN ESTUDIO =====');
error_log('[PACS_MANAGER][EDIT] Input completo recibido: ' . json_encode($input, JSON_PRETTY_PRINT));
error_log('[PACS_MANAGER][EDIT] study_id recibido: ' . ($studyId ?? 'NULL'));
error_log('[PACS_MANAGER][EDIT] study_id tipo: ' . gettype($studyId));
error_log('[PACS_MANAGER][EDIT] study_id longitud: ' . ($studyId ? strlen($studyId) : 0));
error_log('[PACS_MANAGER][EDIT] tags recibidos: ' . json_encode($tags, JSON_PRETTY_PRINT));

if (!$studyId) {
    error_log('[PACS_MANAGER][EDIT] ❌ ERROR: study_id es NULL o vacío');
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'study_id es requerido'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Validar formato del study_id (debe ser un ID interno de Orthanc)
// Los IDs de Orthanc suelen tener formato UUID con guiones
if (!preg_match('/^[a-f0-9\-]+$/i', $studyId)) {
    error_log('[PACS_MANAGER][EDIT] ⚠️ ADVERTENCIA: study_id tiene formato inusual: ' . $studyId);
}

if (empty($tags) || !is_array($tags)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'No se proporcionaron tags para actualizar o el formato es inválido'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Validar y formatear tags DICOM
    $validTags = [];
    $tagMapping = [
        'PatientName' => 'PatientName',
        'PatientID' => 'PatientID',
        'StudyDescription' => 'StudyDescription',
        'StudyDate' => 'StudyDate',
        'AccessionNumber' => 'AccessionNumber',
        'InstitutionName' => 'InstitutionName',
        'ReferringPhysicianName' => 'ReferringPhysicianName'
    ];
    
    foreach ($tags as $tagName => $tagValue) {
        if (isset($tagMapping[$tagName])) {
            // Permitir valores vacíos para eliminar el contenido del campo
            // Si el valor está vacío, enviarlo como cadena vacía
            if ($tagValue === '' || $tagValue === null) {
                $validTags[$tagName] = '';
                continue;
            }
            
            // Validar formato de fecha si es StudyDate
            if ($tagName === 'StudyDate') {
                // Convertir a formato DICOM YYYYMMDD
                if (strlen($tagValue) === 8 && ctype_digit($tagValue)) {
                    $validTags[$tagName] = $tagValue;
                } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tagValue)) {
                    // Convertir de YYYY-MM-DD a YYYYMMDD
                    $validTags[$tagName] = str_replace('-', '', $tagValue);
                } else {
                    // Intentar parsear como fecha
                    $timestamp = strtotime($tagValue);
                    if ($timestamp) {
                        $validTags[$tagName] = date('Ymd', $timestamp);
                    } else {
                        error_log("[PACS_MANAGER][EDIT] Fecha inválida: $tagValue");
                        continue;
                    }
                }
            } else {
                $validTags[$tagName] = trim($tagValue);
            }
        }
    }
    
    // Permitir tags vacíos (para eliminar contenido de campos)
    // Solo rechazar si no hay ningún tag en la petición
    // Permitir tags vacíos (para eliminar contenido de campos)
    // Solo rechazar si no hay ningún tag en la petición
    if (count($validTags) === 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'No se proporcionaron tags para actualizar'
        ]);
        exit;
    }
    
    // Usar $validTags directamente (ya incluye tags vacíos si el usuario quiere eliminarlos)
    $tagsToSend = $validTags;
    
    // Log antes de llamar a Orthanc
    error_log('[PACS_MANAGER][EDIT] ===== LLAMANDO A ORTHANC =====');
    error_log('[PACS_MANAGER][EDIT] study_id a enviar: ' . $studyId);
    error_log('[PACS_MANAGER][EDIT] tags válidos a enviar: ' . json_encode($validTags, JSON_PRETTY_PRINT));
    
    $pacsSender = new OrthancPacsSender();
    $oldSuid = PacsModifyPostProcessor::fetchStudyInstanceUid($pacsSender, $studyId);
    $estudiosId = null;
    $patientIdPacs = $validTags['PatientID'] ?? null;
    $patientNamePacs = $validTags['PatientName'] ?? null;
    $accessionNumber = !empty($validTags['AccessionNumber']) ? trim($validTags['AccessionNumber']) : null;
    $oldPatientIdPacs = null;
    $studyMetaResp = $pacsSender->makeRequestWithRetry('/studies/' . urlencode($studyId), 'GET', null, 15);
    if ($studyMetaResp['success'] && !empty($studyMetaResp['data'])) {
        $mt = $studyMetaResp['data']['MainDicomTags'] ?? [];
        $pt = $studyMetaResp['data']['PatientMainDicomTags'] ?? [];
        $oldPatientIdPacs = $mt['PatientID'] ?? $pt['PatientID'] ?? null;
        if (!$accessionNumber && !empty($mt['AccessionNumber'])) {
            $accessionNumber = trim($mt['AccessionNumber']);
        }
    }
    $modality = null;

    $database = new Database();
    $db = $database->getConnection();
    if ($db) {
        try {
            $estStmt = $db->prepare(
                'SELECT id, study_instance_uid, patient_id_pacs, patient_name_pacs, accession_number, modality
                 FROM estudios WHERE orthanc_study_id = ? OR study_instance_uid = ? LIMIT 1'
            );
            $estStmt->execute([$studyId, $oldSuid ?: $studyId]);
            $estRow = $estStmt->fetch(PDO::FETCH_ASSOC);
            if ($estRow) {
                $estudiosId = (int) $estRow['id'];
                if (!$oldSuid && !empty($estRow['study_instance_uid'])) {
                    $oldSuid = $estRow['study_instance_uid'];
                }
                $patientIdPacs = $patientIdPacs ?: ($estRow['patient_id_pacs'] ?? null);
                $patientNamePacs = $patientNamePacs ?: ($estRow['patient_name_pacs'] ?? null);
                $accessionNumber = $accessionNumber ?: ($estRow['accession_number'] ?? null);
                $oldPatientIdPacs = $oldPatientIdPacs ?: ($estRow['patient_id_pacs'] ?? null);
                $modality = $estRow['modality'] ?? null;
            }
        } catch (Exception $e) {
            error_log('[PACS_MANAGER][EDIT] Lookup estudios: ' . $e->getMessage());
        }
    }

    $tagsForLog = $validTags;
    if ($oldPatientIdPacs) {
        $tagsForLog['_old_patient_id'] = $oldPatientIdPacs;
    }
    if ($studyMetaResp['success'] && !empty($studyMetaResp['data'])) {
        $mt = $studyMetaResp['data']['MainDicomTags'] ?? [];
        $tagsForLog['_study_fingerprint'] = [
            'StudyDate' => $mt['StudyDate'] ?? '',
            'StudyDescription' => $mt['StudyDescription'] ?? '',
            'InstitutionName' => $mt['InstitutionName'] ?? '',
            'instances' => (int) (
                $studyMetaResp['data']['Instances']
                ?? $studyMetaResp['data']['NumberOfStudyRelatedInstances']
                ?? 0
            ),
        ];
    }

    $migrationLogId = $db ? PacsStudyModifyLog::create($db, [
        'user_id' => $currentUserId ?? null,
        'old_orthanc_study_id' => $studyId,
        'old_study_instance_uid' => $oldSuid,
        'estudios_id' => $estudiosId,
        'patient_id_pacs' => $patientIdPacs,
        'patient_name_pacs' => $patientNamePacs,
        'accession_number' => $accessionNumber,
        'modality' => $modality,
        'tags_requested' => $tagsForLog,
        'delete_original_requested' => $deleteOriginalRequested,
    ]) : null;

    $result = $pacsSender->updateStudyTags($studyId, $tagsToSend, ['defer_post_process' => true]);
    
    error_log('[PACS_MANAGER][EDIT] ===== RESPUESTA DE ORTHANC =====');
    error_log('[PACS_MANAGER][EDIT] Resultado: ' . json_encode($result, JSON_PRETTY_PRINT));
    
    if ($result['success']) {
        if (isset($result['async']) && $result['async'] && isset($result['job_id'])) {
            if ($db && $migrationLogId) {
                PacsStudyModifyLog::update($db, $migrationLogId, [
                    'status' => 'orthanc_running',
                    'orthanc_job_id' => $result['job_id'],
                    'modify_endpoint' => ($result['modify_via'] ?? 'studies') . '_async',
                ]);
            }
            echo json_encode([
                'success' => true,
                'async' => true,
                'job_id' => $result['job_id'],
                'migration_log_id' => $migrationLogId,
                'delete_original' => $deleteOriginalRequested,
                'message' => $result['message'] ?? 'Modificación iniciada en Orthanc. Puedes seguir trabajando; el progreso aparece en Trabajos.',
                'study_id' => $result['study_id'] ?? $studyId,
                'original_study_id' => $result['original_study_id'] ?? $studyId,
                'updated_tags' => array_keys($validTags),
            ], JSON_UNESCAPED_UNICODE);
        } else {
            $response = [
                'success' => true,
                'message' => 'Estudio actualizado exitosamente',
                'study_id' => $result['study_id'] ?? $studyId,
                'original_study_id' => $result['original_study_id'] ?? $studyId,
                'new_study_id' => ($result['study_id'] ?? $studyId) !== $studyId ? ($result['study_id'] ?? null) : null,
                'updated_tags' => array_keys($validTags),
                'note' => $result['note'] ?? null,
                'migration_log_id' => $migrationLogId,
                'delete_original' => $deleteOriginalRequested,
            ];

            if ($db && $migrationLogId && !empty($result['needs_post_process'])) {
                $newId = $result['study_id'] ?? $studyId;
                $jobData = ['Content' => ['Resources' => ['/studies/' . $newId]]];
                $post = PacsModifyPostProcessor::processAfterOrthancSuccess(
                    $db,
                    $pacsSender,
                    $migrationLogId,
                    $jobData,
                    $deleteOriginalRequested
                );
                $response = array_merge($response, $post);
                $response['study_id'] = $post['new_study_id'] ?? $newId;
                $response['success'] = !empty($post['success']);
            }

            echo json_encode($response, JSON_UNESCAPED_UNICODE);
        }
    } else {
        if ($db && $migrationLogId) {
            PacsStudyModifyLog::update($db, $migrationLogId, [
                'status' => 'failed',
                'error_message' => $result['error'] ?? 'Error Orthanc',
            ]);
        }
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $result['error'] ?? 'Error desconocido al actualizar estudio'
        ]);
    }
    
} catch (Throwable $e) {
    error_log('[PACS_MANAGER][EDIT] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al actualizar estudio: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

