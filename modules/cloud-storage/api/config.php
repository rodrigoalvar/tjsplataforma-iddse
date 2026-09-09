<?php
/**
 * API para obtener y actualizar configuración R2
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    require_once __DIR__ . '/../config/cloud_storage_config.php';
    
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    
    if ($method === 'GET') {
        // Obtener configuración
        $config = CloudStorageConfig::load();
        
        // No devolver secretos completos por seguridad
        $safeConfig = [
            'r2_enabled' => $config['r2_enabled'] ?? false,
            'r2_account_id' => $config['r2_account_id'] ?? '',
            'r2_access_key' => substr($config['r2_access_key'] ?? '', 0, 8) . '...' . substr($config['r2_access_key'] ?? '', -4),
            'r2_secret_key' => '***HIDDEN***',
            'r2_bucket_name' => $config['r2_bucket_name'] ?? '',
            'r2_region' => $config['r2_region'] ?? 'auto',
            'r2_custom_domain' => $config['r2_custom_domain'] ?? '',
            'r2_public_portal_base_url' => $config['r2_public_portal_base_url'] ?? '',
            'r2_storage_prefix' => $config['r2_storage_prefix'] ?? 'studies/',
            'r2_upload_concurrency' => $config['r2_upload_concurrency'] ?? 2,
            'r2_presigned_ttl' => $config['r2_presigned_ttl'] ?? 600,
            'r2_verify_ssl' => $config['r2_verify_ssl'] ?? true,
            'r2_upload_method' => $config['r2_upload_method'] ?? 'instance',
            'r2_zip_temp_dir' => $config['r2_zip_temp_dir'] ?? '',
            'r2_worker_url' => $config['r2_worker_url'] ?? '',
            'r2_worker_auth_token' => $config['r2_worker_auth_token'] ? '***CONFIGURADO***' : '',
            'r2_cf_api_token_read' => $config['r2_cf_api_token_read'] ? '***CONFIGURADO***' : '',
            'r2_quota_enabled' => !empty($config['r2_quota_enabled']),
            'r2_quota_limit_gb' => isset($config['r2_quota_limit_gb']) ? (float)$config['r2_quota_limit_gb'] : 0,
            'r2_recycle_enabled' => !empty($config['r2_recycle_enabled']),
            'r2_recycle_trigger_percent' => isset($config['r2_recycle_trigger_percent']) ? (float)$config['r2_recycle_trigger_percent'] : 0,
            'r2_recycle_trigger_gb' => isset($config['r2_recycle_trigger_gb']) ? (float)$config['r2_recycle_trigger_gb'] : 0,
            'r2_auto_enqueue_enabled' => !empty($config['r2_auto_enqueue_enabled']),
            'r2_auto_enqueue_modalities' => $config['r2_auto_enqueue_modalities'] ?? 'CT',
            'r2_auto_enqueue_min_instances' => isset($config['r2_auto_enqueue_min_instances']) ? (int)$config['r2_auto_enqueue_min_instances'] : 0,
            'r2_auto_enqueue_instances_source' => $config['r2_auto_enqueue_instances_source'] ?? 'orthanc',
            'r2_auto_enqueue_pacs_node_ids' => $config['r2_auto_enqueue_pacs_node_ids'] ?? '',
            'r2_auto_enqueue_webhook_url' => $config['r2_auto_enqueue_webhook_url'] ?? '',
            'r2_auto_enqueue_secret' => !empty($config['r2_auto_enqueue_secret']) ? '***CONFIGURADO***' : '',
            'r2_auto_enqueue_endpoint_hint' => '/modules/cloud-storage/api/auto-enqueue.php'
        ];
        
        echo json_encode([
            'success' => true,
            'data' => $safeConfig
        ], JSON_UNESCAPED_UNICODE);
        
    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Datos inválidos'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // Validar claves permitidas
        $allowedKeys = [
            'r2_enabled',
            'r2_account_id',
            'r2_access_key',
            'r2_secret_key',
            'r2_bucket_name',
            'r2_region',
            'r2_custom_domain',
            'r2_public_portal_base_url',
            'r2_storage_prefix',
            'r2_upload_concurrency',
            'r2_presigned_ttl',
            'r2_verify_ssl',
            'r2_upload_method',
            'r2_zip_temp_dir',
            'r2_worker_url',
            'r2_worker_auth_token',
            'r2_cf_api_token_read',
            'r2_quota_enabled',
            'r2_quota_limit_gb',
            'r2_recycle_enabled',
            'r2_recycle_trigger_percent',
            'r2_recycle_trigger_gb',
            'r2_auto_enqueue_enabled',
            'r2_auto_enqueue_modalities',
            'r2_auto_enqueue_min_instances',
            'r2_auto_enqueue_instances_source',
            'r2_auto_enqueue_pacs_node_ids',
            'r2_auto_enqueue_webhook_url',
            'r2_auto_enqueue_secret'
        ];
        
        $configToSave = [];
        foreach ($allowedKeys as $key) {
            if (isset($input[$key])) {
                $configToSave[$key] = $input[$key];
            }
        }
        
        // Guardar en .env y BD
        $result = CloudStorageConfig::saveConfig($configToSave);
        
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    }
    
} catch (Exception $e) {
    error_log('[CLOUD_STORAGE][CONFIG] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
