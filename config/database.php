<?php
/**
 * Configuración de conexión a la base de datos TJSMEDICAL
 * Sistema de autenticación para Portal de Estudios Médicos
 */

if (!class_exists('Database')) {
    class Database {
        // Valores por defecto del archivo (fallback)
        private $default_host = 'localhost';
        private $default_db_name = 'tjsmedical_iddse';
        private $default_username = 'iddse';
        private $default_password = 'iddse263';
        private $default_charset = 'utf8mb4';
        
        
        // Valores actuales (pueden venir de BD o del archivo)
        private $host;
        private $db_name;
        private $username;
        private $password;
        private $charset;
        
        private $conn;
        private static $configCache = null;
        
        public function __construct() {
            // Cargar configuración (desde BD o usar defaults)
            $this->loadConfig();
        }
        
        /**
         * Carga la configuración desde la BD o usa valores por defecto
         */
        private function loadConfig() {
            // Si ya tenemos cache, usarlo
            if (self::$configCache !== null) {
                $config = self::$configCache;
                $this->host = $config['host'];
                $this->db_name = $config['db_name'];
                $this->username = $config['username'];
                $this->password = $config['password'];
                $this->charset = $config['charset'];
                return;
            }
            
            // Inicializar con valores por defecto
            $this->host = $this->default_host;
            $this->db_name = $this->default_db_name;
            $this->username = $this->default_username;
            $this->password = $this->default_password;
            $this->charset = $this->default_charset;
            
            // Intentar leer desde la BD
            try {
                // Primera conexión con valores por defecto
                $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=" . $this->charset;
                $tempConn = new PDO($dsn, $this->username, $this->password);
                $tempConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                // Verificar si existe la tabla configuracion
                $stmt = $tempConn->query("SHOW TABLES LIKE 'configuracion'");
                if ($stmt->rowCount() > 0) {
                    // Leer configuración desde BD
                    $getConfigValue = function($key, $default) use ($tempConn) {
                        try {
                            $stmt = $tempConn->prepare("SELECT valor FROM configuracion WHERE clave = ?");
                            $stmt->execute([$key]);
                            $result = $stmt->fetch(PDO::FETCH_ASSOC);
                            return $result ? $result['valor'] : $default;
                        } catch (PDOException $e) {
                            return $default;
                        }
                    };
                    
                    $dbHost = $getConfigValue('db_host', $this->host);
                    $dbName = $getConfigValue('db_name', $this->db_name);
                    $dbUsername = $getConfigValue('db_username', $this->username);
                    $dbPassword = $getConfigValue('db_password', $this->password);
                    $dbCharset = $getConfigValue('db_charset', $this->charset);
                    
                    // Si el nombre de la BD cambió, necesitamos reconectar a la nueva BD
                    if ($dbName !== $this->db_name) {
                        // Cerrar conexión temporal
                        $tempConn = null;
                        
                        // Intentar conectar a la nueva BD para verificar que existe y tiene datos
                        try {
                            $newDsn = "mysql:host=" . $dbHost . ";dbname=" . $dbName . ";charset=" . $dbCharset;
                            $testConn = new PDO($newDsn, $dbUsername, $dbPassword);
                            $testConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                            
                            $testConn = null; // Cerrar conexión de prueba
                            
                            // Usar la BD configurada (sin fallback)
                            // Si está vacía, el sistema mostrará la página de setup inicial
                            $this->host = $dbHost;
                            $this->db_name = $dbName;
                            $this->username = $dbUsername;
                            $this->password = $dbPassword;
                            $this->charset = $dbCharset;
                        } catch (PDOException $e) {
                            // Si la nueva BD no existe, mantener valores por defecto
                            error_log("Error conectando a BD configurada ($dbName): " . $e->getMessage() . ". Usando BD por defecto.");
                        }
                    } else {
                        // Solo actualizar otros valores si el nombre de BD no cambió
                        $this->host = $dbHost;
                        $this->username = $dbUsername;
                        $this->password = $dbPassword;
                        $this->charset = $dbCharset;
                    }
                }
                
                // Cerrar conexión temporal
                if ($tempConn) {
                    $tempConn = null;
                }
                
            } catch (PDOException $e) {
                // Si falla la conexión inicial, usar valores por defecto
                error_log("Error cargando configuración de BD desde tabla configuracion: " . $e->getMessage());
            }
            
            // Guardar en cache
            self::$configCache = [
                'host' => $this->host,
                'db_name' => $this->db_name,
                'username' => $this->username,
                'password' => $this->password,
                'charset' => $this->charset
            ];
        }
        
        /**
         * Limpia el cache de configuración (útil después de actualizar configuraciones)
         */
        public static function clearCache() {
            self::$configCache = null;
        }
        
        public function getConnection() {
            $this->conn = null;
            
            try {
                $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=" . $this->charset;
                $this->conn = new PDO($dsn, $this->username, $this->password);
                $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
                // Forzar collation utf8mb4_unicode_ci en la conexión
                // Esto evita errores de "Illegal mix of collations" cuando se comparan
                // parámetros bound (que usan la collation de la conexión) con columnas utf8mb4_unicode_ci
                $this->conn->exec("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");
            } catch(PDOException $exception) {
                // No mostrar errores directamente - solo logearlos
                error_log("Error de conexión a BD: " . $exception->getMessage());
                // Retornar null en lugar de mostrar error
            }
            
            return $this->conn;
        }
        
        public function closeConnection() {
            $this->conn = null;
        }
    }
}

/**
 * Función helper para obtener conexión rápida
 */
if (!function_exists('getDBConnection')) {
    function getDBConnection() {
        $database = new Database();
        return $database->getConnection();
    }
}
?>
