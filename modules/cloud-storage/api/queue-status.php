<?php
/**
 * API para obtener estado de la cola de subida
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    require_once __DIR__ . '/../../../config/database.php';
    
    $database = new Database();
    $db = $database->getConnection();

    $hasIsResync = false;
    try {
        $colStmt = $db->query("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'r2_queue' AND COLUMN_NAME = 'is_resync'
        ");
        $hasIsResync = ((int) $colStmt->fetchColumn()) > 0;
    } catch (Exception $e) {
        $hasIsResync = false;
    }
    $isResyncField = $hasIsResync ? 'is_resync' : '0 AS is_resync';
    
    $stmt = $db->query("
        SELECT 
            id,
            orthanc_study_id,
            study_instance_uid,
            status,
            retry_count,
            last_error,
            total_instances,
            instances_uploaded,
            total_bytes,
            bytes_uploaded,
            upload_started_at,
            upload_finished_at,
            upload_duration_seconds,
            upload_speed_mbps,
            upload_speed_min_mbps,
            upload_speed_max_mbps,
            upload_speed_avg_mbps,
            upload_ip,
            upload_ip_wan,
            upload_method,
            {$isResyncField},
            zip_path,
            patient_name,
            patient_id,
            study_date,
            modality,
            study_description,
            created_at,
            updated_at
        FROM r2_queue 
        ORDER BY created_at DESC 
        LIMIT 100
    ");
    
    $queue = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Estadísticas
    $statsStmt = $db->query("
        SELECT 
            status,
            COUNT(*) as count
        FROM r2_queue
        GROUP BY status
    ");
    $stats = [];
    while ($row = $statsStmt->fetch(PDO::FETCH_ASSOC)) {
        $stats[$row['status']] = (int)$row['count'];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $queue,
        'stats' => $stats
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('[CLOUD_STORAGE][QUEUE_STATUS] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
