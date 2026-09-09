<?php
/**
 * API para listar estudios almacenados en R2
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
    
    // Soportar instalaciones existentes sin los campos de bloqueo:
    // si las columnas no existen, devolvemos valores por defecto para que la UI no falle.
    $columns = [];
    $colsStmt = $db->query("SHOW COLUMNS FROM r2_studies");
    while ($col = $colsStmt->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($col['Field'])) {
            $columns[] = (string)$col['Field'];
        }
    }
    $hasIsLocked = in_array('is_locked', $columns, true);
    $hasLockReason = in_array('lock_reason', $columns, true);
    $hasLockedAt = in_array('locked_at', $columns, true);
    $hasR2SyncStatus = in_array('r2_sync_status', $columns, true);
    
    $isLockedSel = $hasIsLocked ? 'is_locked' : '0 AS is_locked';
    $lockReasonSel = $hasLockReason ? 'lock_reason' : 'NULL AS lock_reason';
    $lockedAtSel = $hasLockedAt ? 'locked_at' : 'NULL AS locked_at';
    $r2SyncSel = $hasR2SyncStatus ? 'r2_sync_status' : '\'idle\' AS r2_sync_status';
    
    $stmt = $db->query("
        SELECT 
            orthanc_study_id,
            study_instance_uid,
            r2_status,
            {$r2SyncSel},
            r2_manifest_path,
            total_instances,
            total_size_bytes,
            {$isLockedSel},
            {$lockReasonSel},
            {$lockedAtSel},
            ROUND(total_size_bytes / 1024 / 1024, 2) as size_mb,
            uploaded_at,
            created_at
        FROM r2_studies 
        WHERE r2_status = 'online'
        ORDER BY uploaded_at DESC
        LIMIT 100
    ");
    
    $studies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Estadísticas
    $lockedCountSel = $hasIsLocked ? 'SUM(is_locked) as locked_count' : '0 as locked_count';
    $statsStmt = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(total_instances) as total_instances,
            SUM(total_size_bytes) as total_size_bytes,
            {$lockedCountSel}
        FROM r2_studies
        WHERE r2_status = 'online'
    ");
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $studies,
        'stats' => [
            'total' => (int)($stats['total'] ?? 0),
            'total_instances' => (int)($stats['total_instances'] ?? 0),
            'total_size_mb' => round(($stats['total_size_bytes'] ?? 0) / 1024 / 1024, 2),
            'locked_count' => (int)($stats['locked_count'] ?? 0)
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('[CLOUD_STORAGE][R2_STUDIES] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
