<?php
/**
 * Endpoint para probar la conexión a Cloudflare R2
 * GET /api/cloud-storage/test-connection
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

try {
    require_once __DIR__ . '/../config/cloud_storage_config.php';
    require_once __DIR__ . '/../drivers/R2StorageDriver.php';
    
    // Limpiar caché de configuración para asegurar que se cargue la última versión
    CloudStorageConfig::clearCache();
    
    // Cargar configuración
    $config = CloudStorageConfig::load();
    
    // Debug: Verificar qué archivos .env existen
    $moduleEnvPath = __DIR__ . '/../.env';
    $rootEnvPath = __DIR__ . '/../../.env';
    $envSource = 'ninguno';
    if (file_exists($moduleEnvPath)) {
        $envSource = 'módulo (' . $moduleEnvPath . ')';
    } elseif (file_exists($rootEnvPath)) {
        $envSource = 'raíz del proyecto (' . $rootEnvPath . ')';
    }
    
    // Verificar que R2 esté habilitado
    if (!($config['r2_enabled'] ?? false)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'R2 no está habilitado. Configura R2_ENABLED=true en el archivo .env del módulo.',
            'debug' => [
                'env_source' => $envSource,
                'r2_enabled' => $config['r2_enabled'] ?? false
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Verificar que las credenciales estén configuradas
    $required = ['r2_account_id', 'r2_access_key', 'r2_secret_key', 'r2_bucket_name'];
    $missing = [];
    $credentialsStatus = [];
    foreach ($required as $key) {
        $hasValue = !empty($config[$key]);
        $credentialsStatus[$key] = $hasValue ? 'configurado' : 'faltante';
        if (!$hasValue) {
            $missing[] = $key;
        }
    }
    
    if (!empty($missing)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Faltan credenciales: ' . implode(', ', $missing),
            'debug' => [
                'env_source' => $envSource,
                'credentials_status' => $credentialsStatus,
                'bucket_name' => $config['r2_bucket_name'] ?? 'no configurado'
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Intentar crear el driver y probar la conexión
    try {
        $driver = new R2StorageDriver($config);
        $connectionOk = $driver->testConnection();
        
        if ($connectionOk) {
            echo json_encode([
                'success' => true,
                'message' => 'Conexión exitosa a Cloudflare R2',
                'details' => [
                    'bucket' => $config['r2_bucket_name'],
                    'account_id' => $config['r2_account_id'],
                    'custom_domain' => $config['r2_custom_domain'] ?? null,
                    'region' => $config['r2_region'] ?? 'auto',
                    'env_source' => $envSource
                ]
            ], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'No se pudo establecer conexión con R2. Verifica tus credenciales y permisos del bucket.',
                'debug' => [
                    'env_source' => $envSource,
                    'bucket' => $config['r2_bucket_name'],
                    'account_id' => substr($config['r2_account_id'] ?? '', 0, 10) . '...' // Solo primeros caracteres por seguridad
                ]
            ], JSON_UNESCAPED_UNICODE);
        }
    } catch (Exception $e) {
        error_log('[CLOUD_STORAGE][TEST_CONNECTION] Error: ' . $e->getMessage());
        error_log('[CLOUD_STORAGE][TEST_CONNECTION] Stack trace: ' . $e->getTraceAsString());
        
        // Detectar errores SSL/TLS específicos
        $errorMessage = $e->getMessage();
        $isSSLError = (
            strpos($errorMessage, 'SSL') !== false ||
            strpos($errorMessage, 'TLS') !== false ||
            strpos($errorMessage, 'handshake') !== false ||
            strpos($errorMessage, 'cURL error 35') !== false
        );
        
        if ($isSSLError) {
            // Obtener información del sistema
            $opensslVersion = defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : 'Desconocida';
            $curlVersion = function_exists('curl_version') ? curl_version() : null;
            $curlSSLVersion = $curlVersion && isset($curlVersion['ssl_version']) ? $curlVersion['ssl_version'] : 'Desconocida';
            
            $errorMessage = 'Error SSL/TLS detectado. Este es un problema conocido de compatibilidad entre OpenSSL 3.x y Cloudflare R2.' . "\n\n" .
                           'Información del sistema:' . "\n" .
                           '- OpenSSL: ' . $opensslVersion . "\n" .
                           '- cURL SSL: ' . $curlSSLVersion . "\n\n" .
                           'Soluciones posibles:' . "\n" .
                           '1. Actualizar OpenSSL a una versión más reciente (recomendado)' . "\n" .
                           '2. Configurar R2_VERIFY_SSL=false en el .env del módulo (ya configurado, pero el problema persiste)' . "\n" .
                           '3. Usar un proxy HTTP o un servidor intermedio' . "\n" .
                           '4. Contactar con el soporte de Cloudflare sobre compatibilidad SSL' . "\n\n" .
                           'Error original: ' . $errorMessage;
        }
        
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $errorMessage,
            'debug' => [
                'env_source' => $envSource,
                'exception_type' => get_class($e),
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
                'is_ssl_error' => $isSSLError
            ]
        ], JSON_UNESCAPED_UNICODE);
    }
    
} catch (Exception $e) {
    error_log('[CLOUD_STORAGE][TEST_CONNECTION] Error fatal: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error fatal: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
