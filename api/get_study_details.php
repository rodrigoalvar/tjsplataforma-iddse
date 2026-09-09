<?php
// Endpoint para cargar detalles de un estudio bajo demanda
// Optimiza la carga inicial mostrando solo datos básicos

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    require_once 'OrthancClient.php';
    $orthancClient = new OrthancClient();
    
    // Verificar conexión
    $serverStatus = $orthancClient->getServerStatus();
    if ($serverStatus['status'] !== 'connected') {
        throw new Exception('No se puede conectar al servidor Orthanc: ' . $serverStatus['message']);
    }
    
    // Obtener parámetros (aceptar tanto studyId como study_id para compatibilidad)
    $studyId = $_GET['studyId'] ?? $_GET['study_id'] ?? null;
    $seriesIds = isset($_GET['seriesIds']) ? json_decode($_GET['seriesIds'], true) : null;
    
    if (!$studyId) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'studyId o study_id es requerido'
        ]);
        exit;
    }
    
    // Cargar detalles bajo demanda
    $details = $orthancClient->getStudyDetailsOnDemand($studyId, $seriesIds);
    
    if ($details === null) {
        throw new Exception('No se pudieron obtener los detalles del estudio');
    }
    
    echo json_encode([
        'success' => true,
        'data' => $details
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    error_log('[GET_STUDY_DETAILS] Exception: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al obtener detalles del estudio: ' . $e->getMessage(),
        'debug' => [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Error $e) {
    error_log('[GET_STUDY_DETAILS] Fatal Error: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error fatal al obtener detalles del estudio: ' . $e->getMessage(),
        'debug' => [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
?>

