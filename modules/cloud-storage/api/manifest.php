<?php
/**
 * Endpoint para obtener manifest.json con URLs presignadas
 * GET /api/cloud-storage/manifest/{orthancStudyId}
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
    require_once __DIR__ . '/../config/cloud_storage_config.php';
    
    // Obtener orthanc_study_id de la URL o parámetro
    $orthancStudyId = $_GET['id'] ?? $_GET['orthanc_study_id'] ?? null;
    
    // Si viene en la URL como /manifest/{id}
    if (!$orthancStudyId) {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $parts = explode('/', trim($path, '/'));
        $manifestIndex = array_search('manifest', $parts);
        if ($manifestIndex !== false && isset($parts[$manifestIndex + 1])) {
            $orthancStudyId = $parts[$manifestIndex + 1];
        }
    }
    
    if (!$orthancStudyId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'orthanc_study_id es requerido']);
        exit;
    }
    
    // TODO: Validar sesión/permisos aquí
    // Por ahora permitimos acceso directo para testing
    
    // Obtener manager y driver
    $manager = new CloudStorageManager();
    $driver = $manager->getDriver();
    
    // Obtener info del estudio en R2
    // database.php ya está incluido arriba
    $database = new Database();
    $db = $database->getConnection();
    
    $stmt = $db->prepare("
        SELECT study_instance_uid, r2_manifest_path 
        FROM r2_studies 
        WHERE orthanc_study_id = ? AND r2_status = 'online'
    ");
    $stmt->execute([$orthancStudyId]);
    $r2Study = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$r2Study) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Estudio no disponible en R2']);
        exit;
    }
    
    // Descargar manifest desde R2
    $manifestJson = $driver->getObject($r2Study['r2_manifest_path']);
    $manifest = json_decode($manifestJson, true);
    
    if (!$manifest) {
        throw new Exception('Error parseando manifest.json');
    }
    
    // Obtener configuración para TTL
    $config = CloudStorageConfig::load();
    $ttl = $config['r2_presigned_ttl'] ?? 600;
    
    // Reescribir paths a presigned URLs
    foreach ($manifest['series'] as &$series) {
        foreach ($series['instances'] as &$instance) {
            if (isset($instance['path'])) {
                $presignedUrl = $driver->generatePresignedUrl($instance['path'], $ttl);
                $instance['url'] = $presignedUrl;
                unset($instance['path']); // Eliminar path, dejar solo url
            }
        }
    }
    
    http_response_code(200);
    echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    error_log('[CLOUD_STORAGE_MANIFEST] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
