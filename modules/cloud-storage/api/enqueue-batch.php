<?php
/**
 * API para encolar múltiples estudios a la vez
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

try {
    require_once __DIR__ . '/../../../config/database.php';
    require_once __DIR__ . '/../CloudStorageManager.php';
    
    $input = json_decode(file_get_contents('php://input'), true);
    $studyIds = $input['study_ids'] ?? [];
    
    if (empty($studyIds) || !is_array($studyIds)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'study_ids es requerido y debe ser un array']);
        exit;
    }
    
    $manager = new CloudStorageManager();
    $results = [];
    $success = 0;
    $errors = 0;
    
    foreach ($studyIds as $studyId) {
        try {
            $result = $manager->enqueueStudy($studyId);
            $results[] = [
                'study_id' => $studyId,
                'success' => $result['success'],
                'message' => $result['message'] ?? 'OK',
                'queue_id' => $result['queue_id'] ?? null
            ];
            if ($result['success']) {
                $success++;
            } else {
                $errors++;
            }
        } catch (Exception $e) {
            $results[] = [
                'study_id' => $studyId,
                'success' => false,
                'error' => $e->getMessage()
            ];
            $errors++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'total' => count($studyIds),
        'success_count' => $success,
        'error_count' => $errors,
        'results' => $results
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('[CLOUD_STORAGE][ENQUEUE_BATCH] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
