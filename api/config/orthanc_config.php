<?php
/**
 * Configuración del servidor Orthanc
 * Este archivo contiene todas las configuraciones necesarias para conectar con Orthanc
 */

// Asegurar que no haya salida antes de las clases
if (!class_exists('OrthancConfig')) {
    class OrthancConfig {
        // Configuración por defecto del servidor Orthanc (fallback si no hay BD)
        private static $defaultConfig = [
            'server' => [
                'host' => '192.168.0.22',
                'port' => 8343,
                'protocol' => 'http',
                'username' => 'orthanc',
                'password' => 'orthanc'
            ],
            'viewer' => [
                'url' => 'https://losalisos.tanjousoft.com.ar/u-dicom-viewer/',
                'study_id_param' => 'studyId',
                'pacs_name' => 'LOSALISOS',
                'dicomweb_proxy_gateway_default' => '',
                'viewers' => [
                    'UDV' => [
                        'url' => 'https://losalisos.tanjousoft.com.ar/u-dicom-viewer/',
                        'format' => 'pacs_studyid',
                        'study_id_param' => 'studyId',
                        'pacs_name' => 'LOSALISOS'
                    ],
                    'StoneViewer' => [
                        'url' => 'https://losalisos.tanjousoft.com.ar/visorweb/stone-webviewer/index.html',
                        // URL opcional exclusiva para aperturas remotas vía proxy DICOMweb (server= / dicomWebRoot).
                        // Si está vacía, se usa 'url'.
                        'remote_url' => '',
                        'format' => 'study_uid',
                        'study_id_param' => 'study',
                        // Base DICOMweb/WADO que el navegador puede alcanzar (WAN). Vacío = Stone usa lo que anuncia Orthanc (suele ser IP LAN).
                        'dicomweb_root' => '',
                        // Stone remoto: query hacia el proxy (server = knopkem/TJS; dicomWebRoot = compatibilidad).
                        'remote_proxy_query_key' => 'server',
                    ],
                    'Oviyam' => [
                        'url' => 'https://losalisos.tanjousoft.com.ar/oviyam/',
                        'format' => 'pacs_studyid',
                        'study_id_param' => 'studyId',
                        'pacs_name' => 'LOSALISOS'
                    ]
                ]
            ],
            'api' => [
                'timeout' => 60,
                'connect_timeout' => 10,
                'verify_ssl' => false
            ],
            'download' => [
                'url' => 'https://demoportal.tanjousoft.com.ar/visorweb'
            ]
        ];
        
        // Cache de configuración cargada
        private static $configCache = null;
        
        /**
         * Carga la configuración desde la base de datos o usa valores por defecto
         */
        private static function loadConfig() {
            if (self::$configCache !== null) {
                return self::$configCache;
            }
            
            // Inicializar con valores por defecto
            $config = self::$defaultConfig;
            
            // Intentar cargar desde la base de datos
            try {
                require_once __DIR__ . '/../../config/database.php';
                $database = new Database();
                $db = $database->getConnection();
                
                if ($db) {
                    // Asegurar que la tabla existe
                    $db->exec("
                        CREATE TABLE IF NOT EXISTS configuracion (
                            clave VARCHAR(100) PRIMARY KEY,
                            valor TEXT,
                            descripcion TEXT,
                            fecha_modificacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                    
                    // Función helper para obtener valor
                    $getValue = function($key, $default) use ($db) {
                        $stmt = $db->prepare("SELECT valor FROM configuracion WHERE clave = ?");
                        $stmt->execute([$key]);
                        $result = $stmt->fetch(PDO::FETCH_ASSOC);
                        return $result ? $result['valor'] : $default;
                    };
                    
                    // Cargar configuraciones del servidor
                    $config['server']['host'] = $getValue('pacs_host', $config['server']['host']);
                    $config['server']['port'] = (int)$getValue('pacs_port', $config['server']['port']);
                    $config['server']['protocol'] = $getValue('pacs_protocol', $config['server']['protocol']);
                    $config['server']['username'] = $getValue('pacs_username', $config['server']['username']);
                    $config['server']['password'] = $getValue('pacs_password', $config['server']['password']);
                    
                    // Cargar configuraciones de API
                    $config['api']['timeout'] = (int)$getValue('pacs_timeout', $config['api']['timeout']);
                    $config['api']['connect_timeout'] = (int)$getValue('pacs_connect_timeout', $config['api']['connect_timeout']);
                    $config['api']['verify_ssl'] = (bool)(int)$getValue('pacs_verify_ssl', $config['api']['verify_ssl'] ? '1' : '0');
                    
                    // Cargar configuraciones de visor general
                    $config['viewer']['pacs_name'] = $getValue('pacs_name', $config['viewer']['pacs_name']);
                    $config['viewer']['dicomweb_proxy_gateway_default'] = $getValue('pacs_dicomweb_proxy_gateway_default', $config['viewer']['dicomweb_proxy_gateway_default'] ?? '');
                    
                    // Cargar configuraciones de UDV
                    $config['viewer']['viewers']['UDV']['url'] = $getValue('pacs_viewer_udv_url', $config['viewer']['viewers']['UDV']['url']);
                    $config['viewer']['viewers']['UDV']['format'] = $getValue('pacs_viewer_udv_format', $config['viewer']['viewers']['UDV']['format']);
                    $config['viewer']['viewers']['UDV']['study_id_param'] = $getValue('pacs_viewer_udv_study_id_param', $config['viewer']['viewers']['UDV']['study_id_param']);
                    $config['viewer']['viewers']['UDV']['pacs_name'] = $getValue('pacs_viewer_udv_pacs_name', $config['viewer']['viewers']['UDV']['pacs_name']);
                    
                    // Cargar configuraciones de StoneViewer
                    $config['viewer']['viewers']['StoneViewer']['url'] = $getValue('pacs_viewer_stoneviewer_url', $config['viewer']['viewers']['StoneViewer']['url']);
                    $config['viewer']['viewers']['StoneViewer']['remote_url'] = $getValue('pacs_viewer_stoneviewer_remote_url', $config['viewer']['viewers']['StoneViewer']['remote_url'] ?? '');
                    $config['viewer']['viewers']['StoneViewer']['format'] = $getValue('pacs_viewer_stoneviewer_format', $config['viewer']['viewers']['StoneViewer']['format']);
                    $config['viewer']['viewers']['StoneViewer']['study_id_param'] = $getValue('pacs_viewer_stoneviewer_study_id_param', $config['viewer']['viewers']['StoneViewer']['study_id_param']);
                    $config['viewer']['viewers']['StoneViewer']['dicomweb_root'] = $getValue('pacs_viewer_stoneviewer_dicomweb_root', $config['viewer']['viewers']['StoneViewer']['dicomweb_root'] ?? '');
                    $rk = strtolower(trim($getValue('pacs_stoneviewer_remote_proxy_query_key', $config['viewer']['viewers']['StoneViewer']['remote_proxy_query_key'] ?? 'server')));
                    $config['viewer']['viewers']['StoneViewer']['remote_proxy_query_key'] = ($rk === 'dicomwebroot') ? 'dicomWebRoot' : 'server';
                    
                    // Cargar configuraciones de Oviyam
                    $config['viewer']['viewers']['Oviyam']['url'] = $getValue('pacs_viewer_oviyam_url', $config['viewer']['viewers']['Oviyam']['url']);
                    $config['viewer']['viewers']['Oviyam']['format'] = $getValue('pacs_viewer_oviyam_format', $config['viewer']['viewers']['Oviyam']['format']);
                    $config['viewer']['viewers']['Oviyam']['study_id_param'] = $getValue('pacs_viewer_oviyam_study_id_param', $config['viewer']['viewers']['Oviyam']['study_id_param']);
                    $config['viewer']['viewers']['Oviyam']['pacs_name'] = $getValue('pacs_viewer_oviyam_pacs_name', $config['viewer']['viewers']['Oviyam']['pacs_name']);
                    
                    // Cargar configuración de descargas
                    $config['download']['url'] = $getValue('pacs_download_url', $config['download']['url']);
                }
            } catch (Exception $e) {
                // Si hay error, usar valores por defecto
                error_log("Error cargando configuración de Orthanc desde BD: " . $e->getMessage());
            }
            
            self::$configCache = $config;
            return $config;
        }
        
        /**
         * Limpia el cache de configuración (útil después de actualizar configuraciones)
         */
        public static function clearCache() {
            self::$configCache = null;
        }
        
        /**
         * Obtiene la configuración completa
         */
        public static function getConfig() {
            return self::loadConfig();
        }
        
        /**
         * Obtiene la URL base del servidor Orthanc
         */
        public static function getServerUrl() {
            $config = self::loadConfig();
            return $config['server']['protocol'] . '://' . $config['server']['host'] . ':' . $config['server']['port'];
        }
        
        /**
         * Obtiene las credenciales de autenticación
         */
        public static function getCredentials() {
            $config = self::loadConfig();
            return [
                'username' => $config['server']['username'],
                'password' => $config['server']['password']
            ];
        }
        
        /**
         * Obtiene la URL base para descargas de estudios
         */
        public static function getDownloadUrl() {
            $config = self::loadConfig();
            return $config['download']['url'] ?? 'https://demoportal.tanjousoft.com.ar/visorweb';
        }
        
        /**
         * Detecta si el dispositivo es móvil basándose en el User-Agent
         * @return bool True si es dispositivo móvil, False si es escritorio
         */
        public static function isMobileDevice() {
            if (!isset($_SERVER['HTTP_USER_AGENT'])) {
                return false;
            }
            
            $userAgent = $_SERVER['HTTP_USER_AGENT'];
            $mobilePatterns = [
                '/Mobile/i',
                '/Android/i',
                '/iPhone/i',
                '/iPad/i',
                '/iPod/i',
                '/BlackBerry/i',
                '/Windows Phone/i',
                '/Opera Mini/i',
                '/IEMobile/i'
            ];
            
            foreach ($mobilePatterns as $pattern) {
                if (preg_match($pattern, $userAgent)) {
                    return true;
                }
            }
            
            return false;
        }
        
        /**
         * Obtiene la URL del visor con el ID del estudio
         * @param string $studyId ID del estudio (Orthanc ID)
         * @param string $viewerType Tipo de visor: UDV, StoneViewer, Oviyam (por defecto: UDV)
         * @param string $studyInstanceUID StudyInstanceUID (requerido para StoneViewer)
         * @return string URL del visor con parámetros
         */
        public static function getViewerUrl($studyId, $viewerType = 'UDV', $studyInstanceUID = null, $forceMobile = null) {
            $config = self::loadConfig();
            $viewerConfig = $config['viewer'];
            
            // Si corresponde dispositivo móvil, forzar UDV
            $isMobile = is_bool($forceMobile) ? $forceMobile : self::isMobileDevice();
            if ($isMobile) {
                $viewerType = 'UDV';
            }
            
            // Validar tipo de visor
            if (!in_array($viewerType, ['UDV', 'StoneViewer', 'Oviyam'])) {
                $viewerType = 'UDV'; // Valor por defecto
            }
            
            // Obtener configuración del visor específico
            if (isset($viewerConfig['viewers'][$viewerType])) {
                $viewer = $viewerConfig['viewers'][$viewerType];
                $url = $viewer['url'];
                $format = $viewer['format'] ?? 'pacs_studyid';
            } else {
                // Fallback a configuración por defecto
                $url = $viewerConfig['url'];
                $format = 'pacs_studyid';
            }
            
            // Construir URL según el formato del visor
            switch ($format) {
                case 'study_uid':
                    // Formato para StoneViewer: ?study=STUDY_INSTANCE_UID (opcional &dicomWebRoot= para WAN / proxy)
                    if (empty($studyInstanceUID)) {
                        error_log("Warning: StoneViewer requiere studyInstanceUID pero no se proporcionó. Usando studyId como fallback.");
                        $studyInstanceUID = $studyId;
                    }
                    $studyIdParam = $viewer['study_id_param'] ?? 'study';
                    $sep = (strpos($url, '?') !== false) ? '&' : '?';
                    $open = $url . $sep . $studyIdParam . '=' . rawurlencode($studyInstanceUID);
                    $dicomWebRoot = isset($viewer['dicomweb_root']) ? trim((string) $viewer['dicomweb_root']) : '';
                    if ($dicomWebRoot !== '') {
                        $open .= '&dicomWebRoot=' . rawurlencode(rtrim($dicomWebRoot, '/'));
                    }
                    return $open;
                    
                case 'pacs_studyid':
                default:
                    // Formato para UDV y Oviyam: ?pacs=NAME&studyId=ORTHANC_ID
                    $pacsName = $viewer['pacs_name'] ?? $viewerConfig['pacs_name'] ?? 'LOSALISOS';
                    $studyIdParam = $viewer['study_id_param'] ?? 'studyId';
                    return $url . '?pacs=' . urlencode($pacsName) . '&' . $studyIdParam . '=' . urlencode($studyId);
            }
        }
        
        /**
         * Actualiza la configuración (ahora se guarda en BD)
         */
        public static function updateConfig($newConfig) {
            // Limpiar cache para forzar recarga desde BD
            self::clearCache();
        }
        
        /**
         * Valida la conexión con Orthanc
         */
        public static function testConnection() {
            try {
                $config = self::loadConfig();
                $url = self::getServerUrl() . '/system';
                $credentials = self::getCredentials();
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                curl_setopt($ch, CURLOPT_USERPWD, $credentials['username'] . ':' . $credentials['password']);
                curl_setopt($ch, CURLOPT_TIMEOUT, $config['api']['timeout']);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $config['api']['verify_ssl']);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                return $httpCode === 200;
            } catch (Exception $e) {
                return false;
            }
        }
    }
}
?>
