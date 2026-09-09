<?php
/**
 * Gestor de Configuración del Módulo de WhatsApp
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * @package WhatsAppModule
 * @version 1.0
 */

if (!class_exists('WhatsAppConfig')) {
    class WhatsAppConfig {
        private static $configFile = __DIR__ . '/config/whatsapp_config.php';
        private $config;
        
        /**
         * Carga la configuración desde el archivo
         * @return WhatsAppConfig Instancia con la configuración cargada
         */
        public static function load() {
            $instance = new self();
            
            if (file_exists(self::$configFile)) {
                $instance->config = require self::$configFile;
            } else {
                // Configuración por defecto
                $instance->config = [
                    'waha' => [
                        'base_url' => 'http://localhost:3000',
                        'api_key' => '',
                        'timeout' => 30,
                        'default_session' => 'default',
                        'default_country_code' => '54'
                    ],
                    'options' => [
                        'log_errors' => true,
                        'log_file' => __DIR__ . '/logs/whatsapp.log'
                    ]
                ];
            }
            
            return $instance;
        }
        
        /**
         * Guarda la configuración en el archivo
         * @param array $config Configuración a guardar
         * @return bool True si se guardó correctamente
         * @throws Exception Si hay error al guardar
         */
        public static function save($config) {
            $configDir = dirname(self::$configFile);
            
            // Crear directorio si no existe
            if (!is_dir($configDir)) {
                if (!mkdir($configDir, 0775, true)) {
                    throw new Exception('No se puede crear el directorio de configuración');
                }
            }
            
            // Asegurar permisos del directorio
            if (is_dir($configDir) && !is_writable($configDir)) {
                @chmod($configDir, 0775);
            }
            
            // Generar contenido del archivo
            $configContent = "<?php\n/**\n * Configuración del Módulo de WhatsApp\n * Generado automáticamente por el administrador\n * \n * @package WhatsAppModule\n */\n\nreturn " . var_export($config, true) . ";\n";
            
            // Escribir archivo
            $result = @file_put_contents(self::$configFile, $configContent);
            if ($result === false) {
                $error = error_get_last();
                $errorMsg = $error ? $error['message'] : 'Error desconocido';
                error_log('Error escribiendo archivo de configuración: ' . $errorMsg);
                error_log('Archivo: ' . self::$configFile);
                error_log('Directorio escribible: ' . (is_writable($configDir) ? 'sí' : 'no'));
                error_log('Archivo existe: ' . (file_exists(self::$configFile) ? 'sí' : 'no'));
                if (file_exists(self::$configFile)) {
                    error_log('Archivo escribible: ' . (is_writable(self::$configFile) ? 'sí' : 'no'));
                    error_log('Permisos del archivo: ' . substr(sprintf('%o', fileperms(self::$configFile)), -4));
                }
                throw new Exception('Error al escribir el archivo de configuración: ' . $errorMsg);
            }
            
            // Asegurar permisos del archivo
            @chmod(self::$configFile, 0664);
            
            return true;
        }
        
        /**
         * Obtiene la configuración de WAHA
         * @return array Configuración de WAHA
         */
        public function getWahaConfig() {
            return $this->config['waha'] ?? [];
        }
        
        /**
         * Obtiene las opciones de configuración
         * @return array Opciones
         */
        public function getOptions() {
            return $this->config['options'] ?? [];
        }
        
        /**
         * Obtiene toda la configuración
         * @return array Configuración completa
         */
        public function getConfig() {
            return $this->config;
        }
        
        /**
         * Obtiene un valor específico de la configuración
         * @param string $key Clave (puede ser anidada con punto, ej: 'waha.base_url')
         * @param mixed $default Valor por defecto
         * @return mixed Valor de la configuración
         */
        public function get($key, $default = null) {
            $keys = explode('.', $key);
            $value = $this->config;
            
            foreach ($keys as $k) {
                if (isset($value[$k])) {
                    $value = $value[$k];
                } else {
                    return $default;
                }
            }
            
            return $value;
        }
        
        /**
         * Carga la configuración de WAHA para un usuario específico desde la base de datos
         * @param int $userId ID del usuario
         * @return array|null Configuración de WAHA o null si no existe
         */
        public static function loadForUser($userId) {
            try {
                // Intentar cargar database.php desde diferentes ubicaciones
                $dbPaths = [
                    __DIR__ . '/../../config/database.php',
                    __DIR__ . '/../../../config/database.php'
                ];
                
                $dbLoaded = false;
                foreach ($dbPaths as $dbPath) {
                    if (file_exists($dbPath)) {
                        require_once $dbPath;
                        $dbLoaded = true;
                        break;
                    }
                }
                
                if (!$dbLoaded) {
                    error_log('No se pudo cargar database.php para WhatsAppConfig::loadForUser');
                    return null;
                }
                
                $pdo = getDBConnection();
                if (!$pdo) {
                    error_log('No se pudo obtener conexión a la base de datos en WhatsAppConfig::loadForUser');
                    return null;
                }
                
                // Verificar si la tabla existe
                $tableCheck = $pdo->query("SHOW TABLES LIKE 'user_whatsapp_config'");
                if (!$tableCheck || $tableCheck->rowCount() === 0) {
                    // La tabla no existe, retornar null
                    return null;
                }
                
                // Solo usuarios con permiso administracion_whatsapp pueden usar configuración personal
                // Verificar permisos del usuario antes de cargar su configuración
                $userQuery = "SELECT u.permisos, u.nivel FROM usuarios u WHERE u.id = ? AND u.activo = 1";
                $userStmt = $pdo->prepare($userQuery);
                $userStmt->execute([$userId]);
                $user = $userStmt->fetch(PDO::FETCH_ASSOC);
                
                $canUsePersonalConfig = false;
                if ($user) {
                    // ROOT puede usar configuración personal
                    if (isset($user['nivel']) && $user['nivel'] === 'root') {
                        $canUsePersonalConfig = true;
                    } else {
                        // Verificar permiso administracion_whatsapp
                        $permissions = json_decode($user['permisos'] ?? '[]', true) ?: [];
                        $canUsePersonalConfig = in_array('administracion_whatsapp', $permissions) 
                                             || in_array('all', $permissions);
                    }
                }
                
                // Solo cargar configuración personal si el usuario tiene el permiso
                if (!$canUsePersonalConfig) {
                    return null; // No tiene permiso, retornar null para usar global
                }
                
                $query = "SELECT waha_base_url, waha_api_key, waha_timeout, 
                                 waha_default_session, waha_default_country_code
                          FROM user_whatsapp_config 
                          WHERE usuario_id = ? AND activo = 1";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$userId]);
                $config = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($config) {
                    return [
                        'base_url' => $config['waha_base_url'],
                        'api_key' => $config['waha_api_key'] ?? '',
                        'timeout' => $config['waha_timeout'] ?? 30,
                        'default_session' => $config['waha_default_session'] ?? 'default',
                        'session_name' => $config['waha_default_session'] ?? 'default',
                        'default_country_code' => $config['waha_default_country_code'] ?? '54'
                    ];
                }
                
                return null;
            } catch (Exception $e) {
                error_log('Error cargando configuración WAHA por usuario: ' . $e->getMessage());
                return null;
            }
        }
        
        /**
         * Guarda la configuración de WAHA para un usuario específico en la base de datos
         * @param int $userId ID del usuario
         * @param array $config Configuración de WAHA
         * @return bool True si se guardó correctamente
         */
        public static function saveForUser($userId, $config) {
            try {
                // Intentar cargar database.php desde diferentes ubicaciones
                $dbPaths = [
                    __DIR__ . '/../../config/database.php',
                    __DIR__ . '/../../../config/database.php'
                ];
                
                $dbLoaded = false;
                foreach ($dbPaths as $dbPath) {
                    if (file_exists($dbPath)) {
                        require_once $dbPath;
                        $dbLoaded = true;
                        break;
                    }
                }
                
                if (!$dbLoaded) {
                    error_log('No se pudo cargar database.php para WhatsAppConfig::saveForUser');
                    return false;
                }
                
                $pdo = getDBConnection();
                if (!$pdo) {
                    error_log('No se pudo obtener conexión a la base de datos en WhatsAppConfig::saveForUser');
                    return false;
                }
                
                // Verificar si la tabla existe, si no, crearla
                $tableCheck = $pdo->query("SHOW TABLES LIKE 'user_whatsapp_config'");
                $tableExists = $tableCheck && $tableCheck->rowCount() > 0;
                
                if (!$tableExists) {
                    // Crear la tabla
                    $createTableSQL = "CREATE TABLE IF NOT EXISTS `user_whatsapp_config` (
                      `id` int NOT NULL AUTO_INCREMENT,
                      `usuario_id` int NOT NULL,
                      `waha_base_url` varchar(255) NOT NULL,
                      `waha_api_key` varchar(255) DEFAULT NULL,
                      `waha_timeout` int DEFAULT 30,
                      `waha_default_session` varchar(100) DEFAULT 'default',
                      `waha_default_country_code` varchar(5) DEFAULT '54',
                      `activo` tinyint(1) DEFAULT '1',
                      `fecha_creacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                      `fecha_actualizacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                      PRIMARY KEY (`id`),
                      UNIQUE KEY `usuario_id` (`usuario_id`),
                      KEY `idx_usuario_activo` (`usuario_id`, `activo`),
                      CONSTRAINT `fk_user_whatsapp_config_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                    
                    try {
                        $pdo->exec($createTableSQL);
                    } catch (PDOException $e) {
                        error_log('Error creando tabla user_whatsapp_config: ' . $e->getMessage());
                        // Continuar de todas formas, puede que ya exista
                    }
                }
                
                // Verificar si ya existe configuración para este usuario
                $checkQuery = "SELECT id FROM user_whatsapp_config WHERE usuario_id = ?";
                $checkStmt = $pdo->prepare($checkQuery);
                $checkStmt->execute([$userId]);
                $exists = $checkStmt->fetch();
                
                if ($exists) {
                    // Actualizar
                    $query = "UPDATE user_whatsapp_config SET
                              waha_base_url = ?,
                              waha_api_key = ?,
                              waha_timeout = ?,
                              waha_default_session = ?,
                              waha_default_country_code = ?,
                              activo = 1
                              WHERE usuario_id = ?";
                    $stmt = $pdo->prepare($query);
                    $stmt->execute([
                        $config['base_url'] ?? '',
                        $config['api_key'] ?? '',
                        $config['timeout'] ?? 30,
                        $config['default_session'] ?? 'default',
                        $config['default_country_code'] ?? '54',
                        $userId
                    ]);
                } else {
                    // Insertar
                    $query = "INSERT INTO user_whatsapp_config 
                             (usuario_id, waha_base_url, waha_api_key, waha_timeout, 
                              waha_default_session, waha_default_country_code, activo)
                             VALUES (?, ?, ?, ?, ?, ?, 1)";
                    $stmt = $pdo->prepare($query);
                    $stmt->execute([
                        $userId,
                        $config['base_url'] ?? '',
                        $config['api_key'] ?? '',
                        $config['timeout'] ?? 30,
                        $config['default_session'] ?? 'default',
                        $config['default_country_code'] ?? '54'
                    ]);
                }
                
                return true;
            } catch (Exception $e) {
                error_log('Error guardando configuración WAHA por usuario: ' . $e->getMessage());
                error_log('Stack trace: ' . $e->getTraceAsString());
                return false;
            }
        }
    }
}

