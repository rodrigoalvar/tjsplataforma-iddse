<?php
/**
 * Configuración del módulo Cloud Storage
 * Sistema TJSMEDICAL - Cloud Storage Module
 * 
 * Carga configuración desde .env, BD o valores por defecto
 */

class CloudStorageConfig {
    private static $configCache = null;
    
    /**
     * Carga la configuración
     */
    public static function load() {
        if (self::$configCache !== null) {
            return self::$configCache;
        }
        
        $config = self::getDefaultConfig();
        
        // 1. Intentar cargar desde .env (prioridad: módulo > raíz del proyecto)
        $envFiles = [
            __DIR__ . '/../.env',           // .env en la carpeta del módulo (prioridad)
            __DIR__ . '/../../.env'         // .env en la raíz del proyecto (fallback)
        ];
        
        foreach ($envFiles as $envFile) {
            if (file_exists($envFile)) {
                $envConfig = self::loadFromEnv($envFile);
                $config = array_merge($config, $envConfig);
                break; // Usar el primero que encuentre
            }
        }
        
        // 2. Intentar cargar desde BD
        try {
            $dbConfigPath = __DIR__ . '/../../config/database.php';
            if (!file_exists($dbConfigPath)) {
                // Intentar ruta alternativa
                $dbConfigPath = __DIR__ . '/../../../config/database.php';
            }
            if (file_exists($dbConfigPath)) {
                require_once $dbConfigPath;
                $database = new Database();
                $db = $database->getConnection();
            } else {
                $db = null;
            }
            
            if ($db) {
                $dbConfig = self::loadFromDatabase($db);
                $config = array_merge($config, $dbConfig);
            }
        } catch (Exception $e) {
            error_log('[CLOUD_STORAGE_CONFIG] Error cargando desde BD: ' . $e->getMessage());
        }
        
        // 3. Cargar configuración de Orthanc
        $orthancConfigPath = __DIR__ . '/../../api/config/orthanc_config.php';
        if (!file_exists($orthancConfigPath)) {
            $orthancConfigPath = __DIR__ . '/../../../api/config/orthanc_config.php';
        }
        if (file_exists($orthancConfigPath)) {
            require_once $orthancConfigPath;
            if (class_exists('OrthancConfig')) {
                $config['orthanc_url'] = OrthancConfig::getServerUrl();
                $credentials = OrthancConfig::getCredentials();
                $config['orthanc_user'] = $credentials['username'];
                $config['orthanc_pass'] = $credentials['password'];
            }
        }
        
        self::$configCache = $config;
        return $config;
    }
    
    /**
     * Configuración por defecto
     */
    private static function getDefaultConfig() {
        return [
            'r2_enabled' => false,
            'r2_account_id' => '',
            'r2_access_key' => '',
            'r2_secret_key' => '',
            'r2_bucket_name' => 'dicom-studies',
            'r2_region' => 'auto',
            'r2_custom_domain' => '',
            'r2_storage_prefix' => 'studies/',
            'r2_upload_concurrency' => 10, // Worker Pool size: mantener siempre N uploads activos
            'r2_presigned_ttl' => 600,
            'r2_verify_ssl' => true, // Por defecto verificar SSL, pero se puede deshabilitar con R2_VERIFY_SSL=false
            'r2_upload_method' => 'instance', // 'instance', 'zip', 'pre-download', 'zip-extract-upload', o 'rclone'
            'r2_zip_temp_dir' => __DIR__ . '/../temp/zips', // Carpeta temporal para ZIPs
            'r2_worker_url' => '', // URL del Worker de Cloudflare para extracción
            'r2_worker_auth_token' => '', // Token de autenticación para el Worker
            'r2_cf_api_token_read' => '', // Token API Cloudflare con permiso de lectura de uso R2
            // Quote / cuota (GB) y reciclado automático
            'r2_quota_enabled' => false,
            'r2_quota_limit_gb' => 0,
            'r2_recycle_enabled' => false,
            // Cuando el uso supere este porcentaje (0 = deshabilitado) se considera que "empieza a reciclar"
            'r2_recycle_trigger_percent' => 90,
            // Alternativa en GB (0 = deshabilitado)
            'r2_recycle_trigger_gb' => 0,
            // Encolado automático (Orthanc OnStableStudy → webhook PHP → r2_queue)
            'r2_auto_enqueue_enabled' => false,
            'r2_auto_enqueue_modalities' => 'CT', // Lista separada por comas (ej. CT,MR)
            'r2_auto_enqueue_min_instances' => 0, // Umbral de instancias (>=). 0 = deshabilitado
            'r2_auto_enqueue_instances_source' => 'orthanc', // orthanc|pacs_nodes
            'r2_auto_enqueue_pacs_node_ids' => '', // IDs de pacs_nodes separados por coma (si source=pacs_nodes)
            'r2_auto_enqueue_webhook_url' => '', // URL completa del endpoint auto-enqueue (desde Orthanc)
            'r2_auto_enqueue_secret' => '', // Token compartido (Authorization: Bearer)
            // URL pública del portal para manifest.php (Study routing). Vacío = inferir del request HTTP.
            'r2_public_portal_base_url' => ''
        ];
    }
    
    /**
     * Carga configuración desde archivo .env
     */
    private static function loadFromEnv($envFile) {
        $config = [];
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue; // Comentario
            }
            
            if (strpos($line, '=') === false) {
                continue;
            }
            
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            // Para valores que pueden tener espacios o caracteres especiales, no usar trim completo
            // Solo eliminar espacios al inicio y fin, pero preservar el contenido
            $value = trim($value);
            // Si el valor está entre comillas, eliminarlas
            if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') || 
                (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                $value = substr($value, 1, -1);
            }
            
            // Mapear claves de .env a claves de config
            $keyMap = [
                'R2_ENABLED' => 'r2_enabled',
                'R2_ACCOUNT_ID' => 'r2_account_id',
                'R2_ACCESS_KEY' => 'r2_access_key',
                'R2_SECRET_KEY' => 'r2_secret_key',
                'R2_BUCKET_NAME' => 'r2_bucket_name',
                'R2_REGION' => 'r2_region',
                'R2_CUSTOM_DOMAIN' => 'r2_custom_domain',
                'R2_STORAGE_PREFIX' => 'r2_storage_prefix',
                'R2_UPLOAD_CONCURRENCY' => 'r2_upload_concurrency',
                'R2_PRESIGNED_TTL' => 'r2_presigned_ttl',
                'R2_VERIFY_SSL' => 'r2_verify_ssl',
                'R2_UPLOAD_METHOD' => 'r2_upload_method',
                'R2_ZIP_TEMP_DIR' => 'r2_zip_temp_dir',
                'R2_WORKER_URL' => 'r2_worker_url',
                'R2_WORKER_AUTH_TOKEN' => 'r2_worker_auth_token',
                'R2_CF_API_TOKEN_READ' => 'r2_cf_api_token_read',
                'R2_QUOTA_ENABLED' => 'r2_quota_enabled',
                'R2_QUOTA_LIMIT_GB' => 'r2_quota_limit_gb',
                'R2_RECYCLE_ENABLED' => 'r2_recycle_enabled',
                'R2_RECYCLE_TRIGGER_PERCENT' => 'r2_recycle_trigger_percent',
                'R2_RECYCLE_TRIGGER_GB' => 'r2_recycle_trigger_gb',
                'R2_AUTO_ENQUEUE_ENABLED' => 'r2_auto_enqueue_enabled',
                'R2_AUTO_ENQUEUE_MODALITIES' => 'r2_auto_enqueue_modalities',
                'R2_AUTO_ENQUEUE_MIN_INSTANCES' => 'r2_auto_enqueue_min_instances',
                'R2_AUTO_ENQUEUE_INSTANCES_SOURCE' => 'r2_auto_enqueue_instances_source',
                'R2_AUTO_ENQUEUE_PACS_NODE_IDS' => 'r2_auto_enqueue_pacs_node_ids',
                'R2_AUTO_ENQUEUE_WEBHOOK_URL' => 'r2_auto_enqueue_webhook_url',
                'R2_AUTO_ENQUEUE_SECRET' => 'r2_auto_enqueue_secret',
                'R2_PUBLIC_PORTAL_BASE_URL' => 'r2_public_portal_base_url'
            ];
            
            if (isset($keyMap[$key])) {
                $configKey = $keyMap[$key];
                
                // Convertir booleanos y enteros
                $boolKeys = ['r2_enabled', 'r2_verify_ssl', 'r2_auto_enqueue_enabled', 'r2_quota_enabled', 'r2_recycle_enabled'];
                $intKeys = ['r2_upload_concurrency', 'r2_presigned_ttl', 'r2_auto_enqueue_min_instances'];
                $floatKeys = ['r2_quota_limit_gb', 'r2_recycle_trigger_percent', 'r2_recycle_trigger_gb'];
                
                if (in_array($configKey, $boolKeys, true)) {
                    $config[$configKey] = in_array(strtolower($value), ['true', '1', 'yes', 'on']);
                } elseif (in_array($configKey, $intKeys, true)) {
                    $config[$configKey] = (int)$value;
                } elseif (in_array($configKey, $floatKeys, true)) {
                    $config[$configKey] = (float)$value;
                } else {
                    $config[$configKey] = $value;
                }
            }
        }
        
        return $config;
    }
    
    /**
     * Carga configuración desde base de datos
     */
    private static function loadFromDatabase($db) {
        $config = [];
        
        try {
            // Verificar si existe la tabla
            $stmt = $db->query("SHOW TABLES LIKE 'configuracion'");
            if ($stmt->rowCount() === 0) {
                return $config;
            }
            
            $keys = [
                'r2_enabled' => 'r2_enabled',
                'r2_account_id' => 'r2_account_id',
                'r2_access_key' => 'r2_access_key',
                'r2_secret_key' => 'r2_secret_key',
                'r2_bucket_name' => 'r2_bucket_name',
                'r2_region' => 'r2_region',
                'r2_custom_domain' => 'r2_custom_domain',
                'r2_storage_prefix' => 'r2_storage_prefix',
                'r2_upload_concurrency' => 'r2_upload_concurrency',
                'r2_presigned_ttl' => 'r2_presigned_ttl',
                'r2_verify_ssl' => 'r2_verify_ssl',
                'r2_upload_method' => 'r2_upload_method',
                'r2_zip_temp_dir' => 'r2_zip_temp_dir',
                'r2_worker_url' => 'r2_worker_url',
                'r2_worker_auth_token' => 'r2_worker_auth_token',
                'r2_cf_api_token_read' => 'r2_cf_api_token_read',
                'r2_quota_enabled' => 'r2_quota_enabled',
                'r2_quota_limit_gb' => 'r2_quota_limit_gb',
                'r2_recycle_enabled' => 'r2_recycle_enabled',
                'r2_recycle_trigger_percent' => 'r2_recycle_trigger_percent',
                'r2_recycle_trigger_gb' => 'r2_recycle_trigger_gb',
                'r2_auto_enqueue_enabled' => 'r2_auto_enqueue_enabled',
                'r2_auto_enqueue_modalities' => 'r2_auto_enqueue_modalities',
                'r2_auto_enqueue_min_instances' => 'r2_auto_enqueue_min_instances',
                'r2_auto_enqueue_instances_source' => 'r2_auto_enqueue_instances_source',
                'r2_auto_enqueue_pacs_node_ids' => 'r2_auto_enqueue_pacs_node_ids',
                'r2_auto_enqueue_webhook_url' => 'r2_auto_enqueue_webhook_url',
                'r2_auto_enqueue_secret' => 'r2_auto_enqueue_secret',
                'r2_public_portal_base_url' => 'r2_public_portal_base_url'
            ];
            
            foreach ($keys as $configKey => $dbKey) {
                $stmt = $db->prepare("SELECT valor FROM configuracion WHERE clave = ?");
                $stmt->execute([$dbKey]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result) {
                    $value = $result['valor'];
                    
                    $boolKeys = ['r2_enabled', 'r2_verify_ssl', 'r2_auto_enqueue_enabled', 'r2_quota_enabled', 'r2_recycle_enabled'];
                    $intKeys = ['r2_upload_concurrency', 'r2_presigned_ttl', 'r2_auto_enqueue_min_instances'];
                    $floatKeys = ['r2_quota_limit_gb', 'r2_recycle_trigger_percent', 'r2_recycle_trigger_gb'];
                    
                    if (in_array($configKey, $boolKeys, true)) {
                        $config[$configKey] = in_array(strtolower($value), ['true', '1', 'yes', 'on']);
                    } elseif (in_array($configKey, $intKeys, true)) {
                        $config[$configKey] = (int)$value;
                    } elseif (in_array($configKey, $floatKeys, true)) {
                        $config[$configKey] = (float)$value;
                    } else {
                        // Para custom_domain, si está vacío o es solo un comentario, dejarlo vacío
                        if ($configKey === 'r2_custom_domain' && (empty(trim($value)) || strpos($value, '#') === 0)) {
                            $config[$configKey] = '';
                        } else {
                            $config[$configKey] = $value;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log('[CLOUD_STORAGE_CONFIG] Error leyendo BD: ' . $e->getMessage());
        }
        
        return $config;
    }
    
    /**
     * Limpia el cache de configuración
     */
    public static function clearCache() {
        self::$configCache = null;
    }
    
    /**
     * Guarda configuración en .env y BD
     * @param array $config Configuración a guardar
     * @return array ['success' => bool, 'env_saved' => bool, 'db_saved' => bool, 'errors' => array]
     */
    public static function saveConfig($config) {
        $result = [
            'success' => true,
            'env_saved' => false,
            'db_saved' => false,
            'errors' => []
        ];
        
        // 1. Guardar en .env
        try {
            $envFile = __DIR__ . '/../.env';
            $envSaved = self::saveToEnv($envFile, $config);
            $result['env_saved'] = $envSaved;
        } catch (Exception $e) {
            $result['errors'][] = 'Error guardando en .env: ' . $e->getMessage();
            $result['success'] = false;
        }
        
        // 2. Guardar en BD
        try {
            $dbConfigPath = __DIR__ . '/../../config/database.php';
            if (!file_exists($dbConfigPath)) {
                $dbConfigPath = __DIR__ . '/../../../config/database.php';
            }
            
            if (file_exists($dbConfigPath)) {
                require_once $dbConfigPath;
                $database = new Database();
                $db = $database->getConnection();
                
                if ($db) {
                    $dbSaved = self::saveToDatabase($db, $config);
                    $result['db_saved'] = $dbSaved;
                }
            }
        } catch (Exception $e) {
            $result['errors'][] = 'Error guardando en BD: ' . $e->getMessage();
            $result['success'] = false;
        }
        
        // 3. Limpiar cache
        self::clearCache();
        
        return $result;
    }
    
    /**
     * Guarda configuración en archivo .env
     */
    private static function saveToEnv($envFile, $config) {
        // Normalizar ruta del archivo .env
        if (file_exists($envFile)) {
            $envFile = realpath($envFile);
        } else {
            // Si no existe, usar la ruta tal cual (se creará)
            $envFile = realpath(dirname($envFile)) . '/' . basename($envFile);
        }
        
        // Mapeo de claves de config a claves de .env
        $envKeyMap = [
            'r2_enabled' => 'R2_ENABLED',
            'r2_account_id' => 'R2_ACCOUNT_ID',
            'r2_access_key' => 'R2_ACCESS_KEY',
            'r2_secret_key' => 'R2_SECRET_KEY',
            'r2_bucket_name' => 'R2_BUCKET_NAME',
            'r2_region' => 'R2_REGION',
            'r2_custom_domain' => 'R2_CUSTOM_DOMAIN',
            'r2_storage_prefix' => 'R2_STORAGE_PREFIX',
            'r2_upload_concurrency' => 'R2_UPLOAD_CONCURRENCY',
            'r2_presigned_ttl' => 'R2_PRESIGNED_TTL',
            'r2_verify_ssl' => 'R2_VERIFY_SSL',
            'r2_upload_method' => 'R2_UPLOAD_METHOD',
            'r2_zip_temp_dir' => 'R2_ZIP_TEMP_DIR',
            'r2_worker_url' => 'R2_WORKER_URL',
            'r2_worker_auth_token' => 'R2_WORKER_AUTH_TOKEN',
            'r2_cf_api_token_read' => 'R2_CF_API_TOKEN_READ',
            'r2_quota_enabled' => 'R2_QUOTA_ENABLED',
            'r2_quota_limit_gb' => 'R2_QUOTA_LIMIT_GB',
            'r2_recycle_enabled' => 'R2_RECYCLE_ENABLED',
            'r2_recycle_trigger_percent' => 'R2_RECYCLE_TRIGGER_PERCENT',
            'r2_recycle_trigger_gb' => 'R2_RECYCLE_TRIGGER_GB',
            'r2_auto_enqueue_enabled' => 'R2_AUTO_ENQUEUE_ENABLED',
            'r2_auto_enqueue_modalities' => 'R2_AUTO_ENQUEUE_MODALITIES',
            'r2_auto_enqueue_min_instances' => 'R2_AUTO_ENQUEUE_MIN_INSTANCES',
            'r2_auto_enqueue_instances_source' => 'R2_AUTO_ENQUEUE_INSTANCES_SOURCE',
            'r2_auto_enqueue_pacs_node_ids' => 'R2_AUTO_ENQUEUE_PACS_NODE_IDS',
            'r2_auto_enqueue_webhook_url' => 'R2_AUTO_ENQUEUE_WEBHOOK_URL',
            'r2_auto_enqueue_secret' => 'R2_AUTO_ENQUEUE_SECRET',
            'r2_public_portal_base_url' => 'R2_PUBLIC_PORTAL_BASE_URL'
        ];
        
        // Leer archivo .env existente
        $lines = [];
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        }
        
        // Actualizar o agregar valores SOLO para campos que se envían y NO están vacíos
        // (excepto para booleanos y números que pueden ser 0/false)
        foreach ($envKeyMap as $configKey => $envKey) {
            if (!isset($config[$configKey])) {
                continue; // No se envió este campo, preservar valor existente
            }
            
            $value = $config[$configKey];
            
            // Para campos sensibles (secret_key, access_key), si viene vacío o tiene "...", NO actualizar
            // (preservar el valor existente)
            if (in_array($configKey, ['r2_secret_key', 'r2_access_key', 'r2_cf_api_token_read', 'r2_auto_enqueue_secret'])) {
                $valueStr = trim((string)$value);
                if (empty($valueStr) || $valueStr === '' || strpos($valueStr, '...') !== false) {
                    continue; // No actualizar si está vacío o tiene "..." (valor truncado), preservar valor existente
                }
            }
            
            $allowEmptyString = ['r2_auto_enqueue_modalities', 'r2_auto_enqueue_webhook_url', 'r2_auto_enqueue_pacs_node_ids'];
            // Para otros campos string, si están vacíos, preservar valor existente
            // Solo actualizar si tiene valor o es booleano/número (o claves que permiten vacío)
            if (!is_bool($value) && !is_numeric($value) && empty(trim((string)$value)) && !in_array($configKey, $allowEmptyString, true)) {
                continue;
            }
            
            // Convertir booleanos
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } else {
                $value = (string)$value;
            }
            
            $found = false;
            foreach ($lines as $i => $line) {
                // Saltar comentarios
                if (strpos(trim($line), '#') === 0) {
                    continue;
                }
                
                // Buscar línea con esta clave (puede tener espacios alrededor del =)
                if (preg_match('/^' . preg_quote($envKey, '/') . '\s*=/', $line)) {
                    $lines[$i] = $envKey . '=' . $value;
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                $lines[] = $envKey . '=' . $value;
            }
        }
        
        // Escribir archivo .env
        $content = implode("\n", $lines) . "\n";
        
        // Verificar permisos de escritura
        if (!is_writable($envFile) && file_exists($envFile)) {
            throw new Exception("Archivo .env no tiene permisos de escritura: $envFile");
        }
        
        // Si el archivo no existe, verificar que el directorio sea escribible
        if (!file_exists($envFile)) {
            $dir = dirname($envFile);
            if (!is_writable($dir)) {
                throw new Exception("Directorio no tiene permisos de escritura: $dir");
            }
        }
        
        if (file_put_contents($envFile, $content) === false) {
            $error = error_get_last();
            $errorMsg = $error ? $error['message'] : 'Error desconocido';
            throw new Exception("No se pudo escribir archivo .env: $envFile - $errorMsg");
        }
        
        return true;
    }
    
    /**
     * Guarda configuración en base de datos
     */
    private static function saveToDatabase($db, $config) {
        try {
            // Verificar si existe la tabla
            $stmt = $db->query("SHOW TABLES LIKE 'configuracion'");
            if ($stmt->rowCount() === 0) {
                throw new Exception("Tabla 'configuracion' no existe");
            }
            
            // Mapeo de claves
            $dbKeyMap = [
                'r2_enabled' => 'r2_enabled',
                'r2_account_id' => 'r2_account_id',
                'r2_access_key' => 'r2_access_key',
                'r2_secret_key' => 'r2_secret_key',
                'r2_bucket_name' => 'r2_bucket_name',
                'r2_region' => 'r2_region',
                'r2_custom_domain' => 'r2_custom_domain',
                'r2_storage_prefix' => 'r2_storage_prefix',
                'r2_upload_concurrency' => 'r2_upload_concurrency',
                'r2_presigned_ttl' => 'r2_presigned_ttl',
                'r2_verify_ssl' => 'r2_verify_ssl',
                'r2_upload_method' => 'r2_upload_method',
                'r2_zip_temp_dir' => 'r2_zip_temp_dir',
                'r2_worker_url' => 'r2_worker_url',
                'r2_worker_auth_token' => 'r2_worker_auth_token',
                'r2_cf_api_token_read' => 'r2_cf_api_token_read',
                'r2_quota_enabled' => 'r2_quota_enabled',
                'r2_quota_limit_gb' => 'r2_quota_limit_gb',
                'r2_recycle_enabled' => 'r2_recycle_enabled',
                'r2_recycle_trigger_percent' => 'r2_recycle_trigger_percent',
                'r2_recycle_trigger_gb' => 'r2_recycle_trigger_gb',
                'r2_auto_enqueue_enabled' => 'r2_auto_enqueue_enabled',
                'r2_auto_enqueue_modalities' => 'r2_auto_enqueue_modalities',
                'r2_auto_enqueue_min_instances' => 'r2_auto_enqueue_min_instances',
                'r2_auto_enqueue_instances_source' => 'r2_auto_enqueue_instances_source',
                'r2_auto_enqueue_pacs_node_ids' => 'r2_auto_enqueue_pacs_node_ids',
                'r2_auto_enqueue_webhook_url' => 'r2_auto_enqueue_webhook_url',
                'r2_auto_enqueue_secret' => 'r2_auto_enqueue_secret',
                'r2_public_portal_base_url' => 'r2_public_portal_base_url'
            ];
            
            // Verificar qué columnas tiene la tabla
            $hasUpdatedAt = false;
            $hasFechaModificacion = false;
            try {
                $stmt = $db->query("SHOW COLUMNS FROM configuracion");
                $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $hasUpdatedAt = in_array('updated_at', $columns);
                $hasFechaModificacion = in_array('fecha_modificacion', $columns);
            } catch (Exception $e) {
                // Si hay error, asumir que no existe
                $hasUpdatedAt = false;
                $hasFechaModificacion = false;
            }
            
            $db->beginTransaction();
            
            foreach ($dbKeyMap as $configKey => $dbKey) {
                if (!isset($config[$configKey])) {
                    continue; // No se envió este campo, preservar valor existente
                }
                
                $value = $config[$configKey];
                
                // Para campos sensibles (secret_key, access_key), si viene vacío, NO actualizar
                if (in_array($configKey, ['r2_secret_key', 'r2_access_key', 'r2_cf_api_token_read', 'r2_auto_enqueue_secret'], true)) {
                    if (empty($value) || trim((string)$value) === '') {
                        continue; // No actualizar si está vacío, preservar valor existente
                    }
                }
                
                $allowEmptyString = ['r2_auto_enqueue_modalities', 'r2_auto_enqueue_webhook_url', 'r2_auto_enqueue_pacs_node_ids', 'r2_public_portal_base_url'];
                // Para otros campos string, si están vacíos, preservar valor existente
                if (!is_bool($value) && !is_numeric($value) && empty(trim((string)$value)) && !in_array($configKey, $allowEmptyString, true)) {
                    continue; // Preservar valor existente
                }
                
                // Convertir booleanos
                if (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                } else {
                    $value = (string)$value;
                }
                
                // INSERT ... ON DUPLICATE KEY UPDATE
                if ($hasUpdatedAt) {
                    $stmt = $db->prepare("
                        INSERT INTO configuracion (clave, valor, updated_at) 
                        VALUES (?, ?, NOW())
                        ON DUPLICATE KEY UPDATE valor = ?, updated_at = NOW()
                    ");
                    $stmt->execute([$dbKey, $value, $value]);
                } elseif ($hasFechaModificacion) {
                    $stmt = $db->prepare("
                        INSERT INTO configuracion (clave, valor, fecha_modificacion) 
                        VALUES (?, ?, NOW())
                        ON DUPLICATE KEY UPDATE valor = ?, fecha_modificacion = NOW()
                    ");
                    $stmt->execute([$dbKey, $value, $value]);
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO configuracion (clave, valor) 
                        VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE valor = ?
                    ");
                    $stmt->execute([$dbKey, $value, $value]);
                }
            }
            
            $db->commit();
            return true;
            
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
