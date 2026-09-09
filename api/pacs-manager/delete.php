<?php
/**
 * API para eliminar estudios en PACS (Orthanc)
 * Módulo PACS Manager - Sistema TJSMEDICAL
 * 
 * Elimina un estudio completo usando DELETE /studies/{id}
 * Requiere permiso: pacs_manager
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
// Timeout generoso: estudios grandes pueden tardar varios minutos (reintentos Orthanc incluidos).
ini_set('max_execution_time', 600);
set_time_limit(600);

// Limpiar cualquier output previo
if (ob_get_level()) {
    ob_end_clean();
}
ob_start();

// Registrar función para capturar errores fatales
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        error_log('[PACS_MANAGER][DELETE] Error fatal: ' . $error['message'] . ' en ' . $error['file'] . ':' . $error['line']);
        
        // Limpiar cualquier salida
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Enviar respuesta JSON de error
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
        }
        
        echo json_encode([
            'success' => false,
            'error' => 'Error interno del servidor: ' . $error['message'],
            'error_type' => 'Fatal Error',
            'error_file' => $error['file'],
            'error_line' => $error['line']
        ]);
        exit;
    }
});

// Enviar headers solo si no se han enviado ya
if (!headers_sent()) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    exit(0);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../auth/validate-session-simple.php';
require_once __DIR__ . '/../OrthancPacsSender.php';
require_once __DIR__ . '/../../middleware/permissions.php';
require_once __DIR__ . '/lib/StudyDeleteImpact.php';
require_once __DIR__ . '/lib/PacsStudyDeleteLog.php';

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

// Obtener study_id y metadatos opcionales
$studyId = null;
$studyInstanceUid = null;
$patientIdPacs = null;
$patientNamePacs = null;
$input = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $studyId = $input['study_id'] ?? null;
    $studyInstanceUid = $input['study_instance_uid'] ?? null;
    $patientIdPacs = $input['patient_id'] ?? null;
    $patientNamePacs = $input['patient_name'] ?? null;
} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $studyId = $_GET['study_id'] ?? null;
    $studyInstanceUid = $_GET['study_instance_uid'] ?? null;
}

if (!$studyId) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'study_id es requerido'
    ]);
    exit;
}

try {
    // Limpiar buffer antes de procesar
    ob_clean();
    
    // Log de inicio
    error_log('[PACS_MANAGER][DELETE] Iniciando eliminación de estudio: ' . $studyId);
    error_log('[PACS_MANAGER][DELETE] Study ID length: ' . strlen($studyId));
    error_log('[PACS_MANAGER][DELETE] Study ID type: ' . gettype($studyId));
    
    // Validar que study_id no esté vacío
    if (empty($studyId) || trim($studyId) === '') {
        ob_end_clean();
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'study_id no puede estar vacío'
        ]);
        exit;
    }
    
    // Snapshot de impacto antes de borrar (auditoría)
    $database = new Database();
    $db = $database->getConnection();
    $impactAnalysis = null;
    $userId = $sessionValid['user']['id'] ?? null;
    if ($db) {
        $impactAnalysis = StudyDeleteImpact::analyze($db, $studyId, $studyInstanceUid);
    }

    // Eliminar estudio de Orthanc
    $pacsSender = new OrthancPacsSender();
    $result = $pacsSender->deleteStudy($studyId);
    
    // Log del resultado completo
    error_log('[PACS_MANAGER][DELETE] Resultado completo: ' . json_encode($result, JSON_PRETTY_PRINT));
    error_log('[PACS_MANAGER][DELETE] Resultado success: ' . ($result['success'] ? 'true' : 'false'));
    if (isset($result['error'])) {
        error_log('[PACS_MANAGER][DELETE] Error en resultado: ' . $result['error']);
    }
    if (isset($result['http_code'])) {
        error_log('[PACS_MANAGER][DELETE] HTTP code de Orthanc: ' . $result['http_code']);
    }
    
    // Limpiar buffer antes de enviar respuesta
    ob_clean();
    
    if ($result['success']) {
        $pacsRefsCleared = 0;
        if ($db) {
            $pacsRefsCleared = StudyDeleteImpact::clearInformesPacsRefs(
                $db,
                $studyId,
                $studyInstanceUid ?: ($impactAnalysis['study_instance_uid'] ?? null)
            );
            PacsStudyDeleteLog::create($db, [
                'user_id' => $userId,
                'orthanc_study_id' => $studyId,
                'study_instance_uid' => $studyInstanceUid ?: ($impactAnalysis['study_instance_uid'] ?? null),
                'patient_id_pacs' => $patientIdPacs,
                'patient_name_pacs' => $patientNamePacs,
                'impact_snapshot' => $impactAnalysis['impact'] ?? null,
                'severity' => $impactAnalysis['severity'] ?? null,
                'orthanc_delete_success' => true,
                'pacs_refs_cleared' => $pacsRefsCleared,
            ]);
        }

        echo json_encode([
            'success' => true,
            'message' => $result['message'] ?? 'Estudio eliminado exitosamente',
            'study_id' => $studyId,
            'pacs_refs_cleared' => $pacsRefsCleared,
            'impact_severity' => $impactAnalysis['severity'] ?? null,
        ]);
    } else {
        // Determinar código HTTP apropiado basado en el error
        $httpCode = 500;
        $errorMsg = $result['error'] ?? 'Error desconocido al eliminar estudio';
        
        // Si el error indica que el estudio no existe, usar 404
        if (stripos($errorMsg, 'not found') !== false || 
            stripos($errorMsg, '404') !== false ||
            stripos($errorMsg, 'no existe') !== false ||
            (isset($result['http_code']) && $result['http_code'] == 404)) {
            $httpCode = 404;
        }
        
        // Si Orthanc devolvió un código HTTP específico, usarlo
        if (isset($result['http_code']) && $result['http_code'] >= 400) {
            $httpCode = $result['http_code'];
        }
        
        error_log('[PACS_MANAGER][DELETE] Enviando error HTTP ' . $httpCode . ': ' . $errorMsg);

        if ($db && $impactAnalysis) {
            PacsStudyDeleteLog::create($db, [
                'user_id' => $userId,
                'orthanc_study_id' => $studyId,
                'study_instance_uid' => $studyInstanceUid ?: ($impactAnalysis['study_instance_uid'] ?? null),
                'patient_id_pacs' => $patientIdPacs,
                'patient_name_pacs' => $patientNamePacs,
                'impact_snapshot' => $impactAnalysis['impact'] ?? null,
                'severity' => $impactAnalysis['severity'] ?? null,
                'orthanc_delete_success' => false,
                'pacs_refs_cleared' => 0,
                'error_message' => $errorMsg,
            ]);
        }
        
        http_response_code($httpCode);
        echo json_encode([
            'success' => false,
            'error' => $errorMsg,
            'study_id' => $studyId
        ]);
    }
    
} catch (Exception $e) {
    $errorMessage = $e->getMessage();
    $errorTrace = $e->getTraceAsString();
    
    error_log('[PACS_MANAGER][DELETE] Excepción capturada: ' . $errorMessage);
    error_log('[PACS_MANAGER][DELETE] Archivo: ' . $e->getFile() . ' Línea: ' . $e->getLine());
    error_log('[PACS_MANAGER][DELETE] Trace: ' . $errorTrace);
    
    // Limpiar buffer antes de enviar error
    ob_clean();
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al eliminar estudio: ' . $errorMessage,
        'error_type' => get_class($e),
        'error_file' => $e->getFile(),
        'error_line' => $e->getLine(),
        'study_id' => $studyId ?? null
    ]);
} catch (Error $e) {
    // Capturar errores fatales de PHP 7+
    $errorMessage = $e->getMessage();
    $errorTrace = $e->getTraceAsString();
    
    error_log('[PACS_MANAGER][DELETE] Error fatal capturado: ' . $errorMessage);
    error_log('[PACS_MANAGER][DELETE] Archivo: ' . $e->getFile() . ' Línea: ' . $e->getLine());
    error_log('[PACS_MANAGER][DELETE] Trace: ' . $errorTrace);
    
    // Limpiar buffer antes de enviar error
    ob_clean();
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error fatal al eliminar estudio: ' . $errorMessage,
        'error_type' => get_class($e),
        'error_file' => $e->getFile(),
        'error_line' => $e->getLine(),
        'study_id' => $studyId ?? null
    ]);
}

