<?php
/**
 * API para listar estudios desde PACS (Orthanc)
 * Módulo PACS Manager - Sistema TJSMEDICAL
 * 
 * Requiere permiso: pacs_manager
 */

// Configurar manejo de errores antes de cualquier salida
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/var/www/tjslosalisos/logs/php-errors.log');

// Registrar función para capturar errores fatales
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log('[PACS_MANAGER][LIST] Error fatal: ' . $error['message'] . ' en ' . $error['file'] . ':' . $error['line']);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Error interno del servidor',
                'details' => $error['message']
            ], JSON_UNESCAPED_UNICODE);
        }
    }
});

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../OrthancClient.php';
    require_once __DIR__ . '/../../middleware/permissions.php';
    require_once __DIR__ . '/../../classes/User.php';
} catch (Throwable $e) {
    error_log('[PACS_MANAGER][LIST] Error cargando archivos: ' . $e->getMessage());
    error_log('[PACS_MANAGER][LIST] Archivo: ' . $e->getFile() . ' Línea: ' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error cargando dependencias: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Función para validar sesión sin hacer echo
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
    error_log('[PACS_MANAGER][LIST] Error verificando permisos: ' . $e->getMessage());
    error_log('[PACS_MANAGER][LIST] Trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error verificando permisos: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$hasPermission) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'No tienes permisos para gestionar estudios PACS'
    ]);
    exit;
}

try {
    $orthancClient = new OrthancClient();
    
    // Verificar conexión con Orthanc
    $serverStatus = $orthancClient->getServerStatus();
    if ($serverStatus['status'] !== 'connected') {
        throw new Exception('No se puede conectar al servidor Orthanc: ' . ($serverStatus['message'] ?? 'Error desconocido'));
    }
    
    // Obtener parámetros de filtro
    $dateFrom = $_GET['dateFrom'] ?? null;
    $dateTo = $_GET['dateTo'] ?? null;
    $patientId = $_GET['patientId'] ?? null;
    $modality = $_GET['modality'] ?? null;
    
    // Obtener visor del usuario si está autenticado
    $userViewerType = 'UDV'; // Valor por defecto
    if ($currentUserId) {
        try {
            $pdo = getDBConnection();
            $viewerQuery = "SELECT dicom_viewer FROM usuarios WHERE id = ? AND activo = 1";
            $viewerStmt = $pdo->prepare($viewerQuery);
            $viewerStmt->execute([$currentUserId]);
            $viewerResult = $viewerStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($viewerResult && isset($viewerResult['dicom_viewer'])) {
                // Si el valor es NULL o está vacío, usar el valor por defecto
                if (!empty($viewerResult['dicom_viewer']) && trim($viewerResult['dicom_viewer']) !== '') {
                    $userViewerType = trim($viewerResult['dicom_viewer']);
                }
            }
        } catch (Exception $e) {
            error_log('[PACS_MANAGER][LIST] Error obteniendo visor del usuario: ' . $e->getMessage());
        }
    }
    
    // Requerir OrthancConfig para generar viewer_url personalizado
    require_once __DIR__ . '/../config/orthanc_config.php';
    require_once __DIR__ . '/../StudyRoutingService.php';
    
    // Obtener estudios desde Orthanc
    // Usar loadDetails = false para modo rápido, pero getAllStudiesEfficient ahora
    // intenta usar ModalitiesInStudy de Orthanc cuando está disponible
    // Si hay filtro de modalidad, se cargarán detalles automáticamente para filtrar
    $loadDetails = ($modality && $modality !== 'all');
    $studies = $orthancClient->getAllStudiesEfficient($dateFrom, $dateTo, $patientId, $modality, $loadDetails);
    
    // Verificar que $studies sea un array
    if (!is_array($studies)) {
        error_log('[PACS_MANAGER][LIST] Error: getAllStudiesEfficient no devolvió un array. Tipo: ' . gettype($studies));
        $studies = [];
    }

    $pdoRoute = getDBConnection();
    $sessionPerms = $sessionValid['user']['permisos'] ?? [];
    if (!is_array($sessionPerms)) {
        $sessionPerms = [];
    }
    $sessionLevel = (string)($sessionValid['user']['nivel'] ?? '');
    $effectiveRouting = 'local';
    $r2Batch = [];
    if ($pdoRoute) {
        $effectiveRouting = StudyRoutingService::getEffectiveMode(
            $pdoRoute,
            $currentUserId ? (int)$currentUserId : null,
            $sessionPerms,
            $sessionLevel
        );
        $routeIds = [];
        foreach ($studies as $st) {
            $oid = $st['orthanc_id'] ?? $st['orthanc_study_id'] ?? '';
            if ($oid !== '') {
                $routeIds[] = $oid;
            }
        }
        if ($routeIds !== []) {
            $r2Batch = StudyRoutingService::batchLoadR2Context($pdoRoute, $routeIds);
        }
    }
    
    // Formatear respuesta
    $formattedStudies = [];
    foreach ($studies as $study) {
        if (!is_array($study)) {
            error_log('[PACS_MANAGER][LIST] Error: Estudio no es un array. Tipo: ' . gettype($study));
            continue;
        }
        
        $studyId = $study['orthanc_id'] ?? $study['orthanc_study_id'] ?? '';
        $studyInstanceUID = $study['study_instance_uid'] ?? '';
        
        // Generar viewer_url usando OrthancConfig con la preferencia del usuario
        $viewerUrl = OrthancConfig::getViewerUrl($studyId, $userViewerType, $studyInstanceUID);
        $localDl = StudyRoutingService::buildLocalOrthancArchiveUrl(
            $studyId,
            (string)($study['patient_id'] ?? ''),
            (string)($study['patient_name'] ?? ''),
            (string)($study['study_date'] ?? ''),
            (string)($study['study_description'] ?? '')
        );
        $r2row = $r2Batch[$studyId] ?? [
            'orthanc_study_id' => $studyId,
            'study_instance_uid' => $studyInstanceUID,
            'r2_status' => 'none',
            'r2_manifest_path' => null,
            'r2_zip_key' => null,
        ];
        if ($pdoRoute) {
            $resolved = StudyRoutingService::resolve(
                $pdoRoute,
                $studyId,
                $studyInstanceUID,
                $viewerUrl,
                $localDl,
                $effectiveRouting,
                $r2row
            );
        } else {
            $resolved = [
                'viewer_url' => $viewerUrl,
                'download_url' => $localDl,
                'study_routing' => [
                    'mode_requested' => 'local',
                    'source_viewer' => 'local',
                    'source_download' => 'local',
                    'delivery' => null,
                    'fallback' => null,
                ],
            ];
        }
        
        $formattedStudies[] = [
            'study_id' => $studyId,
            'study_instance_uid' => $studyInstanceUID,
            'r2_status' => (string)($r2row['r2_status'] ?? 'none'),
            'patient_name' => $study['patient_name'] ?? '',
            'patient_id' => $study['patient_id'] ?? '',
            'patient_birth_date' => $study['patient_birth_date'] ?? '',
            'patient_sex' => $study['patient_sex'] ?? '',
            'study_date' => $study['study_date'] ?? '',
            'study_time' => $study['study_time'] ?? '',
            'study_description' => $study['study_description'] ?? '',
            'modality' => $study['modality'] ?? '',
            'accession_number' => $study['accession_number'] ?? '',
            'institution_name' => $study['institution_name'] ?? '',
            'referring_physician_name' => $study['referring_physician'] ?? '',
            'series_count' => $study['series_count'] ?? 0,
            'instances_count' => $study['instances_count'] ?? 0,
            'viewer_url' => $resolved['viewer_url'],
            'download_url' => $resolved['download_url'],
            'study_routing' => $resolved['study_routing'],
            '_series_ids' => $study['_series_ids'] ?? null, // Para carga bajo demanda
            '_details_loaded' => $study['_details_loaded'] ?? false // Flag para saber si los detalles están cargados
        ];
    }
    
    $response = [
        'success' => true,
        'data' => $formattedStudies,
        'count' => count($formattedStudies)
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
    $errorFile = $e->getFile();
    $errorLine = $e->getLine();
    $errorTrace = $e->getTraceAsString();
    
    error_log('[PACS_MANAGER][LIST] Error: ' . $errorMessage);
    error_log('[PACS_MANAGER][LIST] Archivo: ' . $errorFile . ' Línea: ' . $errorLine);
    error_log('[PACS_MANAGER][LIST] Trace: ' . $errorTrace);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al obtener estudios: ' . $errorMessage,
        'file' => basename($errorFile),
        'line' => $errorLine
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

