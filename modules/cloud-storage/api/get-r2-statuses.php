<?php
/**
 * API para obtener estados R2 y de cola de estudios sin consultar PACS
 * Usado para actualizar la lista localmente después de encolar
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    require_once __DIR__ . '/../../../config/database.php';
    
    $input = json_decode(file_get_contents('php://input'), true);
    $studyIds = $input['study_ids'] ?? [];
    
    if (empty($studyIds)) {
        throw new Exception('No se proporcionaron IDs de estudios');
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception('Error conectando a la base de datos');
    }
    
    // Crear placeholders para la consulta
    $placeholders = implode(',', array_fill(0, count($studyIds), '?'));
    
    // Obtener estados R2
    $r2Statuses = [];
    $stmt = $db->prepare("
        SELECT orthanc_study_id, r2_status, r2_manifest_path 
        FROM r2_studies
        WHERE orthanc_study_id IN ($placeholders)
    ");
    $stmt->execute($studyIds);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $r2Statuses[$row['orthanc_study_id']] = [
            'r2_status' => $row['r2_status'],
            'r2_manifest_path' => $row['r2_manifest_path']
        ];
    }
    
    // Obtener estados de cola (todos los estados, no solo activos)
    $queueStatuses = [];
    $stmt = $db->prepare("
        SELECT orthanc_study_id, status 
        FROM r2_queue
        WHERE orthanc_study_id IN ($placeholders)
        ORDER BY created_at DESC
    ");
    $stmt->execute($studyIds);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Solo guardar el estado más reciente si hay múltiples entradas
        if (!isset($queueStatuses[$row['orthanc_study_id']])) {
            $queueStatuses[$row['orthanc_study_id']] = $row['status'];
        }
    }
    
    // Combinar resultados
    $result = [];
    foreach ($studyIds as $studyId) {
        $result[$studyId] = [
            'r2_status' => $r2Statuses[$studyId]['r2_status'] ?? 'none',
            'r2_manifest_path' => $r2Statuses[$studyId]['r2_manifest_path'] ?? null,
            'queue_status' => $queueStatuses[$studyId] ?? null
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $result
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    error_log('[CLOUD_STORAGE][GET_R2_STATUSES] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
