<?php
/**
 * Endpoint para obtener estado de un estudio en R2
 * GET /api/cloud-storage/status/{orthancStudyId}
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

try {
    require_once __DIR__ . '/../../../config/database.php';
    require_once __DIR__ . '/../CloudStorageManager.php';
    
    // Obtener orthanc_study_id
    $orthancStudyId = $_GET['id'] ?? $_GET['orthanc_study_id'] ?? null;
    
    // Si viene en la URL como /status/{id}
    if (!$orthancStudyId) {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $parts = explode('/', trim($path, '/'));
        $statusIndex = array_search('status', $parts);
        if ($statusIndex !== false && isset($parts[$statusIndex + 1])) {
            $orthancStudyId = $parts[$statusIndex + 1];
        }
    }
    
    if (!$orthancStudyId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'orthanc_study_id es requerido']);
        exit;
    }
    
    // Obtener estado
    $manager = new CloudStorageManager();
    $status = $manager->getStudyStatus($orthancStudyId);
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => $status
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('[CLOUD_STORAGE_STATUS] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
