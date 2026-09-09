<?php
/**
 * Endpoint para encolar estudios en la cola de R2
 * POST /api/cloud-storage/enqueue
 * 
 * Body: { "orthanc_study_id": "..." }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

try {
    require_once __DIR__ . '/../../../config/database.php';
    require_once __DIR__ . '/../CloudStorageManager.php';
    
    // Obtener datos del request
    $input = json_decode(file_get_contents('php://input'), true);
    $orthancStudyId = $input['orthanc_study_id'] ?? $_POST['orthanc_study_id'] ?? null;
    
    if (!$orthancStudyId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'orthanc_study_id es requerido']);
        exit;
    }
    
    // Encolar estudio
    $manager = new CloudStorageManager();
    $result = $manager->enqueueStudy($orthancStudyId);
    
    http_response_code(200);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('[CLOUD_STORAGE_ENQUEUE] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
