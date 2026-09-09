<?php
/**
 * EmailConfig - Gestor de Configuración del Módulo de Email
 * 
 * Carga y valida la configuración del módulo de email desde archivos externos.
 * Permite múltiples entornos (desarrollo/producción).
 * 
 * @package EmailModule
 * @version 1.0
 */

if (!class_exists('EmailConfig')) {
    class EmailConfig {
        private $config = [];
        private $configFile;
        private $defaultConfig = [
            'smtp' => [
                'host' => 'smtp.gmail.com',
                'port' => 587,
                'secure' => 'tls', // 'tls' o 'ssl'
                'username' => '',
                'password' => '',
                'from_email' => 'noreply@tjsmedical.com',
                'from_name' => 'TJS Medical - Portal de Estudios'
            ],
            'options' => [
                'charset' => 'UTF-8',
                'debug' => false,
                'log_errors' => true,
                'log_file' => null // Se establecerá automáticamente
            ]
        ];
        
        /**
         * Constructor
         * @param string|null $configFile Ruta al archivo de configuración
         */
        public function __construct($configFile = null) {
            if ($configFile === null) {
                $configFile = __DIR__ . '/config/email_config.php';
            }
            $this->configFile = $configFile;
            $this->loadConfig();
        }
        
        /**
         * Cargar configuración desde archivo
         * @return bool True si se cargó correctamente
         */
        private function loadConfig() {
            // Establecer log_file por defecto si no está configurado
            if (!isset($this->defaultConfig['options']['log_file'])) {
                $this->defaultConfig['options']['log_file'] = __DIR__ . '/logs/email.log';
            }
            
            // Si el archivo no existe, usar configuración por defecto
            if (!file_exists($this->configFile)) {
                $this->config = $this->defaultConfig;
                $this->log('warning', 'Archivo de configuración no encontrado, usando valores por defecto: ' . $this->configFile);
                return false;
            }
            
            // Cargar configuración desde archivo
            $loadedConfig = include $this->configFile;
            
            if (!is_array($loadedConfig)) {
                $this->config = $this->defaultConfig;
                $this->log('error', 'Error al cargar configuración, formato inválido');
                return false;
            }
            
            // Usar la configuración cargada como base, solo completar con valores por defecto si faltan
            $this->config = $loadedConfig;
            
            // Solo agregar valores por defecto para claves que no existen en la configuración cargada
            if (!isset($this->config['smtp']) || !is_array($this->config['smtp'])) {
                $this->config['smtp'] = $this->defaultConfig['smtp'];
            } else {
                // Para SMTP, solo usar valores por defecto si la clave no existe en la configuración cargada
                foreach ($this->defaultConfig['smtp'] as $key => $defaultValue) {
                    if (!isset($this->config['smtp'][$key]) || $this->config['smtp'][$key] === '') {
                        $this->config['smtp'][$key] = $defaultValue;
                    }
                }
            }
            
            if (!isset($this->config['options']) || !is_array($this->config['options'])) {
                $this->config['options'] = $this->defaultConfig['options'];
            } else {
                // Para options, solo usar valores por defecto si la clave no existe
                foreach ($this->defaultConfig['options'] as $key => $defaultValue) {
                    if (!isset($this->config['options'][$key])) {
                        $this->config['options'][$key] = $defaultValue;
                    }
                }
            }
            
            // Establecer log_file si no está en la configuración
            if (empty($this->config['options']['log_file'])) {
                $this->config['options']['log_file'] = __DIR__ . '/logs/email.log';
            }
            
            return true;
        }
        
        /**
         * Cargar configuración estática (método helper)
         * @param string|null $configFile Ruta al archivo de configuración
         * @return EmailConfig Instancia de EmailConfig
         */
        public static function load($configFile = null) {
            return new self($configFile);
        }
        
        /**
         * Obtener valor de configuración
         * @param string $key Clave en formato "seccion.clave" (ej: "smtp.host")
         * @param mixed $default Valor por defecto si no existe
         * @return mixed Valor de configuración
         */
        public function get($key, $default = null) {
            $keys = explode('.', $key);
            $value = $this->config;
            
            foreach ($keys as $k) {
                if (!isset($value[$k])) {
                    return $default;
                }
                $value = $value[$k];
            }
            
            return $value;
        }
        
        /**
         * Obtener toda la configuración
         * @return array Configuración completa
         */
        public function getAll() {
            return $this->config;
        }
        
        /**
         * Obtener configuración SMTP
         * @return array Configuración SMTP
         */
        public function getSmtpConfig() {
            return $this->config['smtp'] ?? [];
        }
        
        /**
         * Obtener opciones
         * @return array Opciones de configuración
         */
        public function getOptions() {
            return $this->config['options'] ?? [];
        }
        
        /**
         * Validar configuración
         * @return array ['valid' => bool, 'errors' => array]
         */
        public function validate() {
            $errors = [];
            
            // Validar SMTP
            if (empty($this->config['smtp']['host'])) {
                $errors[] = 'SMTP host no configurado';
            }
            
            if (empty($this->config['smtp']['port'])) {
                $errors[] = 'SMTP port no configurado';
            }
            
            if (empty($this->config['smtp']['username'])) {
                $errors[] = 'SMTP username no configurado';
            }
            
            if (empty($this->config['smtp']['password'])) {
                $errors[] = 'SMTP password no configurado';
            }
            
            if (empty($this->config['smtp']['from_email'])) {
                $errors[] = 'From email no configurado';
            }
            
            // Validar formato de email
            if (!empty($this->config['smtp']['from_email'])) {
                $fromEmail = $this->config['smtp']['from_email'];
                // Asegurar que sea string
                if (is_array($fromEmail)) {
                    $errors[] = 'From email no puede ser un array';
                } else {
                    $fromEmail = trim((string)$fromEmail);
                    if (empty($fromEmail)) {
                        $errors[] = 'From email está vacío después de limpiar espacios';
                    } elseif (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                        $errors[] = 'From email tiene formato inválido: ' . htmlspecialchars($fromEmail);
                    }
                }
            }
            
            return [
                'valid' => empty($errors),
                'errors' => $errors
            ];
        }
        
        /**
         * Cargar configuración SMTP de un usuario específico desde la base de datos
         * @param int $userId ID del usuario
         * @return EmailConfig|null Instancia de EmailConfig con la configuración del usuario, o null si no existe
         */
        public static function loadForUser($userId) {
            try {
                require_once __DIR__ . '/../../config/database.php';
                $pdo = getDBConnection();
                
                if (!$pdo) {
                    return null;
                }
                
                $query = "SELECT smtp_host, smtp_port, smtp_secure, smtp_username, smtp_password, from_email, from_name 
                         FROM user_smtp_config 
                         WHERE usuario_id = ? AND activo = 1 
                         LIMIT 1";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$userId]);
                $userConfig = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$userConfig) {
                    return null;
                }
                
                // Crear configuración desde los datos del usuario
                $config = [
                    'smtp' => [
                        'host' => $userConfig['smtp_host'],
                        'port' => (int)$userConfig['smtp_port'],
                        'secure' => $userConfig['smtp_secure'],
                        'username' => $userConfig['smtp_username'],
                        'password' => $userConfig['smtp_password'],
                        'from_email' => $userConfig['from_email'],
                        'from_name' => $userConfig['from_name'] ?? 'TJS Medical - Portal de Estudios'
                    ],
                    'options' => [
                        'charset' => 'UTF-8',
                        'debug' => false,
                        'log_errors' => true,
                        'log_file' => __DIR__ . '/logs/email.log'
                    ]
                ];
                
                $emailConfig = new self();
                $emailConfig->config = $config;
                return $emailConfig;
                
            } catch (Exception $e) {
                error_log('Error cargando configuración SMTP del usuario ' . $userId . ': ' . $e->getMessage());
                return null;
            }
        }
        
        /**
         * Guardar configuración SMTP de un usuario en la base de datos
         * @param int $userId ID del usuario
         * @param array $smtpConfig Configuración SMTP
         * @return bool True si se guardó correctamente
         */
        public static function saveForUser($userId, $smtpConfig) {
            try {
                require_once __DIR__ . '/../../config/database.php';
                $pdo = getDBConnection();
                
                if (!$pdo) {
                    return false;
                }
                
                // Verificar si ya existe configuración para este usuario
                $query = "SELECT id FROM user_smtp_config WHERE usuario_id = ?";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$userId]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existing) {
                    // Actualizar configuración existente
                    $query = "UPDATE user_smtp_config SET 
                             smtp_host = ?, smtp_port = ?, smtp_secure = ?, 
                             smtp_username = ?, smtp_password = ?, from_email = ?, from_name = ?,
                             fecha_actualizacion = NOW()
                             WHERE usuario_id = ?";
                    $stmt = $pdo->prepare($query);
                    $stmt->execute([
                        $smtpConfig['host'],
                        (int)$smtpConfig['port'],
                        $smtpConfig['secure'],
                        $smtpConfig['username'],
                        $smtpConfig['password'],
                        $smtpConfig['from_email'],
                        $smtpConfig['from_name'] ?? 'TJS Medical - Portal de Estudios',
                        $userId
                    ]);
                } else {
                    // Insertar nueva configuración
                    $query = "INSERT INTO user_smtp_config 
                             (usuario_id, smtp_host, smtp_port, smtp_secure, smtp_username, smtp_password, from_email, from_name) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $pdo->prepare($query);
                    $stmt->execute([
                        $userId,
                        $smtpConfig['host'],
                        (int)$smtpConfig['port'],
                        $smtpConfig['secure'],
                        $smtpConfig['username'],
                        $smtpConfig['password'],
                        $smtpConfig['from_email'],
                        $smtpConfig['from_name'] ?? 'TJS Medical - Portal de Estudios'
                    ]);
                }
                
                return true;
                
            } catch (Exception $e) {
                error_log('Error guardando configuración SMTP del usuario ' . $userId . ': ' . $e->getMessage());
                return false;
            }
        }
        
        /**
         * Log de mensajes
         * @param string $level Nivel (info, warning, error)
         * @param string $message Mensaje
         */
        private function log($level, $message) {
            if ($this->get('options.log_errors', true)) {
                $logFile = $this->get('options.log_file', __DIR__ . '/logs/email.log');                                                                         
                $logDir = dirname($logFile);
                
                // Crear directorio de logs si no existe
                if (!is_dir($logDir)) {
                    @mkdir($logDir, 0755, true);
                }
                
                $timestamp = date('Y-m-d H:i:s');
                $logMessage = "[{$timestamp}] [{$level}] {$message}\n";
                @file_put_contents($logFile, $logMessage, FILE_APPEND);
            }
        }
    }
}

