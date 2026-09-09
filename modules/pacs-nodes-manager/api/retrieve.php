<?php
/**
 * API para recuperación C-MOVE de estudios desde nodos PACS
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Endpoint: POST /api/pacs-nodes-manager/retrieve.php
 */

// Habilitar reporte de errores y logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Archivo de log específico para retrieve.php
$logFile = __DIR__ . '/../../../logs/pacs-nodes-retrieve.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
ini_set('error_log', $logFile);

// Capturar errores fatales para devolver JSON válido
register_shutdown_function(function() use ($logFile) {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log("[RETRIEVE] Fatal error capturado: " . $error['message'] . " en " . $error['file'] . ":" . $error['line']);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo json_encode([
            'success' => false,
            'error' => 'Error interno del servidor',
            'detail' => 'Error fatal: ' . $error['message']
        ], JSON_UNESCAPED_UNICODE);
    }
});

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../lib/cloner_order_helpers.php';
require_once __DIR__ . '/../lib/retrieve_execute.php';

// Cargar PacsNodeConfig si existe (para sincronización con Orthanc)
$pacsNodeConfigLoaded = false;
if (file_exists(__DIR__ . '/../PacsNodeConfig.php')) {
    require_once __DIR__ . '/../PacsNodeConfig.php';
    $pacsNodeConfigLoaded = true;
}

try {
    // Log del request raw
    $rawInput = file_get_contents('php://input');
    error_log("[RETRIEVE] Raw input: " . $rawInput);
    
    // Verificar autenticación
    $user = requirePacsNodesAuth('pacs_nodes_manager');
    
    // Obtener datos del request
    $data = json_decode($rawInput, true);
    
    // Log del array parseado
    error_log("[RETRIEVE] Parsed data: " . json_encode($data, JSON_PRETTY_PRINT));
    
    if (!$data) {
        $jsonError = json_last_error_msg();
        error_log("[RETRIEVE] JSON decode error: " . $jsonError);
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Datos JSON inválidos',
            'detail' => $jsonError
        ]);
        exit();
    }
    
    $nodeId = $data['node_id'] ?? null;
    $studyInstanceUIDs = $data['StudyInstanceUIDs'] ?? [];
    $callbackUrl = $data['Callback'] ?? null;
    // TargetAet debe ser el AET del nodo remoto (no el AET del Orthanc local)
    // Si no se especifica, usar el AET del nodo configurado
    $targetAet = $data['TargetAet'] ?? null;
    $clonerOrderId = isset($data['cloner_order_id']) ? (int) $data['cloner_order_id'] : 0;
    if ($clonerOrderId < 1) {
        $clonerOrderId = null;
    }
    
    error_log("[RETRIEVE] nodeId=$nodeId, studyInstanceUIDs=" . json_encode($studyInstanceUIDs) . ", targetAet=$targetAet (será el AET del nodo si no se especifica), clonerOrderId=" . ($clonerOrderId ?? 'null'));
    
    $db = getDBConnection();
    if (!$db) {
        sendErrorResponse('No se pudo conectar a la base de datos', 500);
    }
    
    $exec = pacs_nodes_run_retrieve($db, $user, $data, $pacsNodeConfigLoaded);
    if (empty($exec['success'])) {
        http_response_code((int) ($exec['http_code'] ?? 500));
        echo encodeApiJson([
            'success' => false,
            'error' => $exec['error'] ?? 'Error',
            'detail' => $exec['detail'] ?? null,
        ]);
        exit();
    }
    sendSuccessResponse($exec['data']);
    
} catch (Exception $e) {
    $errorMessage = $e->getMessage();
    error_log("[RETRIEVE] Exception general: " . $errorMessage);
    error_log("[RETRIEVE] Stack trace: " . $e->getTraceAsString());
    
    // Determinar código HTTP apropiado
    $httpCode = 500;
    if (strpos($errorMessage, 'requerido') !== false || 
        strpos($errorMessage, 'inválido') !== false ||
        strpos($errorMessage, 'no encontrado') !== false ||
        strpos($errorMessage, 'JSON') !== false) {
        $httpCode = 400;
    }
    
    // Mejorar mensaje de error para el usuario
    if (strpos($errorMessage, 'Unknown DICOM tag') !== false) {
        $userMessage = 'Error al recuperar el estudio. El nodo remoto no pudo procesar la solicitud. Verifique que el nodo esté correctamente configurado y que el estudio exista en el nodo remoto.';
    } elseif (strpos($errorMessage, 'HTTP 500') !== false) {
        $userMessage = 'Error del servidor PACS remoto. Verifique la conectividad y configuración del nodo.';
    } else {
        $userMessage = $errorMessage;
    }
    
    http_response_code($httpCode);
    echo json_encode([
        'success' => false,
        'error' => $userMessage,
        'detail' => $errorMessage
    ]);
    exit();
}
