<?php
/**
 * API Unificada para Gestión de Configuraciones del Sistema
 * 
 * Permite obtener y actualizar todas las configuraciones del sistema:
 * - Base de datos
 * - Servidor PACS (Orthanc)
 * - URLs del sistema
 * - Otras configuraciones generales
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../config/database.php';

// Asegurar que getDBConnection esté disponible
if (!function_exists('getDBConnection')) {
    function getDBConnection() {
        $database = new Database();
        return $database->getConnection();
    }
}

try {
    // Validar sesión
    $sessionToken = null;
    
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        $sessionToken = $headers['Authorization'] ?? null;
    }
    
    if (!$sessionToken) {
        $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    }
    
    if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
        $sessionToken = substr($sessionToken, 7);
    }
    
    if (!$sessionToken) {
        $sessionToken = $_COOKIE['session_token'] ?? null;
    }
    
    if (!$sessionToken) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Token de autorización requerido']);
        exit();
    }
    
    $user = new User();
    $userData = $user->validateSession($sessionToken);
    
    if (!$userData) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sesión inválida']);
        exit();
    }
    
    // Verificar que el usuario tenga permisos de administración (ROOT o ADMIN)
    if (!in_array(strtolower($userData['nivel'] ?? 'user'), ['root', 'admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'No tienes permisos para acceder a la configuración']);
        exit();
    }
    
    $db = getDBConnection();
    
    if (!$db) {
        throw new Exception('No se pudo conectar a la base de datos');
    }
    
    // Asegurar que la tabla configuracion existe
    ensureConfigTable($db);
    
    // GET: Obtener todas las configuraciones
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $category = $_GET['category'] ?? null;
        
        $configs = getAllConfigurations($db, $category);
        
        echo json_encode([
            'success' => true,
            'configurations' => $configs,
            'message' => 'Configuraciones obtenidas exitosamente'
        ]);
    }
    
    // POST/PUT: Guardar configuración
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            $input = $_POST;
        }
        
        if (isset($input['key']) && isset($input['value'])) {
            // Guardar una configuración individual
            $key = $input['key'];
            $value = $input['value'];
            $description = $input['description'] ?? null;
            
            saveConfiguration($db, $key, $value, $description);
            
            echo json_encode([
                'success' => true,
                'key' => $key,
                'value' => $value,
                'message' => 'Configuración guardada exitosamente'
            ]);
            
        } elseif (isset($input['configurations']) && is_array($input['configurations'])) {
            // Guardar múltiples configuraciones
            $saved = [];
            $errors = [];
            
            foreach ($input['configurations'] as $config) {
                try {
                    $key = $config['key'] ?? null;
                    $value = $config['value'] ?? null;
                    $description = $config['description'] ?? null;
                    
                    if ($key && $value !== null) {
                        saveConfiguration($db, $key, $value, $description);
                        $saved[] = $key;
                    }
                } catch (Exception $e) {
                    $errors[] = ['key' => $key ?? 'unknown', 'error' => $e->getMessage()];
                }
            }
            
            echo json_encode([
                'success' => count($errors) === 0,
                'saved' => $saved,
                'errors' => $errors,
                'message' => count($saved) . ' configuración(es) guardada(s)'
            ]);
        } else {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Formato de datos inválido'
            ]);
        }
    }
    
    else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    }
    
} catch (Exception $e) {
    error_log("Error en config/manage.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor',
        'error' => $e->getMessage()
    ]);
}

/**
 * Asegurar que la tabla configuracion existe
 */
function ensureConfigTable($db) {
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS configuracion (
                clave VARCHAR(100) PRIMARY KEY,
                valor TEXT,
                descripcion TEXT,
                fecha_modificacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        // La tabla ya existe o hay un error, continuar
    }
}

/**
 * Obtener todas las configuraciones
 */
function getAllConfigurations($db, $category = null) {
    $configs = [];
    $getConfigValue = function($key, $default) use ($db) {
        $stmt = $db->prepare("SELECT valor FROM configuracion WHERE clave = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['valor'] : $default;
    };
    
    // Configuraciones de base de datos
    if (!$category || $category === 'database') {
        require_once __DIR__ . '/../../config/database.php';
        $dbConfig = new Database();
        
        // Usar reflexión para obtener propiedades privadas (valores por defecto del archivo)
        $reflection = new ReflectionClass($dbConfig);
        $hostProp = $reflection->getProperty('host');
        $hostProp->setAccessible(true);
        $dbNameProp = $reflection->getProperty('db_name');
        $dbNameProp->setAccessible(true);
        $usernameProp = $reflection->getProperty('username');
        $usernameProp->setAccessible(true);
        $passwordProp = $reflection->getProperty('password');
        $passwordProp->setAccessible(true);
        $charsetProp = $reflection->getProperty('charset');
        $charsetProp->setAccessible(true);
        
        // Función helper para obtener valor desde BD o usar default del archivo
        $configs['database'] = [
            'host' => [
                'key' => 'db_host',
                'value' => $getConfigValue('db_host', $hostProp->getValue($dbConfig)),
                'description' => 'Host de la base de datos',
                'category' => 'database',
                'type' => 'text',
                'readonly' => false
            ],
            'db_name' => [
                'key' => 'db_name',
                'value' => $getConfigValue('db_name', $dbNameProp->getValue($dbConfig)),
                'description' => 'Nombre de la base de datos',
                'category' => 'database',
                'type' => 'text',
                'readonly' => false
            ],
            'username' => [
                'key' => 'db_username',
                'value' => $getConfigValue('db_username', $usernameProp->getValue($dbConfig)),
                'description' => 'Usuario de la base de datos',
                'category' => 'database',
                'type' => 'text',
                'readonly' => false
            ],
            'password' => [
                'key' => 'db_password',
                'value' => '••••••••', // No mostrar la contraseña real
                'description' => 'Contraseña de la base de datos',
                'category' => 'database',
                'type' => 'password',
                'readonly' => false,
                'masked' => true
            ],
            'charset' => [
                'key' => 'db_charset',
                'value' => $getConfigValue('db_charset', $charsetProp->getValue($dbConfig)),
                'description' => 'Charset de la base de datos',
                'category' => 'database',
                'type' => 'text',
                'readonly' => false
            ]
        ];
    }
    
    // Configuraciones de PACS
    if (!$category || $category === 'pacs') {
        // Obtener configuraciones desde BD o usar valores por defecto del archivo
        require_once __DIR__ . '/../config/orthanc_config.php';
        $orthancConfig = OrthancConfig::getConfig();
        
        // Función helper para obtener valor desde BD o usar default
        $configs['pacs'] = [
            'host' => [
                'key' => 'pacs_host',
                'value' => $getConfigValue('pacs_host', $orthancConfig['server']['host'] ?? '192.168.0.22'),
                'description' => 'Host del servidor PACS (Orthanc)',
                'category' => 'pacs',
                'type' => 'text',
                'readonly' => false
            ],
            'port' => [
                'key' => 'pacs_port',
                'value' => $getConfigValue('pacs_port', $orthancConfig['server']['port'] ?? '8343'),
                'description' => 'Puerto del servidor PACS',
                'category' => 'pacs',
                'type' => 'number',
                'readonly' => false
            ],
            'protocol' => [
                'key' => 'pacs_protocol',
                'value' => $getConfigValue('pacs_protocol', $orthancConfig['server']['protocol'] ?? 'http'),
                'description' => 'Protocolo (http o https)',
                'category' => 'pacs',
                'type' => 'select',
                'options' => ['http', 'https'],
                'readonly' => false
            ],
            'username' => [
                'key' => 'pacs_username',
                'value' => $getConfigValue('pacs_username', $orthancConfig['server']['username'] ?? 'orthanc'),
                'description' => 'Usuario del servidor PACS',
                'category' => 'pacs',
                'type' => 'text',
                'readonly' => false
            ],
            'password' => [
                'key' => 'pacs_password',
                'value' => '••••••••',
                'description' => 'Contraseña del servidor PACS',
                'category' => 'pacs',
                'type' => 'password',
                'readonly' => false,
                'masked' => true
            ],
            'timeout' => [
                'key' => 'pacs_timeout',
                'value' => $getConfigValue('pacs_timeout', $orthancConfig['api']['timeout'] ?? '60'),
                'description' => 'Timeout para peticiones HTTP (segundos)',
                'category' => 'pacs',
                'type' => 'number',
                'readonly' => false
            ],
            'connect_timeout' => [
                'key' => 'pacs_connect_timeout',
                'value' => $getConfigValue('pacs_connect_timeout', $orthancConfig['api']['connect_timeout'] ?? '10'),
                'description' => 'Timeout de conexión (segundos)',
                'category' => 'pacs',
                'type' => 'number',
                'readonly' => false
            ],
            'verify_ssl' => [
                'key' => 'pacs_verify_ssl',
                'value' => $getConfigValue('pacs_verify_ssl', $orthancConfig['api']['verify_ssl'] ? '1' : '0'),
                'description' => 'Verificar certificados SSL',
                'category' => 'pacs',
                'type' => 'select',
                'options' => ['0' => 'No', '1' => 'Sí'],
                'readonly' => false
            ],
            'pacs_name' => [
                'key' => 'pacs_name',
                'value' => $getConfigValue('pacs_name', $orthancConfig['viewer']['pacs_name'] ?? 'LOSALISOS'),
                'description' => 'Nombre del PACS',
                'category' => 'pacs',
                'type' => 'text',
                'readonly' => false
            ],
            // Visor UDV
            'viewer_udv_url' => [
                'key' => 'pacs_viewer_udv_url',
                'value' => $getConfigValue('pacs_viewer_udv_url', $orthancConfig['viewer']['viewers']['UDV']['url'] ?? 'https://losalisos.tanjousoft.com.ar/u-dicom-viewer/'),
                'description' => 'URL del visor UDV',
                'category' => 'pacs',
                'type' => 'url',
                'readonly' => false
            ],
            'viewer_udv_format' => [
                'key' => 'pacs_viewer_udv_format',
                'value' => $getConfigValue('pacs_viewer_udv_format', $orthancConfig['viewer']['viewers']['UDV']['format'] ?? 'pacs_studyid'),
                'description' => 'Formato del visor UDV (pacs_studyid o study_uid)',
                'category' => 'pacs',
                'type' => 'select',
                'options' => ['pacs_studyid', 'study_uid'],
                'readonly' => false
            ],
            'viewer_udv_study_id_param' => [
                'key' => 'pacs_viewer_udv_study_id_param',
                'value' => $getConfigValue('pacs_viewer_udv_study_id_param', $orthancConfig['viewer']['viewers']['UDV']['study_id_param'] ?? 'studyId'),
                'description' => 'Parámetro del ID de estudio para UDV',
                'category' => 'pacs',
                'type' => 'text',
                'readonly' => false
            ],
            'viewer_udv_pacs_name' => [
                'key' => 'pacs_viewer_udv_pacs_name',
                'value' => $getConfigValue('pacs_viewer_udv_pacs_name', $orthancConfig['viewer']['viewers']['UDV']['pacs_name'] ?? 'LOSALISOS'),
                'description' => 'Nombre del PACS para UDV',
                'category' => 'pacs',
                'type' => 'text',
                'readonly' => false
            ],
            // Visor StoneViewer
            'viewer_stoneviewer_url' => [
                'key' => 'pacs_viewer_stoneviewer_url',
                'value' => $getConfigValue('pacs_viewer_stoneviewer_url', $orthancConfig['viewer']['viewers']['StoneViewer']['url'] ?? 'https://losalisos.tanjousoft.com.ar/visorweb/stone-webviewer/index.html'),
                'description' => 'URL del visor StoneViewer',
                'category' => 'pacs',
                'type' => 'url',
                'readonly' => false
            ],
            'viewer_stoneviewer_remote_url' => [
                'key' => 'pacs_viewer_stoneviewer_remote_url',
                'value' => $getConfigValue('pacs_viewer_stoneviewer_remote_url', $orthancConfig['viewer']['viewers']['StoneViewer']['remote_url'] ?? ''),
                'description' => 'URL de StoneViewer para aperturas remotas con proxy DICOMweb (server=/dicomWebRoot). Ejemplo recomendado: https://webportal.iddse.com.ar/stone-standalone/index.html. Vacío = usar la URL principal de StoneViewer.',
                'category' => 'pacs',
                'type' => 'url',
                'readonly' => false
            ],
            'viewer_stoneviewer_format' => [
                'key' => 'pacs_viewer_stoneviewer_format',
                'value' => $getConfigValue('pacs_viewer_stoneviewer_format', $orthancConfig['viewer']['viewers']['StoneViewer']['format'] ?? 'study_uid'),
                'description' => 'Formato del visor StoneViewer (pacs_studyid o study_uid)',
                'category' => 'pacs',
                'type' => 'select',
                'options' => ['pacs_studyid', 'study_uid'],
                'readonly' => false
            ],
            'viewer_stoneviewer_study_id_param' => [
                'key' => 'pacs_viewer_stoneviewer_study_id_param',
                'value' => $getConfigValue('pacs_viewer_stoneviewer_study_id_param', $orthancConfig['viewer']['viewers']['StoneViewer']['study_id_param'] ?? 'study'),
                'description' => 'Parámetro del ID de estudio para StoneViewer',
                'category' => 'pacs',
                'type' => 'text',
                'readonly' => false
            ],
            'viewer_stoneviewer_dicomweb_root' => [
                'key' => 'pacs_viewer_stoneviewer_dicomweb_root',
                'value' => $getConfigValue('pacs_viewer_stoneviewer_dicomweb_root', $orthancConfig['viewer']['viewers']['StoneViewer']['dicomweb_root'] ?? ''),
                'description' => 'URL pública DICOMweb (o raíz WADO-RS) del Orthanc para el navegador, sin barra final. Ej: https://pacs.clinic.com/orthanc/dicom-web. Si está vacío, Stone suele usar la URL interna que Orthanc anuncia (LAN). Mismo concepto que dicomweb_proxy_base en nodos remotos.',
                'category' => 'pacs',
                'type' => 'url',
                'readonly' => false
            ],
            'dicomweb_proxy_gateway_default' => [
                'key' => 'pacs_dicomweb_proxy_gateway_default',
                'value' => $getConfigValue('pacs_dicomweb_proxy_gateway_default', $orthancConfig['viewer']['dicomweb_proxy_gateway_default'] ?? ''),
                'description' => 'URL base del proxy DICOMweb (p. ej. knopkem dicomweb-proxy: http://192.168.0.189:5000/rs), sin barra final. Si un nodo remoto no tiene dicomweb_proxy_base, Portal v2 / historial usan esta URL para Stone remoto.',
                'category' => 'pacs',
                'type' => 'url',
                'readonly' => false
            ],
            'viewer_stoneviewer_remote_proxy_query_key' => [
                'key' => 'pacs_stoneviewer_remote_proxy_query_key',
                'value' => $getConfigValue('pacs_stoneviewer_remote_proxy_query_key', $orthancConfig['viewer']['viewers']['StoneViewer']['remote_proxy_query_key'] ?? 'server'),
                'description' => 'Nombre del parámetro de query para la raíz DICOMweb en Stone remoto: server (knoopkem/TJS, ej. ?server=.../rs&study=UID) o dicomWebRoot (compatibilidad con otros despliegues Stone).',
                'category' => 'pacs',
                'type' => 'select',
                'options' => ['server', 'dicomWebRoot'],
                'readonly' => false
            ],
            // Visor Oviyam
            'viewer_oviyam_url' => [
                'key' => 'pacs_viewer_oviyam_url',
                'value' => $getConfigValue('pacs_viewer_oviyam_url', $orthancConfig['viewer']['viewers']['Oviyam']['url'] ?? 'https://losalisos.tanjousoft.com.ar/oviyam/'),
                'description' => 'URL del visor Oviyam',
                'category' => 'pacs',
                'type' => 'url',
                'readonly' => false
            ],
            'viewer_oviyam_format' => [
                'key' => 'pacs_viewer_oviyam_format',
                'value' => $getConfigValue('pacs_viewer_oviyam_format', $orthancConfig['viewer']['viewers']['Oviyam']['format'] ?? 'pacs_studyid'),
                'description' => 'Formato del visor Oviyam (pacs_studyid o study_uid)',
                'category' => 'pacs',
                'type' => 'select',
                'options' => ['pacs_studyid', 'study_uid'],
                'readonly' => false
            ],
            'viewer_oviyam_study_id_param' => [
                'key' => 'pacs_viewer_oviyam_study_id_param',
                'value' => $getConfigValue('pacs_viewer_oviyam_study_id_param', $orthancConfig['viewer']['viewers']['Oviyam']['study_id_param'] ?? 'studyId'),
                'description' => 'Parámetro del ID de estudio para Oviyam',
                'category' => 'pacs',
                'type' => 'text',
                'readonly' => false
            ],
            // URL de Descargas
            'download_url' => [
                'key' => 'pacs_download_url',
                'value' => $getConfigValue('pacs_download_url', $orthancConfig['download']['url'] ?? 'https://demoportal.tanjousoft.com.ar/visorweb'),
                'description' => 'URL base del servidor Orthanc para descargas de estudios (sin /studies/{id}/archive)',
                'category' => 'pacs',
                'type' => 'url',
                'readonly' => false
            ],
            'viewer_oviyam_pacs_name' => [
                'key' => 'pacs_viewer_oviyam_pacs_name',
                'value' => $getConfigValue('pacs_viewer_oviyam_pacs_name', $orthancConfig['viewer']['viewers']['Oviyam']['pacs_name'] ?? 'LOSALISOS'),
                'description' => 'Nombre del PACS para Oviyam',
                'category' => 'pacs',
                'type' => 'text',
                'readonly' => false
            ]
        ];
    }
    
    // Configuraciones de URLs
    if (!$category || $category === 'urls') {
        // Obtener de base de datos o usar valores por defecto
        $stmt = $db->prepare("SELECT clave, valor, descripcion FROM configuracion WHERE clave LIKE 'url_%'");
        $stmt->execute();
        $urlConfigs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $configs['urls'] = [];
        
        // URLs comunes
        $defaultUrls = [
            'url_base' => ['value' => 'http://localhost', 'description' => 'URL base del sistema'],
            'url_study_history_api' => [
                'value' => '',
                'description' => 'URL pública donde existen manifest.php y wado-instance.php (sin barra final). Si está vacío, se usa url_base. Obligatorio si url_base apunta a otro host (ej. webportal) y el API del historial vive solo en plataforma.'
            ],
            'url_api' => ['value' => 'http://localhost/api', 'description' => 'URL base de la API'],
            'url_dashboard' => ['value' => 'http://localhost/dashboard-unified.html', 'description' => 'URL del dashboard']
        ];
        
        foreach ($defaultUrls as $key => $default) {
            $found = array_search($key, array_column($urlConfigs, 'clave'));
            $configValue = $found !== false ? $urlConfigs[$found]['valor'] : $default['value'];
            $configDesc = $found !== false ? $urlConfigs[$found]['descripcion'] : $default['description'];
            
            $configs['urls'][$key] = [
                'key' => $key,
                'value' => $configValue,
                'description' => $configDesc,
                'category' => 'urls',
                'type' => 'url',
                'readonly' => false
            ];
        }
    }
    
    // Otras configuraciones de la base de datos
    if (!$category || $category === 'general') {
        $stmt = $db->prepare("SELECT clave, valor, descripcion FROM configuracion WHERE clave NOT LIKE 'url_%' AND clave NOT LIKE 'db_%' AND clave NOT LIKE 'pacs_%' AND clave NOT LIKE 'ic_%' AND clave NOT LIKE 'ir_%'");
        $stmt->execute();
        $generalConfigs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $configs['general'] = [];
        
        foreach ($generalConfigs as $config) {
            // Determinar el tipo según la clave
            $type = 'text';
            $options = null;
            
            if ($config['clave'] === 'pacs_formato_defecto') {
                $type = 'select';
                $options = ['pdf', 'jpg'];
            } elseif ($config['clave'] === 'paciente_search_type') {
                $type = 'select';
                $options = ['idpaciente', 'id_interno'];
            } elseif ($config['clave'] === 'app_logo') {
                $type = 'file';
            }
            
            $configs['general'][$config['clave']] = [
                'key' => $config['clave'],
                'value' => $config['valor'],
                'description' => $config['descripcion'] ?? '',
                'category' => 'general',
                'type' => $type,
                'options' => $options,
                'readonly' => false
            ];
        }
        
        // Agregar configuraciones conocidas si no existen
        $knownConfigs = [
            'pacs_formato_defecto' => ['value' => 'pdf', 'description' => 'Formato por defecto para envío a PACS: pdf o jpg'],
            'paciente_search_type' => ['value' => 'idpaciente', 'description' => 'Tipo de búsqueda por defecto en paciente.html: idpaciente o id_interno'],
            'app_titulo' => ['value' => 'GESTION DE ESTUDIOS', 'description' => 'Título de la aplicación que se muestra en la pantalla de inicio'],
            'app_logo' => ['value' => '', 'description' => 'Ruta del logo de la aplicación (opcional)']
        ];
        
        foreach ($knownConfigs as $key => $default) {
            if (!isset($configs['general'][$key])) {
                $stmt = $db->prepare("SELECT valor FROM configuracion WHERE clave = ?");
                $stmt->execute([$key]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $type = 'text';
                $options = null;
                
                if ($key === 'pacs_formato_defecto') {
                    $type = 'select';
                    $options = ['pdf', 'jpg'];
                } elseif ($key === 'paciente_search_type') {
                    $type = 'select';
                    $options = ['idpaciente', 'id_interno'];
                } elseif ($key === 'app_logo') {
                    $type = 'file';
                }
                
                $configs['general'][$key] = [
                    'key' => $key,
                    'value' => $existing ? $existing['valor'] : $default['value'],
                    'description' => $default['description'],
                    'category' => 'general',
                    'type' => $type,
                    'options' => $options,
                    'readonly' => false
                ];
            }
        }
    }

    // Study routing (R2 manifest / ZIP; credenciales en Cloud Storage)
    if (!$category || $category === 'study_routing') {
        $configs['study_routing'] = [
            'study_routing_global_mode' => [
                'key' => 'study_routing_global_mode',
                'value' => $getConfigValue('study_routing_global_mode', 'local'),
                'description' => 'Modo por defecto para usuarios con permiso study_routing: local = PACS; r2 = manifest o ZIP presignado según el estudio en R2.',
                'category' => 'study_routing',
                'type' => 'select',
                'options' => ['local', 'r2'],
                'readonly' => false
            ],
            'study_routing_manifest_query_param' => [
                'key' => 'study_routing_manifest_query_param',
                'value' => $getConfigValue('study_routing_manifest_query_param', 'manifestUrl'),
                'description' => 'Parámetro de query del visor para la URL del manifest (manifest.php).',
                'category' => 'study_routing',
                'type' => 'text',
                'readonly' => false
            ]
        ];
    }

    // Portal de estudios (fase 0: configuración sin impacto; v2 apagado por defecto)
    if (!$category || $category === 'portal_estudios') {
        $configs['portal_estudios'] = [
            'portal_estudios_v2_enabled' => [
                'key' => 'portal_estudios_v2_enabled',
                'value' => $getConfigValue('portal_estudios_v2_enabled', '0'),
                'description' => 'Activa el flujo nuevo del portal de estudios (0 = legacy actual, 1 = nuevo enrutamiento).',
                'category' => 'portal_estudios',
                'type' => 'select',
                'options' => ['0' => 'No', '1' => 'Sí'],
                'readonly' => false
            ],
            'portal_estudios_listing_mode' => [
                'key' => 'portal_estudios_listing_mode',
                'value' => $getConfigValue('portal_estudios_listing_mode', 'local'),
                'description' => 'Origen de listado en portal: local, remote o mixed.',
                'category' => 'portal_estudios',
                'type' => 'select',
                'options' => ['local', 'remote', 'mixed'],
                'readonly' => false
            ],
            'portal_estudios_opening_mode' => [
                'key' => 'portal_estudios_opening_mode',
                'value' => $getConfigValue('portal_estudios_opening_mode', 'auto'),
                'description' => 'Apertura de estudios: auto (con fallback) o strict (sin fallback).',
                'category' => 'portal_estudios',
                'type' => 'select',
                'options' => ['auto', 'strict'],
                'readonly' => false
            ],
            'portal_estudios_strict_node_id' => [
                'key' => 'portal_estudios_strict_node_id',
                'value' => $getConfigValue('portal_estudios_strict_node_id', ''),
                'description' => 'ID de nodo fijo para modo strict remoto (vacío = no definido).',
                'category' => 'portal_estudios',
                'type' => 'text',
                'readonly' => false
            ],
            'portal_estudios_use_r2' => [
                'key' => 'portal_estudios_use_r2',
                'value' => $getConfigValue('portal_estudios_use_r2', '1'),
                'description' => 'Permite usar estudios en R2/Cloud Storage al abrir.',
                'category' => 'portal_estudios',
                'type' => 'select',
                'options' => ['0' => 'No', '1' => 'Sí'],
                'readonly' => false
            ],
            'portal_estudios_r2_priority' => [
                'key' => 'portal_estudios_r2_priority',
                'value' => $getConfigValue('portal_estudios_r2_priority', 'first'),
                'description' => 'Prioridad de R2 en modo auto: first (primero) o last (fallback).',
                'category' => 'portal_estudios',
                'type' => 'select',
                'options' => ['first', 'last'],
                'readonly' => false
            ],
            'portal_estudios_search_id_type' => [
                'key' => 'portal_estudios_search_id_type',
                'value' => $getConfigValue('portal_estudios_search_id_type', 'idpaciente'),
                'description' => 'Identificador por defecto de búsqueda en portal.',
                'category' => 'portal_estudios',
                'type' => 'select',
                'options' => ['idpaciente', 'id_interno'],
                'readonly' => false
            ],
            'portal_estudios_viewer_desktop' => [
                'key' => 'portal_estudios_viewer_desktop',
                'value' => $getConfigValue('portal_estudios_viewer_desktop', 'UDV'),
                'description' => 'Visor preferido para desktop en el portal.',
                'category' => 'portal_estudios',
                'type' => 'select',
                'options' => ['UDV', 'StoneViewer', 'OHIF', 'Oviyam', 'VolView'],
                'readonly' => false
            ],
            'portal_estudios_viewer_mobile' => [
                'key' => 'portal_estudios_viewer_mobile',
                'value' => $getConfigValue('portal_estudios_viewer_mobile', 'UDV'),
                'description' => 'Visor preferido para mobile en el portal.',
                'category' => 'portal_estudios',
                'type' => 'select',
                'options' => ['UDV', 'StoneViewer', 'OHIF', 'Oviyam', 'VolView'],
                'readonly' => false
            ],
            'portal_estudios_show_source_badge' => [
                'key' => 'portal_estudios_show_source_badge',
                'value' => $getConfigValue('portal_estudios_show_source_badge', '1'),
                'description' => 'Mostrar badge de origen (R2/Local/Remoto) en listados del portal.',
                'category' => 'portal_estudios',
                'type' => 'select',
                'options' => ['0' => 'No', '1' => 'Sí'],
                'readonly' => false
            ],
        ];
    }

    // Auto-vinculación de informes recibidos por score
    if (!$category || $category === 'informes_recibidos') {
        $configs['informes_recibidos'] = [
            'ir_auto_vincular_activo' => [
                'key' => 'ir_auto_vincular_activo',
                'value' => $getConfigValue('ir_auto_vincular_activo', '0'),
                'description' => 'Activar vinculación automática por score (0 = desactivado, 1 = activado).',
                'category' => 'informes_recibidos',
                'type' => 'select',
                'options' => ['0' => 'No', '1' => 'Sí'],
                'readonly' => false
            ],
            'ir_auto_vincular_min_score' => [
                'key' => 'ir_auto_vincular_min_score',
                'value' => $getConfigValue('ir_auto_vincular_min_score', '75'),
                'description' => 'Umbral mínimo de score (0 a 100) para vinculación automática.',
                'category' => 'informes_recibidos',
                'type' => 'number',
                'readonly' => false
            ],
            'ir_auto_vincular_requiere_accno_exacto' => [
                'key' => 'ir_auto_vincular_requiere_accno_exacto',
                'value' => $getConfigValue('ir_auto_vincular_requiere_accno_exacto', '1'),
                'description' => 'Requerir coincidencia exacta de ACCNO para auto-vincular.',
                'category' => 'informes_recibidos',
                'type' => 'select',
                'options' => ['0' => 'No', '1' => 'Sí'],
                'readonly' => false
            ],
            'ir_auto_vincular_requiere_patient_id' => [
                'key' => 'ir_auto_vincular_requiere_patient_id',
                'value' => $getConfigValue('ir_auto_vincular_requiere_patient_id', '0'),
                'description' => 'Requerir coincidencia de Patient ID para auto-vincular.',
                'category' => 'informes_recibidos',
                'type' => 'select',
                'options' => ['0' => 'No', '1' => 'Sí'],
                'readonly' => false
            ],
            'ir_auto_vincular_en_pacs' => [
                'key' => 'ir_auto_vincular_en_pacs',
                'value' => $getConfigValue('ir_auto_vincular_en_pacs', 'bloquear'),
                'description' => 'Qué hacer si el informe objetivo ya está en PACS (bloquear o permitir).',
                'category' => 'informes_recibidos',
                'type' => 'select',
                'options' => ['bloquear' => 'Bloquear', 'permitir' => 'Permitir'],
                'readonly' => false
            ],
            'ir_auto_enviar_pacs_activo' => [
                'key' => 'ir_auto_enviar_pacs_activo',
                'value' => $getConfigValue('ir_auto_enviar_pacs_activo', '0'),
                'description' => 'Tras vincular un informe recibido, enviar automáticamente el informe a PACS (Orthanc).',
                'category' => 'informes_recibidos',
                'type' => 'select',
                'options' => ['0' => 'No', '1' => 'Sí'],
                'readonly' => false
            ],
            'ir_pdf_metadata_title' => [
                'key' => 'ir_pdf_metadata_title',
                'value' => $getConfigValue('ir_pdf_metadata_title', ''),
                'description' => 'Texto para el metadato PDF Título (propiedades del documento) al ingerir PDFs por API, carpetas o adjunto. Vacío = no modificar. Requiere exiftool en el servidor (p. ej. paquete libimage-exiftool-perl).',
                'category' => 'informes_recibidos',
                'type' => 'text',
                'readonly' => false
            ],
            'ir_pdf_metadata_author' => [
                'key' => 'ir_pdf_metadata_author',
                'value' => $getConfigValue('ir_pdf_metadata_author', ''),
                'description' => 'Texto para el metadato PDF Autor (propiedades del documento) al ingerir PDFs por API, carpetas o adjunto. Vacío = no modificar. Requiere exiftool en el servidor (p. ej. paquete libimage-exiftool-perl).',
                'category' => 'informes_recibidos',
                'type' => 'text',
                'readonly' => false
            ],
            'ir_modalidades_excluidas' => [
                'key' => 'ir_modalidades_excluidas',
                'value' => $getConfigValue('ir_modalidades_excluidas', 'DMO,US'),
                'description' => 'Modalidades excluidas de búsqueda automática en PACS (separadas por coma, ej: DMO,US). Los informes recibidos con estas modalidades se marcarán como pendiente_sin_pacs.',
                'category' => 'informes_recibidos',
                'type' => 'text',
                'readonly' => false
            ],
            'ic_activo' => [
                'key' => 'ic_activo',
                'value' => $getConfigValue('ic_activo', '0'),
                'description' => 'Activar ingesta desde carpetas PDF/TXT (SMB). Al desactivar, el worker y el poll ignorarán los archivos nuevos.',
                'category' => 'informes_recibidos',
                'type' => 'select',
                'options' => ['0' => 'No', '1' => 'Sí'],
                'readonly' => false
            ],
            'ic_pdf_path' => [
                'key' => 'ic_pdf_path',
                'value' => $getConfigValue('ic_pdf_path', '/var/www/tjsiddse/uploads/informespdf_net'),
                'description' => 'Carpeta montada (SMB) con informes PDF. Sugerido: /var/www/tjsiddse/uploads/informespdf_net — nombres idpaciente_numeroestudio.pdf',
                'category' => 'informes_recibidos',
                'type' => 'text',
                'readonly' => false
            ],
            'ic_txt_path' => [
                'key' => 'ic_txt_path',
                'value' => $getConfigValue('ic_txt_path', '/var/www/tjsiddse/uploads/mensajesris_net'),
                'description' => 'Carpeta montada (SMB) con TXT DicomData. Sugerido: /var/www/tjsiddse/uploads/mensajesris_net — nombres idpaciente_accno.txt',
                'category' => 'informes_recibidos',
                'type' => 'text',
                'readonly' => false
            ],
        ];
    }

    if (!$category || $category === 'estudios_recibidos') {
        $configs['estudios_recibidos'] = [
            'sla_activo' => [
                'key' => 'sla_activo',
                'value' => $getConfigValue('sla_activo', '0'),
                'description' => 'Activar monitoreo SLA de publicación de informes (0=apagado, sin contadores/modal). El webhook puede seguir registrando llegada local.',
                'category' => 'estudios_recibidos',
                'type' => 'select',
                'options' => ['0' => 'No (apagado)', '1' => 'Sí (activo)'],
                'readonly' => false
            ],
            'sla_default_horas' => [
                'key' => 'sla_default_horas',
                'value' => $getConfigValue('sla_default_horas', '72'),
                'description' => 'Horas por defecto desde llegada a PACS local hasta publicación del informe en PACS',
                'category' => 'estudios_recibidos',
                'type' => 'number',
                'readonly' => false
            ],
            'sla_warning_horas' => [
                'key' => 'sla_warning_horas',
                'value' => $getConfigValue('sla_warning_horas', '24'),
                'description' => 'Horas antes del vencimiento para aviso «por vencer»',
                'category' => 'estudios_recibidos',
                'type' => 'number',
                'readonly' => false
            ],
            'sla_webhook_secret' => [
                'key' => 'sla_webhook_secret',
                'value' => $getConfigValue('sla_webhook_secret', ''),
                'description' => 'Token Bearer para Orthanc OnStableStudy → api/estudios/local-arrived-webhook.php (vacío = webhook deshabilitado)',
                'category' => 'estudios_recibidos',
                'type' => 'password',
                'readonly' => false
            ],
        ];
    }

    if (!$category || $category === 'gasalud_envio') {
        $configs['gasalud_envio'] = [
            'gasalud_envio_activo' => [
                'key' => 'gasalud_envio_activo',
                'value' => $getConfigValue('gasalud_envio_activo', '0'),
                'description' => 'Activar envío de informes PDF hacia Gasalud. Solo informes generados en la plataforma (origen≠externo). 0 = apagado.',
                'category' => 'gasalud_envio',
                'type' => 'select',
                'options' => ['0' => 'No (apagado)', '1' => 'Sí (activo)'],
                'readonly' => false
            ],
            'gasalud_api_url' => [
                'key' => 'gasalud_api_url',
                'value' => $getConfigValue('gasalud_api_url', 'http://192.168.0.149:8325/api/v2/Informes'),
                'description' => 'URL POST multipart Informes (Swagger: /api/v2/Informes). Campo archivo = Archivo.',
                'category' => 'gasalud_envio',
                'type' => 'text',
                'readonly' => false
            ],
            'gasalud_login_url' => [
                'key' => 'gasalud_login_url',
                'value' => $getConfigValue('gasalud_login_url', 'http://192.168.0.149:8325/api/v2/Usuarios/Login'),
                'description' => 'URL login JSON (nombreUsuario/password → token). Usado si auth = Login API.',
                'category' => 'gasalud_envio',
                'type' => 'text',
                'readonly' => false
            ],
            'gasalud_http_method' => [
                'key' => 'gasalud_http_method',
                'value' => $getConfigValue('gasalud_http_method', 'POST'),
                'description' => 'Método HTTP del endpoint Informes',
                'category' => 'gasalud_envio',
                'type' => 'select',
                'options' => ['POST' => 'POST', 'PUT' => 'PUT'],
                'readonly' => false
            ],
            'gasalud_auth_mode' => [
                'key' => 'gasalud_auth_mode',
                'value' => $getConfigValue('gasalud_auth_mode', 'login'),
                'description' => 'login = Usuarios/Login + Bearer; bearer/api_key/basic/none = modos alternativos',
                'category' => 'gasalud_envio',
                'type' => 'select',
                'options' => [
                    'login' => 'Login API (recomendado Gasalud)',
                    'bearer' => 'Bearer token fijo',
                    'api_key' => 'API Key (header)',
                    'basic' => 'Basic (usuario/clave)',
                    'none' => 'Sin autenticación',
                ],
                'readonly' => false
            ],
            'gasalud_auth_username' => [
                'key' => 'gasalud_auth_username',
                'value' => $getConfigValue('gasalud_auth_username', ''),
                'description' => 'Usuario Login (nombreUsuario) o Basic Auth',
                'category' => 'gasalud_envio',
                'type' => 'text',
                'readonly' => false
            ],
            'gasalud_auth_password' => [
                'key' => 'gasalud_auth_password',
                'value' => $getConfigValue('gasalud_auth_password', ''),
                'description' => 'Password Login o Basic Auth (guardado en BD)',
                'category' => 'gasalud_envio',
                'type' => 'password',
                'readonly' => false
            ],
            'gasalud_auth_token' => [
                'key' => 'gasalud_auth_token',
                'value' => $getConfigValue('gasalud_auth_token', ''),
                'description' => 'Token Bearer (cache automático en modo login; o token fijo en modo bearer)',
                'category' => 'gasalud_envio',
                'type' => 'password',
                'readonly' => false
            ],
            'gasalud_token_expires_at' => [
                'key' => 'gasalud_token_expires_at',
                'value' => $getConfigValue('gasalud_token_expires_at', ''),
                'description' => 'Expiración del token cacheado (solo lectura operativa; se actualiza en login)',
                'category' => 'gasalud_envio',
                'type' => 'text',
                'readonly' => false
            ],
            'gasalud_api_key_header' => [
                'key' => 'gasalud_api_key_header',
                'value' => $getConfigValue('gasalud_api_key_header', 'X-API-Key'),
                'description' => 'Nombre del header cuando auth_mode = api_key',
                'category' => 'gasalud_envio',
                'type' => 'text',
                'readonly' => false
            ],
            'gasalud_tipo_default' => [
                'key' => 'gasalud_tipo_default',
                'value' => $getConfigValue('gasalud_tipo_default', 'pdf'),
                'description' => 'Campo Tipo (solo enviamos PDF → pdf)',
                'category' => 'gasalud_envio',
                'type' => 'text',
                'readonly' => false
            ],
            'gasalud_prestador_modo' => [
                'key' => 'gasalud_prestador_modo',
                'value' => $getConfigValue('gasalud_prestador_modo', 'worklist'),
                'description' => 'Prestador: matrícula/ID de PV1 guardada en worklist.referring_physician (HL7), o matrícula/ID de usuario',
                'category' => 'gasalud_envio',
                'type' => 'select',
                'options' => [
                    'worklist' => 'Worklist / HL7 PV1 (matrícula)',
                    'matricula' => 'Matrícula del usuario firmante',
                    'user_id' => 'ID usuario plataforma',
                ],
                'readonly' => false
            ],
            'gasalud_trigger' => [
                'key' => 'gasalud_trigger',
                'value' => $getConfigValue('gasalud_trigger', 'al_pacs'),
                'description' => 'Cuándo enviar PDF de informes de plataforma (nunca API recibidos)',
                'category' => 'gasalud_envio',
                'type' => 'select',
                'options' => [
                    'manual' => 'Solo manual (API/botón)',
                    'al_finalizado' => 'Al pasar a Finalizado (si hay PDF)',
                    'al_pacs' => 'Tras publicar en PACS (recomendado: PDF ya generado)',
                ],
                'readonly' => false
            ],
            'gasalud_timeout_sec' => [
                'key' => 'gasalud_timeout_sec',
                'value' => $getConfigValue('gasalud_timeout_sec', '60'),
                'description' => 'Timeout HTTP en segundos',
                'category' => 'gasalud_envio',
                'type' => 'number',
                'readonly' => false
            ],
            'gasalud_verify_ssl' => [
                'key' => 'gasalud_verify_ssl',
                'value' => $getConfigValue('gasalud_verify_ssl', '0'),
                'description' => 'Verificar certificado SSL (en LAN http suele ser No)',
                'category' => 'gasalud_envio',
                'type' => 'select',
                'options' => ['1' => 'Sí', '0' => 'No'],
                'readonly' => false
            ],
        ];
    }
    
    return $configs;
}

/**
 * Guardar una configuración
 */
function saveConfiguration($db, $key, $value, $description = null) {
    // Validar que key y value no estén vacíos
    if (empty($key)) {
        throw new Exception('La clave de configuración no puede estar vacía');
    }
    
    // Permitir valores vacíos pero convertir null a string vacío
    $value = $value === null ? '' : (string)$value;
    
    // Si se está eliminando el logo (app_logo con valor vacío), eliminar el archivo físico
    if ($key === 'app_logo' && empty($value)) {
        $stmt = $db->prepare("SELECT valor FROM configuracion WHERE clave = 'app_logo'");
        $stmt->execute();
        $oldLogo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($oldLogo && $oldLogo['valor']) {
            $oldLogoPath = __DIR__ . '/../../' . $oldLogo['valor'];
            if (file_exists($oldLogoPath)) {
                @unlink($oldLogoPath);
            }
        }
    }
    
    // Obtener descripción actual si no se proporciona
    if ($description === null) {
        $stmt = $db->prepare("SELECT descripcion FROM configuracion WHERE clave = ?");
        $stmt->execute([$key]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        $description = $existing ? $existing['descripcion'] : null;
    }
    
    // Verificar si ya existe
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM configuracion WHERE clave = ?");
    $stmt->execute([$key]);
    $exists = $stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;
    
    if ($exists) {
        if ($description) {
            $stmt = $db->prepare("UPDATE configuracion SET valor = ?, descripcion = ? WHERE clave = ?");
            $stmt->execute([$value, $description, $key]);
        } else {
            $stmt = $db->prepare("UPDATE configuracion SET valor = ? WHERE clave = ?");
            $stmt->execute([$value, $key]);
        }
    } else {
        $stmt = $db->prepare("INSERT INTO configuracion (clave, valor, descripcion) VALUES (?, ?, ?)");
        $stmt->execute([$key, $value, $description]);
    }
    
    // Si es una configuración de base de datos o PACS, también actualizar el archivo correspondiente
    if (strpos($key, 'db_') === 0) {
        updateDatabaseConfig($key, $value);
    } elseif (strpos($key, 'pacs_') === 0) {
        updatePacsConfig($key, $value);
    }
}

/**
 * Actualizar configuración de base de datos en archivo
 */
function updateDatabaseConfig($key, $value) {
    // Limpiar cache de Database para que recargue desde BD
    if (class_exists('Database')) {
        Database::clearCache();
    }
    error_log("Configuración de BD actualizada: $key = $value (cache limpiado, se recargará desde BD)");
}

/**
 * Actualizar configuración de PACS en archivo
 */
function updatePacsConfig($key, $value) {
    // Limpiar cache de OrthancConfig para que recargue desde BD
    if (class_exists('OrthancConfig')) {
        OrthancConfig::clearCache();
    }
    error_log("Configuración de PACS actualizada: $key = $value (cache limpiado)");
}

?>

