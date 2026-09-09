<?php
/**
 * Interfaz de Administración del Módulo de Email
 * 
 * Interfaz web completa para gestionar la configuración del módulo de email.
 * Permite configurar SMTP, probar conexión, gestionar eventos y ver logs.
 * 
 * @package EmailModule
 * @version 1.0
 */

// Configurar manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/admin_errors.log');

// Log inicial para debugging
error_log('═══════════════════════════════════════════════════════════════');
error_log('🔍 [admin.php] INICIO - Request Method: ' . ($_SERVER['REQUEST_METHOD'] ?? 'NO SET'));
error_log('🔍 [admin.php] Action: ' . ($_GET['action'] ?? 'NO SET'));
error_log('🔍 [admin.php] POST data: ' . (isset($_POST) && !empty($_POST) ? json_encode($_POST) : 'EMPTY'));

// Verificar autenticación básica (se puede mejorar con el sistema de autenticación del proyecto)
session_start();

// Verificar permisos de administración de email
require_once __DIR__ . '/../../config/database.php';

function checkEmailAdminPermission() {
    // Intentar usar el sistema de autenticación del proyecto si está disponible
    $userClassPaths = [
        __DIR__ . '/../../classes/User.php',
        __DIR__ . '/../../../classes/User.php'
    ];
    
    foreach ($userClassPaths as $path) {
        if (file_exists($path)) {
            require_once $path;
            if (class_exists('User')) {
                try {
                    $user = new User();
                    $token = $_COOKIE['session_token'] ?? null;
                    
                    if ($token) {
                        $user_data = $user->validateSession($token);
                        if ($user_data && is_array($user_data)) {
                            // ROOT tiene todos los permisos
                            if (isset($user_data['nivel']) && $user_data['nivel'] === 'root') {
                                return true;
                            }
                            
                            // Verificar permiso específico
                            $permissions = [];
                            if (isset($user_data['permisos'])) {
                                if (is_string($user_data['permisos'])) {
                                    $permissions = json_decode($user_data['permisos'], true) ?: [];
                                } elseif (is_array($user_data['permisos'])) {
                                    $permissions = $user_data['permisos'];
                                }
                            }
                            
                            return in_array('administracion_email', $permissions) || in_array('all', $permissions);
                        }
                    }
                } catch (Exception $e) {
                    error_log('Error usando clase User para verificar permisos: ' . $e->getMessage());
                }
            }
            break;
        }
    }
    
    // Fallback: Verificar sesión directamente
    if (!isset($_SESSION['user_id']) && !isset($_COOKIE['session_token'])) {
        return false;
    }
    
    try {
        $pdo = getDBConnection();
        if (!$pdo) {
            return false;
        }
        
        $token = $_COOKIE['session_token'] ?? null;
        $user = null;
        
        if (!$token && isset($_SESSION['user_id'])) {
            // Si hay sesión pero no cookie, verificar directamente por user_id
            $query = "SELECT u.id, u.nivel, u.permisos, u.activo 
                      FROM usuarios u 
                      WHERE u.id = ? AND u.activo = 1";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } elseif ($token) {
            // Verificar por token de sesión - intentar ambas tablas posibles
            // Primero intentar con 'sesiones' (tabla del sistema)
            $query = "SELECT u.id, u.nivel, u.permisos, u.activo 
                      FROM usuarios u 
                      INNER JOIN sesiones s ON u.id = s.usuario_id 
                      WHERE s.token_sesion = ? AND s.activa = 1 AND s.fecha_expiracion > NOW() AND u.activo = 1";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$token]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Si no se encontró, intentar con 'user_sessions' (tabla alternativa)
            if (!$user) {
                $query = "SELECT u.id, u.nivel, u.permisos, u.activo 
                          FROM usuarios u 
                          INNER JOIN user_sessions s ON u.id = s.user_id 
                          WHERE s.session_token = ? AND s.expires_at > NOW() AND u.activo = 1";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$token]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }
        
        if (!$user) {
            return false;
        }
        
        // ROOT tiene todos los permisos
        if (isset($user['nivel']) && $user['nivel'] === 'root') {
            return true;
        }
        
        // Verificar permiso específico
        $permissions = json_decode($user['permisos'], true) ?: [];
        return in_array('administracion_email', $permissions) || in_array('all', $permissions);
        
    } catch (Exception $e) {
        error_log('Error verificando permisos de email admin: ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        return false;
    }
}

/**
 * Obtener ID del usuario actual
 * @return int|null ID del usuario o null si no se puede obtener
 */
function getCurrentUserId() {
    // Intentar obtener desde sesión
    if (isset($_SESSION['user_id'])) {
        return (int)$_SESSION['user_id'];
    }
    
    // Intentar obtener desde token de sesión
    $token = $_COOKIE['session_token'] ?? null;
    if ($token) {
        try {
            require_once __DIR__ . '/../../config/database.php';
            $pdo = getDBConnection();
            
            if ($pdo) {
                // Intentar con tabla 'sesiones'
                $query = "SELECT u.id FROM usuarios u 
                         INNER JOIN sesiones s ON u.id = s.usuario_id 
                         WHERE s.token_sesion = ? AND s.activa = 1 AND s.fecha_expiracion > NOW() AND u.activo = 1";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$token]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user) {
                    return (int)$user['id'];
                }
                
                // Intentar con tabla 'user_sessions'
                $query = "SELECT u.id FROM usuarios u 
                         INNER JOIN user_sessions s ON u.id = s.user_id 
                         WHERE s.session_token = ? AND s.expires_at > NOW() AND u.activo = 1";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$token]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user) {
                    return (int)$user['id'];
                }
            }
        } catch (Exception $e) {
            error_log('Error obteniendo ID de usuario: ' . $e->getMessage());
        }
    }
    
    return null;
}

/**
 * Obtener datos completos del usuario actual (incluyendo nivel)
 * @return array|null Datos del usuario o null si no se encuentra
 */
function getCurrentUserData() {
    // Intentar obtener desde sesión
    if (isset($_SESSION['user_id'])) {
        try {
            require_once __DIR__ . '/../../config/database.php';
            $pdo = getDBConnection();
            if ($pdo) {
                $query = "SELECT id, nivel, permisos, activo FROM usuarios WHERE id = ? AND activo = 1";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$_SESSION['user_id']]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($user) {
                    return $user;
                }
            }
        } catch (Exception $e) {
            error_log('Error obteniendo datos de usuario desde sesión: ' . $e->getMessage());
        }
    }
    
    // Intentar obtener desde token de sesión
    $token = $_COOKIE['session_token'] ?? null;
    if ($token) {
        try {
            require_once __DIR__ . '/../../config/database.php';
            $pdo = getDBConnection();
            
            if ($pdo) {
                // Intentar con tabla 'sesiones'
                $query = "SELECT u.id, u.nivel, u.permisos, u.activo 
                         FROM usuarios u 
                         INNER JOIN sesiones s ON u.id = s.usuario_id 
                         WHERE s.token_sesion = ? AND s.activa = 1 AND s.fecha_expiracion > NOW() AND u.activo = 1";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$token]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user) {
                    return $user;
                }
                
                // Intentar con tabla 'user_sessions'
                $query = "SELECT u.id, u.nivel, u.permisos, u.activo 
                         FROM usuarios u 
                         INNER JOIN user_sessions s ON u.id = s.user_id 
                         WHERE s.session_token = ? AND s.expires_at > NOW() AND u.activo = 1";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$token]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user) {
                    return $user;
                }
            }
        } catch (Exception $e) {
            error_log('Error obteniendo datos de usuario desde token: ' . $e->getMessage());
        }
    }
    
    return null;
}

// Iniciar output buffering para evitar problemas con redirecciones
if (!ob_get_level()) {
    ob_start();
}

// Verificar permisos antes de continuar
$hasPermission = checkEmailAdminPermission();

// Modo debug: mostrar información de diagnóstico
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    error_log('=== DEBUG admin.php ===');
    error_log('SESSION user_id: ' . ($_SESSION['user_id'] ?? 'NO SET'));
    error_log('COOKIE session_token: ' . (isset($_COOKIE['session_token']) ? 'SET (' . substr($_COOKIE['session_token'], 0, 10) . '...)' : 'NO SET'));
    error_log('Has Permission: ' . ($hasPermission ? 'YES' : 'NO'));
    
    // Intentar obtener información del usuario
    try {
        $pdo = getDBConnection();
        if ($pdo) {
            if (isset($_SESSION['user_id'])) {
                $query = "SELECT id, nombre, apellido, nivel, activo FROM usuarios WHERE id = ?";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$_SESSION['user_id']]);
                $userInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($userInfo) {
                    error_log('User from SESSION: ' . json_encode($userInfo));
                }
            }
            if (isset($_COOKIE['session_token'])) {
                $query = "SELECT u.id, u.nombre, u.apellido, u.nivel, u.activo 
                          FROM usuarios u 
                          INNER JOIN sesiones s ON u.id = s.usuario_id 
                          WHERE s.token_sesion = ? AND s.activa = 1";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$_COOKIE['session_token']]);
                $userInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($userInfo) {
                    error_log('User from COOKIE: ' . json_encode($userInfo));
                } else {
                    error_log('No se encontró usuario con ese token en sesiones');
                }
            }
        }
    } catch (Exception $e) {
        error_log('Error en debug: ' . $e->getMessage());
    }
}

if (!$hasPermission) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Acceso Denegado - Administración Email</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <style>
            body {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            }
            .access-denied-card {
                background: white;
                border-radius: 15px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.2);
                padding: 40px;
                max-width: 500px;
                text-align: center;
            }
            .access-denied-icon {
                font-size: 80px;
                color: #dc3545;
                margin-bottom: 20px;
            }
            .access-denied-title {
                color: #333;
                font-size: 28px;
                font-weight: bold;
                margin-bottom: 15px;
            }
            .access-denied-message {
                color: #666;
                font-size: 16px;
                margin-bottom: 30px;
                line-height: 1.6;
            }
            .btn-back {
                background: #667eea;
                color: white;
                border: none;
                padding: 12px 30px;
                border-radius: 8px;
                font-size: 16px;
                text-decoration: none;
                display: inline-block;
                transition: background 0.3s;
            }
            .btn-back:hover {
                background: #5568d3;
                color: white;
            }
        </style>
    </head>
    <body>
        <div class="access-denied-card">
            <div class="access-denied-icon">
                <i class="fas fa-lock"></i>
            </div>
            <h1 class="access-denied-title">Acceso Denegado</h1>
            <p class="access-denied-message">
                No tienes permisos para acceder a la <strong>Administración de Email</strong>.<br>
                Contacta al administrador del sistema para solicitar el permiso <strong>"Administración email"</strong>.
            </p>
            <a href="../../dashboard-unified.html" class="btn-back">
                <i class="fas fa-arrow-left me-2"></i>Volver al Dashboard
            </a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Cargar clases
require_once __DIR__ . '/EmailConfig.php';
require_once __DIR__ . '/EmailTemplate.php';

// Verificar si PHPMailer está disponible
$phpmailerAvailable = false;
$phpmailerError = '';

// Intentar cargar PHPMailer
$phpmailerPaths = [
    __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php',
    __DIR__ . '/../../vendor/phpmailer/phpmailer/src/PHPMailer.php'
];

foreach ($phpmailerPaths as $path) {
    if (file_exists($path)) {
        require_once dirname($path) . '/PHPMailer.php';
        require_once dirname($path) . '/SMTP.php';
        require_once dirname($path) . '/Exception.php';
        $phpmailerAvailable = true;
        break;
    }
}

if (!$phpmailerAvailable) {
    // Intentar cargar desde autoload de Composer
    $composerAutoloads = [
        __DIR__ . '/vendor/autoload.php',
        __DIR__ . '/../../vendor/autoload.php'
    ];
    
    foreach ($composerAutoloads as $autoload) {
        if (file_exists($autoload)) {
            require_once $autoload;
            if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
                $phpmailerAvailable = true;
                break;
            }
        }
    }
}

if ($phpmailerAvailable) {
    require_once __DIR__ . '/EmailService.php';
    require_once __DIR__ . '/EmailEventManager.php';
} else {
    $phpmailerError = 'PHPMailer no está instalado. Ejecuta: composer install';
}

// Procesar acciones
$action = $_GET['action'] ?? 'dashboard';
$message = '';
$messageType = '';

// Recuperar mensajes de sesión (para mensajes flash después de redirección)
if (isset($_SESSION['email_admin_message'])) {
    $message = $_SESSION['email_admin_message'];
    $messageType = $_SESSION['email_admin_message_type'] ?? 'success';
    error_log('🔍 [admin.php] Mensaje flash recuperado: ' . $message . ' (tipo: ' . $messageType . ')');
    unset($_SESSION['email_admin_message']);
    unset($_SESSION['email_admin_message_type']);
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log('🔍 [admin.php] POST recibido - Action: ' . $action);
    error_log('🔍 [admin.php] POST data: ' . json_encode($_POST));
    
    if ($action === 'save-whatsapp-config') {
        // Guardar configuración de WhatsApp
        try {
            require_once __DIR__ . '/../whatsapp/WhatsAppConfig.php';
            
            $config = [
                'waha' => [
                    'base_url' => !empty($_POST['waha_base_url']) ? trim($_POST['waha_base_url']) : 'http://localhost:3000',
                    'api_key' => !empty($_POST['waha_api_key']) ? trim($_POST['waha_api_key']) : '',
                    'timeout' => 30,
                    'default_session' => !empty($_POST['waha_default_session']) ? trim($_POST['waha_default_session']) : 'default',
                    'default_country_code' => '54'
                ],
                'options' => [
                    'log_errors' => true,
                    'log_file' => __DIR__ . '/../whatsapp/logs/whatsapp.log'
                ]
            ];
            
            WhatsAppConfig::save($config);
            
            $message = 'Configuración de WhatsApp guardada correctamente';
            $messageType = 'success';
            
        } catch (Exception $e) {
            error_log('Error guardando configuración WhatsApp: ' . $e->getMessage());
            $message = 'Error al guardar configuración: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
    
    if ($action === 'save-my-whatsapp-config') {
        // Guardar configuración personal de WhatsApp del usuario
        try {
            $userId = getCurrentUserId();
            
            if (!$userId) {
                throw new Exception('No se pudo obtener el ID del usuario');
            }
            
            // Verificar permisos
            $userClassPaths = [
                __DIR__ . '/../../classes/User.php',
                __DIR__ . '/../../../classes/User.php'
            ];
            
            $hasPermission = false;
            foreach ($userClassPaths as $path) {
                if (file_exists($path)) {
                    require_once $path;
                    if (class_exists('User')) {
                        $user = new User();
                        $token = $_COOKIE['session_token'] ?? null;
                        if ($token) {
                            $user_data = $user->validateSession($token);
                            if ($user_data && is_array($user_data)) {
                                if (isset($user_data['nivel']) && $user_data['nivel'] === 'root') {
                                    $hasPermission = true;
                                } else {
                                    $permissions = [];
                                    if (isset($user_data['permisos'])) {
                                        $permissions = is_string($user_data['permisos']) 
                                            ? json_decode($user_data['permisos'], true) 
                                            : $user_data['permisos'];
                                    }
                                    $hasPermission = in_array('administracion_whatsapp', $permissions) 
                                                 || in_array('all', $permissions);
                                }
                            }
                        }
                        break;
                    }
                }
            }
            
            if (!$hasPermission) {
                throw new Exception('No tienes permisos para configurar WhatsApp personal');
            }
            
            require_once __DIR__ . '/../whatsapp/WhatsAppConfig.php';
            
            $config = [
                'base_url' => !empty($_POST['waha_base_url']) ? trim($_POST['waha_base_url']) : '',
                'api_key' => !empty($_POST['waha_api_key']) ? trim($_POST['waha_api_key']) : '',
                'timeout' => !empty($_POST['waha_timeout']) ? intval($_POST['waha_timeout']) : 30,
                'default_session' => !empty($_POST['waha_default_session']) ? trim($_POST['waha_default_session']) : 'default',
                'default_country_code' => !empty($_POST['waha_default_country_code']) ? trim($_POST['waha_default_country_code']) : '54'
            ];
            
            if (empty($config['base_url'])) {
                throw new Exception('La URL base de WAHA es requerida');
            }
            
            if (WhatsAppConfig::saveForUser($userId, $config)) {
                $message = 'Tu configuración personal de WAHA ha sido guardada exitosamente';
                $messageType = 'success';
            } else {
                throw new Exception('Error al guardar la configuración personal');
            }
            
        } catch (Exception $e) {
            error_log('Error guardando configuración personal WhatsApp: ' . $e->getMessage());
            $message = 'Error al guardar tu configuración: ' . $e->getMessage();
            $messageType = 'error';
        } catch (Error $e) {
            error_log('Error fatal guardando configuración WhatsApp: ' . $e->getMessage());
            $message = 'Error fatal al guardar configuración: ' . $e->getMessage();
            $messageType = 'error';
        }
        
        $_SESSION['email_admin_message'] = $message;
        $_SESSION['email_admin_message_type'] = $messageType;
        if (ob_get_level()) {
            ob_end_clean();
        }
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?tab=whatsapp&saved=1');
        exit;
        
    } elseif ($action === 'delete-my-whatsapp-config') {
        // Eliminar configuración personal de WhatsApp del usuario
        try {
            $userId = getCurrentUserId();
            
            if (!$userId) {
                throw new Exception('No se pudo obtener el ID del usuario');
            }
            
            // Verificar permisos
            $userClassPaths = [
                __DIR__ . '/../../classes/User.php',
                __DIR__ . '/../../../classes/User.php'
            ];
            
            $hasPermission = false;
            foreach ($userClassPaths as $path) {
                if (file_exists($path)) {
                    require_once $path;
                    if (class_exists('User')) {
                        $user = new User();
                        $token = $_COOKIE['session_token'] ?? null;
                        if ($token) {
                            $user_data = $user->validateSession($token);
                            if ($user_data && is_array($user_data)) {
                                if (isset($user_data['nivel']) && $user_data['nivel'] === 'root') {
                                    $hasPermission = true;
                                } else {
                                    $permissions = [];
                                    if (isset($user_data['permisos'])) {
                                        $permissions = is_string($user_data['permisos']) 
                                            ? json_decode($user_data['permisos'], true) 
                                            : $user_data['permisos'];
                                    }
                                    $hasPermission = in_array('administracion_whatsapp', $permissions) 
                                                 || in_array('all', $permissions);
                                }
                            }
                        }
                        break;
                    }
                }
            }
            
            if (!$hasPermission) {
                throw new Exception('No tienes permisos para eliminar tu configuración de WhatsApp');
            }
            
            require_once __DIR__ . '/../../config/database.php';
            $pdo = getDBConnection();
            
            if ($pdo) {
                // Desactivar la configuración en lugar de eliminarla
                $query = "UPDATE user_whatsapp_config SET activo = 0 WHERE usuario_id = ?";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$userId]);
                
                $message = 'Tu configuración personal de WAHA ha sido eliminada. Se usará la configuración global.';
                $messageType = 'success';
            } else {
                throw new Exception('Error de conexión a la base de datos');
            }
            
        } catch (Exception $e) {
            error_log('Error eliminando configuración personal WhatsApp: ' . $e->getMessage());
            $message = 'Error al eliminar tu configuración: ' . $e->getMessage();
            $messageType = 'error';
        } catch (Error $e) {
            error_log('Error fatal eliminando configuración personal WhatsApp: ' . $e->getMessage());
            $message = 'Error fatal al eliminar tu configuración: ' . $e->getMessage();
            $messageType = 'error';
        }
        
        $_SESSION['email_admin_message'] = $message;
        $_SESSION['email_admin_message_type'] = $messageType;
        if (ob_get_level()) {
            ob_end_clean();
        }
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?tab=whatsapp&deleted=1');
        exit;
        
    } elseif ($action === 'save-config') {
        error_log('🔍 [admin.php] Procesando save-config...');
        // Inicializar variables de mensaje en este scope
        $saveMessage = '';
        $saveMessageType = '';
        
        try {
            // Cargar configuración actual para preservar valores que no se están cambiando
            try {
                $currentConfig = EmailConfig::load();
                $currentSmtp = $currentConfig->getSmtpConfig();
            } catch (Exception $e) {
                error_log('🚨 [admin.php] Error cargando configuración actual: ' . $e->getMessage());
                throw $e;
            }
        
        // Guardar configuración SMTP
        // IMPORTANTE: Si la contraseña está vacía, mantener la actual
        $newPassword = !empty($_POST['smtp_password']) ? $_POST['smtp_password'] : ($currentSmtp['password'] ?? '');
        error_log('🔍 [admin.php] Password: ' . ($newPassword ? 'SET (nueva o preservada)' : 'EMPTY'));
        
        $config = [
            'app_name' => !empty($_POST['app_name']) ? trim($_POST['app_name']) : 'TJS Medical - Portal de Estudios',
            'smtp' => [
                'host' => !empty($_POST['smtp_host']) ? trim($_POST['smtp_host']) : ($currentSmtp['host'] ?? 'smtp.gmail.com'),
                'port' => !empty($_POST['smtp_port']) ? (int)$_POST['smtp_port'] : ($currentSmtp['port'] ?? 587),
                'secure' => !empty($_POST['smtp_secure']) ? $_POST['smtp_secure'] : ($currentSmtp['secure'] ?? 'tls'),
                'username' => !empty($_POST['smtp_username']) ? trim($_POST['smtp_username']) : ($currentSmtp['username'] ?? ''),
                'password' => $newPassword, // Mantener contraseña actual si está vacía
                'from_email' => !empty($_POST['from_email']) ? trim($_POST['from_email']) : ($currentSmtp['from_email'] ?? 'noreply@tjsmedical.com'),
                'from_name' => !empty($_POST['from_name']) ? trim($_POST['from_name']) : ($currentSmtp['from_name'] ?? 'TJS Medical - Portal de Estudios')
            ],
            'options' => [
                'charset' => 'UTF-8',
                'debug' => isset($_POST['debug']) && $_POST['debug'] === '1',
                'log_errors' => true,
                'log_file' => __DIR__ . '/logs/email.log'
            ]
        ];
        
        // Preservar branding si existe en la configuración actual
        $currentOptions = $currentConfig->getOptions();
        if (method_exists($currentConfig, 'get') && $currentConfig->get('branding')) {
            $config['branding'] = $currentConfig->get('branding');
        }
        
        // Log para debugging
        error_log('Guardando configuración SMTP - Host: ' . $config['smtp']['host']);
        error_log('Guardando configuración SMTP - Username: ' . ($config['smtp']['username'] ? 'SET' : 'EMPTY'));
        error_log('Guardando configuración SMTP - Password: ' . ($config['smtp']['password'] ? 'SET (preservada o nueva)' : 'EMPTY'));
        
        // Determinar si se guarda como configuración global o por usuario
        // IMPORTANTE: Usuarios 'root' siempre guardan como configuración global
        $currentUserData = getCurrentUserData();
        $currentUserId = $currentUserData ? (int)$currentUserData['id'] : null;
        $isRootUser = $currentUserData && isset($currentUserData['nivel']) && $currentUserData['nivel'] === 'root';
        
        $saveAsUserConfig = !$isRootUser && isset($_POST['save_as_user_config']) && $_POST['save_as_user_config'] === '1';
        
        error_log('🔍 [admin.php] Usuario es ROOT: ' . ($isRootUser ? 'SÍ' : 'NO'));
        error_log('🔍 [admin.php] Guardar como configuración de usuario: ' . ($saveAsUserConfig ? 'SÍ' : 'NO'));
        error_log('🔍 [admin.php] ID de usuario actual: ' . ($currentUserId ?? 'NO DISPONIBLE'));
        
        if ($saveAsUserConfig && $currentUserId && !$isRootUser) {
            // Guardar configuración por usuario en la base de datos
            error_log('🔍 [admin.php] Guardando configuración SMTP para usuario ID: ' . $currentUserId);
            $saved = EmailConfig::saveForUser($currentUserId, $config['smtp']);
            
            if ($saved) {
                $saveMessage = 'Configuración SMTP guardada exitosamente para tu usuario';
                $saveMessageType = 'success';
                error_log('✅ [admin.php] Configuración SMTP del usuario guardada exitosamente');
            } else {
                $saveMessage = 'Error al guardar la configuración SMTP del usuario';
                $saveMessageType = 'error';
                error_log('🚨 [admin.php] Error guardando configuración SMTP del usuario');
            }
            
            // Guardar mensaje en sesión y redirigir
            $_SESSION['email_admin_message'] = $saveMessage;
            $_SESSION['email_admin_message_type'] = $saveMessageType;
            if (ob_get_level()) {
                ob_end_clean();
            }
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?tab=config&saved=1');
            exit;
        } else {
            // Guardar como configuración global (comportamiento actual)
            error_log('🔍 [admin.php] Guardando como configuración global...');
        }
        
        error_log('🔍 [admin.php] Generando contenido del archivo de configuración...');
        $configContent = "<?php\n/**\n * Configuración del Módulo de Email\n * Generado automáticamente por el administrador\n */\n\nreturn " . var_export($config, true) . ";\n";
        error_log('🔍 [admin.php] Contenido generado. Longitud: ' . strlen($configContent) . ' bytes');
        
        $configFile = __DIR__ . '/config/email_config.php';
        $configDir = dirname($configFile);
        error_log('🔍 [admin.php] Archivo de configuración: ' . $configFile);
        error_log('🔍 [admin.php] Directorio: ' . $configDir);
        
        // Verificar que el directorio existe y es escribible
        error_log('🔍 [admin.php] Verificando directorio...');
        if (!is_dir($configDir)) {
            error_log('🔍 [admin.php] Directorio no existe, intentando crear...');
            if (!mkdir($configDir, 0755, true)) {
                $saveMessage = 'Error: No se puede crear el directorio de configuración. Verifique permisos.';
                $saveMessageType = 'error';
                error_log("🚨 [admin.php] Error creando directorio: $configDir");
                $_SESSION['email_admin_message'] = $saveMessage;
                $_SESSION['email_admin_message_type'] = $saveMessageType;
                if (ob_get_level()) {
                    ob_end_clean();
                }
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?tab=config');
                exit;
            }
        }
        
        error_log('🔍 [admin.php] Directorio existe, verificando permisos...');
        error_log('🔍 [admin.php] Variable $saveMessage está ' . (isset($saveMessage) && !empty($saveMessage) ? 'SET: ' . $saveMessage : 'NO SET'));
        if (empty($saveMessage)) {
            // Verificar permisos de escritura
            error_log('🔍 [admin.php] Verificando si el directorio es escribible...');
            error_log('🔍 [admin.php] is_writable(' . $configDir . '): ' . (is_writable($configDir) ? 'TRUE' : 'FALSE'));
            if (!is_writable($configDir)) {
                $saveMessage = 'Error: El directorio de configuración no tiene permisos de escritura. Verifique permisos del directorio: ' . $configDir;
                $saveMessageType = 'error';
                error_log("🚨 [admin.php] Directorio no escribible: $configDir (permisos: " . substr(sprintf('%o', fileperms($configDir)), -4) . ")");
                $_SESSION['email_admin_message'] = $saveMessage;
                $_SESSION['email_admin_message_type'] = $saveMessageType;
                if (ob_get_level()) {
                    ob_end_clean();
                }
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?tab=config');
                exit;
            } elseif (file_exists($configFile) && !is_writable($configFile)) {
                $saveMessage = 'Error: El archivo de configuración no tiene permisos de escritura. Verifique permisos del archivo: ' . $configFile;
                $saveMessageType = 'error';
                error_log("🚨 [admin.php] Archivo no escribible: $configFile (permisos: " . substr(sprintf('%o', fileperms($configFile)), -4) . ")");
                $_SESSION['email_admin_message'] = $saveMessage;
                $_SESSION['email_admin_message_type'] = $saveMessageType;
                if (ob_get_level()) {
                    ob_end_clean();
                }
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?tab=config');
                exit;
            } else {
                // Log antes de escribir
                error_log("✅ [admin.php] Permisos OK, intentando guardar configuración en: $configFile");
                error_log("🔍 [admin.php] Host SMTP a guardar: " . $config['smtp']['host']);
                error_log("🔍 [admin.php] Username SMTP a guardar: " . ($config['smtp']['username'] ? 'SET' : 'EMPTY'));
                error_log("🔍 [admin.php] Tamaño del contenido: " . strlen($configContent) . " bytes");
                
                // Intentar escribir el archivo
                error_log("🔍 [admin.php] Llamando a file_put_contents...");
                $result = @file_put_contents($configFile, $configContent, LOCK_EX);
                error_log("🔍 [admin.php] Resultado de file_put_contents: " . ($result === false ? 'FALSE' : $result . ' bytes escritos'));
                
                if ($result === false) {
                    $lastError = error_get_last();
                    $errorMsg = $lastError ? $lastError['message'] : 'Error desconocido';
                    
                    // Obtener información del usuario actual
                    $currentUser = get_current_user();
                    $fileOwner = 'desconocido';
                    if (file_exists($configFile) && function_exists('posix_getpwuid')) {
                        $ownerInfo = posix_getpwuid(fileowner($configFile));
                        $fileOwner = $ownerInfo ? $ownerInfo['name'] : 'desconocido';
                    }
                    $filePerms = file_exists($configFile) ? substr(sprintf('%o', fileperms($configFile)), -4) : 'N/A';
                    
                    $saveMessage = 'Error al guardar la configuración. ' . 
                               'Usuario PHP: ' . $currentUser . ', ' .
                               'Propietario archivo: ' . $fileOwner . ', ' .
                               'Permisos: ' . $filePerms . '. ' .
                               'Ejecute: <code>bash modules/email/fix-config-permissions.sh</code> o ' .
                               '<code>sudo chmod 775 ' . $configFile . '</code>';
                    $saveMessageType = 'error';
                    error_log("Error escribiendo archivo: $configFile - $errorMsg - Usuario: $currentUser - Propietario: $fileOwner - Permisos: $filePerms");
                    $_SESSION['email_admin_message'] = $saveMessage;
                    $_SESSION['email_admin_message_type'] = $saveMessageType;
                    if (ob_get_level()) {
                        ob_end_clean();
                    }
                    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?tab=config');
                    exit;
                } else {
                    // Verificar que el archivo se escribió correctamente
                    $fileSize = filesize($configFile);
                    error_log("Archivo guardado exitosamente. Tamaño: $fileSize bytes. Resultado: $result bytes escritos");
                    
                    // Verificar contenido guardado
                    $savedConfig = include $configFile;
                    if (isset($savedConfig['smtp']['host'])) {
                        error_log("Host SMTP guardado: " . $savedConfig['smtp']['host']);
                    }
                    
                    $saveMessage = 'Configuración guardada exitosamente';
                    $saveMessageType = 'success';
                    
                    error_log('✅ [admin.php] Configuración guardada exitosamente - Redirigiendo...');
                    
                    // Guardar mensaje en sesión y redirigir (patrón PRG)
                    $_SESSION['email_admin_message'] = $saveMessage;
                    $_SESSION['email_admin_message_type'] = $saveMessageType;
                    
                    // Limpiar buffer de salida antes de redirigir
                    if (ob_get_level()) {
                        ob_end_clean();
                    }
                    
                    // Limpiar cache de opcache si está habilitado
                    if (function_exists('opcache_invalidate')) {
                        opcache_invalidate($configFile, true);
                    }
                    
                    $redirectUrl = strtok($_SERVER['REQUEST_URI'], '?') . '?tab=config&saved=1';
                    error_log('🔍 [admin.php] URL de redirección: ' . $redirectUrl);
                    header('Location: ' . $redirectUrl);
                    exit;
                }
            }
        } else {
            error_log('🔍 [admin.php] $saveMessage ya está definida, saltando verificación de permisos');
        }
        } catch (Exception $e) {
            error_log('🚨 [admin.php] ERROR FATAL en save-config: ' . $e->getMessage());
            error_log('🚨 [admin.php] Stack trace: ' . $e->getTraceAsString());
            $_SESSION['email_admin_message'] = 'Error al guardar configuración: ' . $e->getMessage();
            $_SESSION['email_admin_message_type'] = 'error';
            if (ob_get_level()) {
                ob_end_clean();
            }
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?tab=config');
            exit;
        } catch (Error $e) {
            error_log('🚨 [admin.php] ERROR FATAL (Error): ' . $e->getMessage());
            error_log('🚨 [admin.php] Stack trace: ' . $e->getTraceAsString());
            $_SESSION['email_admin_message'] = 'Error fatal al guardar configuración: ' . $e->getMessage();
            $_SESSION['email_admin_message_type'] = 'error';
            if (ob_get_level()) {
                ob_end_clean();
            }
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?tab=config');
            exit;
        }
    } elseif ($action === 'test-connection') {
        // Probar conexión SMTP
        // Timeouts más cortos para evitar bloqueos del servidor
        set_time_limit(20); // 20 segundos máximo
        ini_set('max_execution_time', 20);
        
        if (!$phpmailerAvailable) {
            $message = 'PHPMailer no está instalado. Ejecuta: composer install';
            $messageType = 'error';
            $_SESSION['email_admin_message'] = $message;
            $_SESSION['email_admin_message_type'] = $messageType;
            if (ob_get_level()) {
                ob_end_clean();
            }
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?tab=config');
            exit;
        } else {
            try {
                error_log('🔍 [admin.php] Iniciando prueba de conexión SMTP...');
                // Intentar cargar configuración del usuario primero (excepto root)
                $currentUserData = getCurrentUserData();
                $currentUserId = $currentUserData ? (int)$currentUserData['id'] : null;
                $isRootUser = $currentUserData && isset($currentUserData['nivel']) && $currentUserData['nivel'] === 'root';
                $config = null;
                if ($currentUserId && !$isRootUser) {
                    $config = EmailConfig::loadForUser($currentUserId);
                    if ($config) {
                        error_log('🔍 [admin.php] Usando configuración SMTP del usuario para prueba');
                    }
                }
                // Si no hay configuración de usuario, usar global
                if (!$config) {
                    $config = EmailConfig::load();
                }
                $emailService = new EmailService($config);
                $result = $emailService->testConnection();
                
                if ($result['success']) {
                    $message = 'Conexión SMTP exitosa: ' . ($result['message'] ?? '');
                    $messageType = 'success';
                    error_log('✅ [admin.php] Prueba de conexión exitosa');
                } else {
                    $message = 'Error de conexión: ' . ($result['message'] ?? 'Error desconocido');
                    $messageType = 'error';
                    error_log('🚨 [admin.php] Error en prueba de conexión: ' . $message);
                }
                
                // Guardar mensaje en sesión y redirigir
                $_SESSION['email_admin_message'] = $message;
                $_SESSION['email_admin_message_type'] = $messageType;
                if (ob_get_level()) {
                    ob_end_clean();
                }
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?tab=config');
                exit;
            } catch (Exception $e) {
                error_log('🚨 [admin.php] Excepción en prueba de conexión: ' . $e->getMessage());
                $message = 'Error al probar conexión: ' . $e->getMessage();
                $messageType = 'error';
                $_SESSION['email_admin_message'] = $message;
                $_SESSION['email_admin_message_type'] = $messageType;
                if (ob_get_level()) {
                    ob_end_clean();
                }
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?tab=config');
                exit;
            } catch (Error $e) {
                error_log('🚨 [admin.php] Error fatal en prueba de conexión: ' . $e->getMessage());
                $message = 'Error fatal al probar conexión: ' . $e->getMessage();
                $messageType = 'error';
                $_SESSION['email_admin_message'] = $message;
                $_SESSION['email_admin_message_type'] = $messageType;
                if (ob_get_level()) {
                    ob_end_clean();
                }
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?tab=config');
                exit;
            }
        }
    } elseif ($action === 'send-test') {
        // Enviar email de prueba
        // Timeouts más cortos para evitar bloqueos del servidor
        set_time_limit(30); // 30 segundos máximo
        ini_set('max_execution_time', 30);
        
        if (!$phpmailerAvailable) {
            $message = 'PHPMailer no está instalado. Ejecuta: composer install';
            $messageType = 'error';
        } else {
            try {
                // Intentar cargar configuración del usuario primero (excepto root)
                $currentUserData = getCurrentUserData();
                $currentUserId = $currentUserData ? (int)$currentUserData['id'] : null;
                $isRootUser = $currentUserData && isset($currentUserData['nivel']) && $currentUserData['nivel'] === 'root';
                $config = null;
                if ($currentUserId && !$isRootUser) {
                    $config = EmailConfig::loadForUser($currentUserId);
                    if ($config) {
                        error_log('🔍 [admin.php] Usando configuración SMTP del usuario para envío de prueba');
                    }
                }
                // Si no hay configuración de usuario, usar global
                if (!$config) {
                    $config = EmailConfig::load();
                }
                $emailService = new EmailService($config);
                
                // Configurar timeout en PHPMailer si es posible
                if (method_exists($emailService, 'setTimeout')) {
                    $emailService->setTimeout(60); // 60 segundos
                }
                
                $result = $emailService->send([
                    'to' => $_POST['test_email'] ?? '',
                    'subject' => 'Test del Módulo de Email - ' . date('Y-m-d H:i:s'),
                    'body' => '<h1>Email de Prueba</h1><p>Este es un email de prueba del módulo de email.</p><p>Fecha: ' . date('Y-m-d H:i:s') . '</p>',
                    'body_type' => 'html'
                ]);
                
                if ($result['success']) {
                    $message = 'Email de prueba enviado exitosamente a ' . $_POST['test_email'];
                    $messageType = 'success';
                } else {
                    $message = 'Error al enviar: ' . ($result['message'] ?? 'Error desconocido');
                    $messageType = 'error';
                }
                
                // Guardar mensaje en sesión y redirigir
                $_SESSION['email_admin_message'] = $message;
                $_SESSION['email_admin_message_type'] = $messageType;
                if (ob_get_level()) {
                    ob_end_clean();
                }
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?tab=test');
                exit;
            } catch (Exception $e) {
                error_log('🚨 [admin.php] Error enviando email de prueba: ' . $e->getMessage());
                $message = 'Error al enviar email: ' . $e->getMessage();
                $messageType = 'error';
                $_SESSION['email_admin_message'] = $message;
                $_SESSION['email_admin_message_type'] = $messageType;
                if (ob_get_level()) {
                    ob_end_clean();
                }
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?tab=test');
                exit;
            } catch (Error $e) {
                error_log('🚨 [admin.php] Error fatal enviando email de prueba: ' . $e->getMessage());
                $message = 'Error fatal al enviar email: ' . $e->getMessage();
                $messageType = 'error';
                $_SESSION['email_admin_message'] = $message;
                $_SESSION['email_admin_message_type'] = $messageType;
                if (ob_get_level()) {
                    ob_end_clean();
                }
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?tab=test');
                exit;
            }
        }
    } elseif ($action === 'save-events') {
        // Guardar configuración de eventos
        $eventsFile = __DIR__ . '/config/email_events.php';
        $eventsConfig = include $eventsFile;
        
        foreach ($_POST['events'] ?? [] as $eventName => $eventData) {
            if (isset($eventsConfig[$eventName])) {
                $eventsConfig[$eventName]['enabled'] = isset($eventData['enabled']);
                if (!empty($eventData['subject'])) {
                    $eventsConfig[$eventName]['subject'] = $eventData['subject'];
                }
            }
        }
        
        $eventsContent = "<?php\n/**\n * Configuración de Eventos Automáticos de Email\n * Generado automáticamente por el administrador\n */\n\nreturn " . var_export($eventsConfig, true) . ";\n";
        
        if (file_put_contents($eventsFile, $eventsContent)) {
            $message = 'Configuración de eventos guardada exitosamente';
            $messageType = 'success';
        } else {
            $message = 'Error al guardar eventos. Verifique permisos.';
            $messageType = 'error';
        }
        
        // Guardar mensaje en sesión y redirigir
        $_SESSION['email_admin_message'] = $message;
        $_SESSION['email_admin_message_type'] = $messageType;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?tab=events');
        exit;
    }
}

// Cargar configuración actual
// Si hay un mensaje de éxito de guardado, forzar recarga sin cache
try {
    // Limpiar cualquier cache de opcache si está habilitado
    if (function_exists('opcache_invalidate') && isset($_SESSION['email_admin_message']) && $_SESSION['email_admin_message_type'] === 'success') {
        $configFile = __DIR__ . '/config/email_config.php';
        if (file_exists($configFile)) {
            opcache_invalidate($configFile, true);
        }
    }
    
    // Intentar cargar configuración del usuario actual primero
    // IMPORTANTE: Usuarios 'root' siempre usan la configuración global
    $currentUserData = getCurrentUserData();
    $currentUserId = $currentUserData ? (int)$currentUserData['id'] : null;
    $isRootUser = $currentUserData && isset($currentUserData['nivel']) && $currentUserData['nivel'] === 'root';
    
    $config = null;
    $smtpConfig = null;
    $options = null;
    
    // Solo intentar cargar configuración de usuario si NO es root
    if ($currentUserId && !$isRootUser) {
        $userConfig = EmailConfig::loadForUser($currentUserId);
        if ($userConfig) {
            error_log('🔍 [admin.php] Usando configuración SMTP del usuario ID: ' . $currentUserId);
            $config = $userConfig;
            $smtpConfig = $config->getSmtpConfig();
            $options = $config->getOptions();
        }
    } else if ($isRootUser) {
        error_log('🔍 [admin.php] Usuario ROOT detectado - usando configuración global');
    }
    
    // Si no hay configuración de usuario, cargar configuración global
    if (!$config) {
        $config = EmailConfig::load();
        $smtpConfig = $config->getSmtpConfig();
        $options = $config->getOptions();
    }
} catch (Exception $e) {
    error_log('Error cargando configuración en admin.php: ' . $e->getMessage());
    // Valores por defecto si hay error
    $smtpConfig = [
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'secure' => 'tls',
        'username' => '',
        'password' => '',
        'from_email' => 'noreply@tjsmedical.com',
        'from_name' => 'TJS Medical - Portal de Estudios'
    ];
    $options = [
        'charset' => 'UTF-8',
        'debug' => false,
        'log_errors' => true
    ];
    $config = null;
}

// Cargar eventos (solo si PHPMailer está disponible)
$events = [];
if ($phpmailerAvailable && class_exists('EmailEventManager')) {
    try {
        $eventManager = new EmailEventManager();
        $events = $eventManager->getEvents();
    } catch (Exception $e) {
        $phpmailerError = 'Error al cargar eventos: ' . $e->getMessage();
    }
} else {
    // Cargar eventos desde archivo directamente si PHPMailer no está disponible
    $eventsFile = __DIR__ . '/config/email_events.php';
    if (file_exists($eventsFile)) {
        $events = include $eventsFile;
        if (!is_array($events)) {
            $events = [];
        }
    }
}

// Cargar plantillas de email
$template = new EmailTemplate();
$templates = $template->listTemplates();

// Cargar plantillas de WhatsApp
$whatsappTemplates = [];
try {
    if (file_exists(__DIR__ . '/../whatsapp/WhatsAppTemplate.php')) {
        require_once __DIR__ . '/../whatsapp/WhatsAppTemplate.php';
        $whatsappTemplate = new WhatsAppTemplate();
        $whatsappTemplates = $whatsappTemplate->listTemplates();
    }
} catch (Exception $e) {
    error_log('Error cargando plantillas de WhatsApp: ' . $e->getMessage());
}

// Leer logs (últimas 50 líneas)
$logFile = __DIR__ . '/logs/email.log';
$logs = [];
if (file_exists($logFile)) {
    $logLines = file($logFile);
    $logs = array_slice($logLines, -50);
    $logs = array_reverse($logs);
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administración - Módulo de Email</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.css" rel="stylesheet">
    <link href="../../styles.css" rel="stylesheet">
    <!-- Verificación de permisos JavaScript -->
    <script src="../../js/auth-middleware.js"></script>
    <script src="../../assets/js/iframe-detector.js"></script>
    <script src="../../assets/js/simple-permission-manager.js?v=<?php echo time(); ?>"></script>
    <script src="../../assets/js/sidebar-gui-manager.js"></script>
    <script>
        // Ocultar elementos del sidebar inmediatamente para evitar flash visual
        (function() {
            const style = document.createElement('style');
            style.textContent = '.sidebar-nav .nav-item { display: none !important; }';
            document.head.appendChild(style);
        })();
    </script>
    <script>
        // Prevenir limpieza de consola
        console.clear = function() { /* No hacer nada */ };
        
        // Log inicial
        console.log('🔍 [admin.php] Iniciando verificación de permisos...');
        console.log('🔍 [admin.php] URL:', window.location.href);
        console.log('🔍 [admin.php] Timestamp:', new Date().toISOString());
        
        // Verificar permisos al cargar la página
        document.addEventListener('DOMContentLoaded', async function() {
            console.log('🔍 [admin.php] DOMContentLoaded - Iniciando verificación...');
            
            try {
                console.log('🔍 [admin.php] Llamando a requirePermissionSimple...');
                
                const hasAccess = await requirePermissionSimple('administracion_email', 'Administración Email', {
                    title: 'Acceso Denegado - Administración Email',
                    message: 'No tienes permisos para acceder a la <strong>Administración de Email</strong>. Contacta al administrador para solicitar el permiso "Administración email".',
                    redirectUrl: '../../dashboard-unified.html',
                    showLogoutButton: true
                });
                
                console.log('🔍 [admin.php] Resultado de requirePermissionSimple:', hasAccess);
                
                if (!hasAccess) {
                    console.error('❌ [admin.php] Acceso denegado - redirigiendo...');
                    // Si no tiene permisos, el modal ya se mostró y redirigió
                    return;
                }
                
                console.log('✅ [admin.php] Acceso permitido - continuando...');
            } catch (error) {
                console.error('🚨 [admin.php] ERROR en verificación de permisos:', error);
                console.error('🚨 [admin.php] Stack trace:', error.stack);
                // No redirigir en caso de error, permitir ver la página
            }
        });
        
        // También verificar inmediatamente (antes de DOMContentLoaded)
        (async function() {
            console.log('🔍 [admin.php] Verificación inmediata (antes de DOMContentLoaded)...');
            try {
                if (typeof checkPermissionSimple === 'function') {
                    const result = await checkPermissionSimple('administracion_email', 'Administración Email');
                    console.log('🔍 [admin.php] Resultado de checkPermissionSimple:', result);
                    
                    if (result && result.user) {
                        console.log('👤 [admin.php] Usuario detectado:', result.user.nombre, result.user.apellido);
                        console.log('🔐 [admin.php] Nivel:', result.user.nivel);
                        console.log('🔐 [admin.php] Permisos:', result.user.permisos);
                        console.log('✅ [admin.php] Tiene permiso:', result.hasPermission);
                        
                        // Si es root, permitir acceso sin verificar más
                        if (result.user.nivel === 'root') {
                            console.log('✅ [admin.php] Usuario ROOT detectado - acceso permitido automáticamente');
                            return; // No hacer nada más, permitir acceso
                        }
                    }
                }
            } catch (error) {
                console.error('🚨 [admin.php] ERROR en verificación inmediata:', error);
            }
        })();
    </script>
    <!-- TinyMCE Editor -->
    <script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f5f5;
            color: #333;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: relative;
        }
        .header h1 {
            font-size: 1.8em;
            margin-bottom: 5px;
        }
        .header p {
            opacity: 0.9;
        }
        .btn-back {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.3);
            padding: 10px 20px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-back:hover {
            background: rgba(255, 255, 255, 0.3);
            border-color: rgba(255, 255, 255, 0.5);
            color: white;
            text-decoration: none;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        .btn-back:active {
            transform: translateY(0);
        }
        .container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 0 20px;
        }
        .nav-tabs {
            display: flex;
            background: white;
            border-radius: 8px 8px 0 0;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            overflow-x: auto;
        }
        .nav-tab {
            padding: 15px 25px;
            cursor: pointer;
            border: none;
            background: none;
            font-size: 16px;
            color: #666;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
            white-space: nowrap;
        }
        .nav-tab:hover {
            background: #f9f9f9;
            color: #667eea;
        }
        .nav-tab.active {
            color: #667eea;
            border-bottom-color: #667eea;
            font-weight: 600;
        }
        .tab-content {
            display: none;
            background: white;
            padding: 30px;
            border-radius: 0 0 8px 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .tab-content.active {
            display: block;
        }
        .form-group {
            margin: 20px 0;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .form-group small {
            display: block;
            margin-top: 5px;
            color: #666;
            font-size: 12px;
        }
        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s;
            margin-right: 10px;
        }
        .btn-primary {
            background: #667eea;
            color: white;
        }
        .btn-primary:hover {
            background: #5568d3;
        }
        .btn-success {
            background: #28a745;
            color: white;
        }
        .btn-success:hover {
            background: #218838;
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
        .alert {
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-enabled {
            background: #d4edda;
            color: #155724;
        }
        .status-disabled {
            background: #f8d7da;
            color: #721c24;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        .table th,
        .table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .table th {
            background: #f9f9f9;
            font-weight: 600;
        }
        .table tr:hover {
            background: #f9f9f9;
        }
        .log-container {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
            border-radius: 5px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            max-height: 500px;
            overflow-y: auto;
        }
        .log-line {
            margin: 5px 0;
            white-space: pre-wrap;
        }
        .log-info { color: #4ec9b0; }
        .log-warning { color: #dcdcaa; }
        .log-error { color: #f48771; }
        .checkbox-group {
            display: flex;
            align-items: center;
        }
        .checkbox-group input[type="checkbox"] {
            width: auto;
            margin-right: 10px;
        }
    </style>
</head>
<body>
    <!-- Sidebar Layout -->
    <div id="sidebarLayout" class="layout-container">
        <!-- Mobile Header -->
        <div class="mobile-header d-lg-none">
            <div class="container-fluid">
                <div class="d-flex justify-content-between align-items-center py-2">
                    <button class="btn btn-link" id="sidebarToggle">
                        <i class="fas fa-bars fa-lg"></i>
                    </button>
                    <img src="../../logo.svg" alt="Logo" height="30">
                    <div class="dropdown">
                        <button class="btn btn-link" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle fa-lg"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="../../profesional.html">Mi Perfil</a></li>
                            <li><a class="dropdown-item" href="#configuracion">Configuración</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger logout-btn" href="#">Cerrar Sesión</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="sidebar d-flex flex-column" id="sidebar">
            <!-- Sidebar Header -->
            <div class="sidebar-header">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="text-center flex-grow-1">
                        <img src="../../logo.svg" alt="Logo" class="sidebar-logo mb-2">
                        <h5 class="sidebar-title mb-0">Portal Estudios</h5>
                    </div>
                    <!-- Botón para cerrar sidebar en móvil -->
                    <button class="btn btn-link text-white p-1 d-lg-none" id="sidebarCloseMobile" title="Cerrar Menú">
                        <i class="fas fa-times fa-lg"></i>
                    </button>
                    <!-- Botón para ocultar sidebar en desktop -->
                    <button class="btn btn-link text-white p-1 d-none d-lg-block" id="sidebarToggleDesktop" title="Ocultar Sidebar">
                        <i class="fas fa-angle-double-left"></i>
                    </button>
                </div>
            </div>

            <!-- User Info -->
            <div class="sidebar-user d-none d-lg-flex">
                <div class="user-avatar">
                    <i class="fas fa-user-circle"></i>
                </div>
                <div class="user-info">
                    <div class="user-name">Usuario</div>
                    <div class="user-role">Médico</div>
                </div>
            </div>

            <!-- Navigation -->
            <div class="sidebar-nav flex-grow-1">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="../../dashboard-unified.html">
                            <i class="fas fa-tachometer-alt"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../../paciente.html">
                            <i class="fas fa-file-medical"></i>
                            <span>Portal Paciente</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../../components/editor.html" id="informesNavLink">
                            <i class="fas fa-file-alt"></i>
                            <span>Informes</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../../components/informes-manager.html">
                            <i class="fas fa-search"></i>
                            <span>Gestión Informes</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../../estudios-manager.html">
                            <i class="fas fa-share-alt"></i>
                            <span>Gestión Estudios</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../../pacientes-manager.html">
                            <i class="fas fa-user-injured"></i>
                            <span>Gestión Pacientes</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../../app-container.html?section=workspace" data-section="workspace">
                            <i class="fas fa-th-large"></i>
                            <span>WorkSpace</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../../components/audio-recorder.html">
                            <i class="fas fa-microphone"></i>
                            <span>Grabación</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../../components/viewer.html">
                            <i class="fas fa-eye"></i>
                            <span>Visor DICOM</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../../user-management.html">
                            <i class="fas fa-users"></i>
                            <span>Gestión Usuarios</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../../pacs-manager.html">
                            <i class="fas fa-database"></i>
                            <span>PACS Manager</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../../worklist.html">
                            <i class="fas fa-list-alt"></i>
                            <span>Worklist</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../../ai-informes.html">
                            <i class="fas fa-robot"></i>
                            <span>AI Informes</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="admin.php" id="linkGestionMensajes">
                            <i class="fas fa-envelope"></i>
                            <span>Gestión Mensajes</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../../configuracion.html">
                            <i class="fas fa-cog"></i>
                            <span>Configuración</span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Sidebar Footer -->
            <div class="sidebar-footer d-none d-lg-block">
                <a href="#" class="btn btn-outline-light w-100 logout-btn">
                    <i class="fas fa-sign-out-alt me-2"></i>
                    Cerrar Sesión
                </a>
            </div>
        </div>

        <!-- Sidebar Overlay -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Desktop Sidebar Toggle (when hidden) -->
            <button class="btn btn-outline-secondary sidebar-toggle-show d-none" id="sidebarToggleShow" title="Mostrar Sidebar">
                <i class="fas fa-chevron-right"></i>
            </button>
            
            <div class="container" style="max-width: 1200px; margin: 20px auto; padding: 0 20px;">
                <!-- Header Section -->
                <div class="header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-radius: 8px; margin-bottom: 20px;">
                    <h1 style="font-size: 1.8em; margin-bottom: 5px;">📧 Administración del Módulo de Email</h1>
                    <p style="opacity: 0.9; margin: 0;">Gestión y configuración del sistema de envíos por email</p>
                </div>
                
                <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
                <?php endif; ?>
                
                <div class="nav-tabs">
            <button class="nav-tab active" onclick="showTab('config', this)">⚙️ Configuración SMTP</button>
            <button class="nav-tab" onclick="showTab('events', this)">🎯 Eventos Automáticos</button>
            <button class="nav-tab" onclick="showTab('templates', this)">📧 Plantillas</button>
            <button class="nav-tab" onclick="showTab('whatsapp', this)">💬 Mensajería WhatsApp</button>
            <button class="nav-tab" onclick="showTab('test', this)">🧪 Pruebas</button>
            <button class="nav-tab" onclick="showTab('logs', this)">📋 Logs</button>
        </div>
        
        <!-- Tab: Configuración SMTP -->
        <div id="tab-config" class="tab-content active">
            <h2>Configuración SMTP</h2>
            <form method="POST" action="?action=save-config" id="configForm" onsubmit="console.log('🔍 [admin.php] onsubmit handler ejecutado'); const formData = new FormData(this); console.log('🔍 [admin.php] FormData creado'); for (let [key, value] of formData.entries()) { console.log('  -', key, ':', key === 'smtp_password' ? (value ? '***SET***' : 'EMPTY') : value); } return true;">
                <div class="form-group">
                    <label>Servidor SMTP (Host):</label>
                    <input type="text" name="smtp_host" value="<?php echo htmlspecialchars($smtpConfig['host'] ?? ''); ?>" required>
                    <small>Ejemplo: smtp.gmail.com, smtp-mail.outlook.com</small>
                </div>
                
                <div class="form-group">
                    <label>Puerto SMTP:</label>
                    <input type="number" name="smtp_port" value="<?php echo htmlspecialchars($smtpConfig['port'] ?? 587); ?>" required>
                    <small>587 para TLS, 465 para SSL</small>
                </div>
                
                <div class="form-group">
                    <label>Seguridad:</label>
                    <select name="smtp_secure">
                        <option value="tls" <?php echo ($smtpConfig['secure'] ?? 'tls') === 'tls' ? 'selected' : ''; ?>>TLS</option>
                        <option value="ssl" <?php echo ($smtpConfig['secure'] ?? '') === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Usuario SMTP (Email):</label>
                    <input type="email" name="smtp_username" value="<?php echo htmlspecialchars($smtpConfig['username'] ?? ''); ?>" autocomplete="username" required>
                    <small>Email o usuario del servidor SMTP</small>
                </div>
                
                <div class="form-group">
                    <label>Contraseña SMTP:</label>
                    <input type="password" name="smtp_password" value="<?php echo htmlspecialchars($smtpConfig['password'] ?? ''); ?>" autocomplete="current-password" required>
                    <small>Para Gmail, use una "App Password" en lugar de su contraseña normal</small>
                </div>
                
                <div class="form-group">
                    <label>Email Remitente (From):</label>
                    <input type="email" name="from_email" value="<?php echo htmlspecialchars($smtpConfig['from_email'] ?? ''); ?>" autocomplete="email" required>
                </div>
                
                <div class="form-group">
                    <label>Nombre Remitente:</label>
                    <input type="text" name="from_name" value="<?php echo htmlspecialchars($smtpConfig['from_name'] ?? ''); ?>" required>
                    <small>Nombre que aparecerá como remitente en los emails</small>
                </div>
                
                <div style="margin: 30px 0; padding: 20px; background: #f0f4ff; border-left: 4px solid #667eea; border-radius: 5px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label style="font-weight: bold; color: #667eea; font-size: 1.2em; display: block; margin-bottom: 10px;">
                            <i class="fas fa-tag" style="margin-right: 8px;"></i>Nombre de la Aplicación (para Plantillas)
                        </label>
                        <input type="text" name="app_name" id="app_name_input" 
                               value="<?php echo htmlspecialchars(($config ? $config->get('app_name', $smtpConfig['from_name'] ?? 'TJS Medical - Portal de Estudios') : ($smtpConfig['from_name'] ?? 'TJS Medical - Portal de Estudios'))); ?>" 
                               required
                               style="width: 100%; padding: 10px; font-size: 1em; border: 2px solid #667eea; border-radius: 5px;">
                        <small style="display: block; margin-top: 8px; color: #666; line-height: 1.5;">
                            <strong>¿Qué es esto?</strong> Este nombre aparecerá en las plantillas de email cuando uses la variable <code style="background: #e0e0e0; padding: 2px 6px; border-radius: 3px;">{{app_name}}</code>. 
                            Se mostrará en los encabezados y pies de página de todos los emails enviados. 
                            Por ejemplo: "Mi Clínica - Portal de Pacientes" o "Dr. García - Consultorio".
                        </small>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="checkbox-group">
                        <input type="checkbox" name="debug" value="1" <?php echo ($options['debug'] ?? false) ? 'checked' : ''; ?>>
                        Modo Debug (solo para desarrollo)
                    </label>
                </div>
                
                <?php 
                $currentUserData = getCurrentUserData();
                $currentUserId = $currentUserData ? (int)$currentUserData['id'] : null;
                $isRootUser = $currentUserData && isset($currentUserData['nivel']) && $currentUserData['nivel'] === 'root';
                
                // Solo mostrar el checkbox si NO es usuario root
                if ($currentUserId && !$isRootUser): 
                    // Verificar si el usuario tiene configuración propia
                    $userConfig = EmailConfig::loadForUser($currentUserId);
                    $hasUserConfig = $userConfig !== null;
                ?>
                <div class="form-group" style="margin: 20px 0; padding: 15px; background: #e7f3ff; border-left: 4px solid #2196F3; border-radius: 5px;">
                    <label class="checkbox-group" style="font-weight: 600; color: #1976D2;">
                        <input type="checkbox" name="save_as_user_config" value="1" id="save_as_user_config" <?php echo $hasUserConfig ? 'checked' : ''; ?>>
                        Guardar como mi configuración personal (solo para mi usuario)
                    </label>
                    <small style="display: block; margin-top: 8px; color: #666; line-height: 1.5;">
                        <?php if ($hasUserConfig): ?>
                            <strong>✓ Tienes una configuración SMTP personal.</strong> Si marcas esta opción, se guardará tu configuración personal. Si no la marcas, se guardará como configuración global del sistema.
                        <?php else: ?>
                            Si marcas esta opción, esta configuración SMTP se guardará solo para tu usuario. Si no la marcas, se guardará como configuración global del sistema (requiere permisos de administración).
                        <?php endif; ?>
                    </small>
                </div>
                <?php elseif ($isRootUser): ?>
                <div class="form-group" style="margin: 20px 0; padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 5px;">
                    <small style="display: block; color: #856404; line-height: 1.5;">
                        <strong>ℹ️ Usuario ROOT:</strong> Como administrador del sistema, esta configuración se guardará como configuración global y aplicará a todos los usuarios que no tengan configuración personal.
                    </small>
                </div>
                <?php endif; ?>
                
                <button type="submit" class="btn btn-primary" id="saveConfigBtn" onclick="console.log('🔍 [admin.php] Botón Guardar clickeado'); return true;">💾 Guardar Configuración</button>
                <?php if ($phpmailerAvailable): ?>
                <button type="button" class="btn btn-secondary" onclick="testConnection()">🔌 Probar Conexión</button>
                <?php else: ?>
                <button type="button" class="btn btn-secondary" disabled title="PHPMailer no está instalado">🔌 Probar Conexión (Requiere PHPMailer)</button>
                <?php endif; ?>
            </form>
        </div>
        
        <!-- Tab: Eventos Automáticos -->
        <div id="tab-events" class="tab-content">
            <h2>Eventos Automáticos</h2>
            <form method="POST" action="?action=save-events">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Evento</th>
                            <th>Descripción</th>
                            <th>Plantilla</th>
                            <th>Estado</th>
                            <th>Asunto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $eventName => $event): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($eventName); ?></strong></td>
                            <td><?php echo htmlspecialchars($event['description'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($event['template'] ?? ''); ?></td>
                            <td>
                                <span class="status-badge <?php echo ($event['enabled'] ?? false) ? 'status-enabled' : 'status-disabled'; ?>">
                                    <?php echo ($event['enabled'] ?? false) ? '✓ Habilitado' : '✗ Deshabilitado'; ?>
                                </span>
                                <input type="hidden" name="events[<?php echo $eventName; ?>][enabled]" value="0">
                                <input type="checkbox" name="events[<?php echo $eventName; ?>][enabled]" value="1" <?php echo ($event['enabled'] ?? false) ? 'checked' : ''; ?>>
                            </td>
                            <td>
                                <input type="text" name="events[<?php echo $eventName; ?>][subject]" value="<?php echo htmlspecialchars($event['subject'] ?? ''); ?>" style="width: 100%; padding: 8px;">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <button type="submit" class="btn btn-primary">💾 Guardar Eventos</button>
            </form>
        </div>
        
        <!-- Tab: Plantillas -->
        <div id="tab-templates" class="tab-content">
            <h2>Editor de Plantillas</h2>
            
            <!-- Selector de tipo de plantilla -->
            <div style="margin-bottom: 20px; border-bottom: 2px solid #e0e0e0; padding-bottom: 15px;">
                <div style="display: flex; gap: 10px;">
                    <button onclick="switchTemplateType('email')" id="templateTypeEmail" class="btn btn-primary" style="padding: 10px 20px;">
                        <i class="fas fa-envelope"></i> Plantillas de Email
                    </button>
                    <button onclick="switchTemplateType('whatsapp')" id="templateTypeWhatsApp" class="btn btn-secondary" style="padding: 10px 20px;">
                        <i class="fab fa-whatsapp"></i> Plantillas de WhatsApp
                    </button>
                </div>
            </div>
            
            <!-- Sección de Plantillas de Email -->
            <div id="emailTemplatesSection" class="template-section">
                <div style="display: flex; gap: 20px; margin-top: 20px;">
                    <!-- Lista de Plantillas de Email -->
                    <div style="flex: 0 0 300px; border: 1px solid #ddd; border-radius: 5px; padding: 15px; background: #f9f9f9;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                            <h3 style="margin: 0; font-size: 18px; color: #667eea;">
                                <i class="fas fa-envelope" style="margin-right: 8px;"></i>Plantillas Email
                            </h3>
                            <button onclick="createNewTemplate('email')" class="btn btn-success" style="padding: 5px 10px; font-size: 12px;">
                                <i class="fas fa-plus"></i> Nueva
                            </button>
                        </div>
                        <div id="emailTemplatesList" style="max-height: 500px; overflow-y: auto;">
                            <?php foreach ($templates as $tpl): ?>
                            <div class="template-item email-template-item" 
                                 data-template-name="<?php echo htmlspecialchars($tpl['name']); ?>"
                                 onclick="selectTemplate('<?php echo htmlspecialchars($tpl['name']); ?>', this, 'email')" 
                                 style="padding: 10px; margin-bottom: 5px; background: white; border: 1px solid #ddd; border-radius: 3px; cursor: pointer; transition: all 0.2s;"
                                 onmouseover="if(!this.classList.contains('selected')) this.style.background='#e9ecef'; this.style.borderColor='#667eea'" 
                                 onmouseout="if(!this.classList.contains('selected')) this.style.background='white'; this.style.borderColor='#ddd'">
                                <div style="display: flex; justify-content: space-between; align-items: start;">
                                    <div style="flex: 1;">
                                        <strong><?php echo htmlspecialchars($tpl['name']); ?></strong>
                                        <div style="font-size: 11px; color: #666; margin-top: 5px;">
                                            <?php echo number_format($tpl['size'] / 1024, 2); ?> KB · 
                                            <?php echo date('d/m/Y H:i', $tpl['modified']); ?>
                                        </div>
                                    </div>
                                    <button onclick="event.stopPropagation(); loadTemplate('<?php echo htmlspecialchars($tpl['name']); ?>', 'email')" 
                                            class="btn btn-sm btn-outline-primary" 
                                            style="padding: 2px 8px; font-size: 11px;"
                                            title="Editar plantilla">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Editor de Email -->
                    <div style="flex: 1; border: 1px solid #667eea; border-radius: 5px; padding: 15px; background: white;">
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Nombre de Plantilla:</label>
                            <input type="text" id="emailTemplateName" placeholder="ej: bienvenida" 
                                   style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 3px;"
                                   onchange="updateTemplateName('email')">
                            <small style="color: #666;">Solo letras, números, guiones y guiones bajos</small>
                        </div>
                        
                        <div style="margin-bottom: 15px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                                <label style="font-weight: bold;">Contenido de la Plantilla (HTML):</label>
                                <div style="display: flex; gap: 10px;">
                                    <button onclick="toggleEditorMode('email')" id="toggleModeBtnEmail" class="btn btn-secondary" style="padding: 5px 10px; font-size: 12px;">
                                        <i class="fas fa-code"></i> Ver HTML
                                    </button>
                                    <button onclick="showVariables('email')" class="btn btn-info" style="padding: 5px 10px; font-size: 12px;">
                                        <i class="fas fa-info-circle"></i> Variables Disponibles
                                    </button>
                                </div>
                            </div>
                            <div id="emailTemplateEditorContainer" style="min-height: 400px;">
                                <textarea id="emailTemplateContent" placeholder="Ingrese el contenido HTML de la plantilla..."></textarea>
                            </div>
                        </div>
                        
                        <div style="margin-bottom: 15px;">
                            <h4 style="margin: 0 0 10px 0; font-size: 14px;">Vista Previa:</h4>
                            <div style="border: 1px solid #ddd; border-radius: 3px; background: #f9f9f9; position: relative; min-height: 200px; max-height: 400px; overflow: hidden;">
                                <iframe id="emailTemplatePreview" 
                                        style="width: 100%; min-height: 200px; max-height: 400px; border: none; background: white;"
                                        sandbox="allow-same-origin allow-scripts"
                                        title="Vista previa de plantilla de email">
                                </iframe>
                                <div id="emailTemplatePreviewPlaceholder" 
                                     style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #999; pointer-events: none;">
                                    La vista previa aparecerá aquí
                                </div>
                            </div>
                        </div>
                        
                        <div style="display: flex; gap: 10px;">
                            <button onclick="saveTemplate('email')" class="btn btn-primary">
                                <i class="fas fa-save"></i> Guardar
                            </button>
                            <button onclick="previewTemplate('email')" class="btn btn-info">
                                <i class="fas fa-eye"></i> Vista Previa
                            </button>
                            <button onclick="deleteTemplate('email')" id="deleteEmailTemplateBtn" class="btn btn-danger" style="display: none;">
                                <i class="fas fa-trash"></i> Eliminar
                            </button>
                            <button onclick="clearTemplate('email')" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Limpiar
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Sección de Plantillas de WhatsApp -->
            <div id="whatsappTemplatesSection" class="template-section" style="display: none;">
                <div style="display: flex; gap: 20px; margin-top: 20px;">
                    <!-- Lista de Plantillas de WhatsApp -->
                    <div style="flex: 0 0 300px; border: 1px solid #ddd; border-radius: 5px; padding: 15px; background: #f9f9f9;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                            <h3 style="margin: 0; font-size: 18px; color: #25d366;">
                                <i class="fab fa-whatsapp" style="margin-right: 8px;"></i>Plantillas WhatsApp
                            </h3>
                            <button onclick="createNewTemplate('whatsapp')" class="btn btn-success" style="padding: 5px 10px; font-size: 12px;">
                                <i class="fas fa-plus"></i> Nueva
                            </button>
                        </div>
                        <div id="whatsappTemplatesList" style="max-height: 500px; overflow-y: auto;">
                            <?php foreach ($whatsappTemplates as $tpl): ?>
                            <div class="template-item whatsapp-template-item" 
                                 data-template-name="<?php echo htmlspecialchars($tpl['name']); ?>"
                                 onclick="selectTemplate('<?php echo htmlspecialchars($tpl['name']); ?>', this, 'whatsapp')" 
                                 style="padding: 10px; margin-bottom: 5px; background: white; border: 1px solid #ddd; border-radius: 3px; cursor: pointer; transition: all 0.2s;"
                                 onmouseover="if(!this.classList.contains('selected')) this.style.background='#e9ecef'; this.style.borderColor='#25d366'" 
                                 onmouseout="if(!this.classList.contains('selected')) this.style.background='white'; this.style.borderColor='#ddd'">
                                <div style="display: flex; justify-content: space-between; align-items: start;">
                                    <div style="flex: 1;">
                                        <strong><?php echo htmlspecialchars($tpl['name']); ?></strong>
                                        <div style="font-size: 11px; color: #666; margin-top: 5px;">
                                            <?php echo number_format($tpl['size'] / 1024, 2); ?> KB · 
                                            <?php echo date('d/m/Y H:i', $tpl['modified']); ?>
                                        </div>
                                    </div>
                                    <button onclick="event.stopPropagation(); loadTemplate('<?php echo htmlspecialchars($tpl['name']); ?>', 'whatsapp')" 
                                            class="btn btn-sm btn-outline-primary" 
                                            style="padding: 2px 8px; font-size: 11px;"
                                            title="Editar plantilla">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Editor de WhatsApp -->
                    <div style="flex: 1; border: 1px solid #25d366; border-radius: 5px; padding: 15px; background: white;">
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Nombre de Plantilla:</label>
                            <input type="text" id="whatsappTemplateName" placeholder="ej: bienvenida" 
                                   style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 3px;"
                                   onchange="updateTemplateName('whatsapp')">
                            <small style="color: #666;">Solo letras, números, guiones y guiones bajos</small>
                        </div>
                        
                        <div style="margin-bottom: 15px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                                <label style="font-weight: bold;">Contenido de la Plantilla (Texto Plano):</label>
                                <div style="display: flex; gap: 10px;">
                                    <button onclick="showVariables('whatsapp')" class="btn btn-info" style="padding: 5px 10px; font-size: 12px;">
                                        <i class="fas fa-info-circle"></i> Variables Disponibles
                                    </button>
                                </div>
                            </div>
                            <div id="whatsappTemplateEditorContainer" style="min-height: 400px;">
                                <textarea id="whatsappTemplateContent" placeholder="Ingrese el contenido de texto de la plantilla de WhatsApp..." style="width: 100%; min-height: 400px; padding: 10px; border: 1px solid #ddd; border-radius: 3px; font-family: monospace; font-size: 14px;"></textarea>
                            </div>
                            <small style="color: #666; margin-top: 5px; display: block;">
                                <i class="fab fa-whatsapp"></i> Las plantillas de WhatsApp son texto plano. No se admite HTML.
                            </small>
                        </div>
                        
                        <div style="margin-bottom: 15px;">
                            <h4 style="margin: 0 0 10px 0; font-size: 14px;">Vista Previa:</h4>
                            <div style="border: 1px solid #25d366; border-radius: 3px; background: #dcf8c6; padding: 15px; min-height: 200px; max-height: 400px; overflow-y: auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
                                <div id="whatsappTemplatePreview" style="white-space: pre-wrap; word-wrap: break-word; color: #000;">
                                    <div style="color: #999; font-style: italic;">La vista previa aparecerá aquí</div>
                                </div>
                            </div>
                        </div>
                        
                        <div style="display: flex; gap: 10px;">
                            <button onclick="saveTemplate('whatsapp')" class="btn btn-primary" style="background: #25d366; border-color: #25d366;">
                                <i class="fas fa-save"></i> Guardar
                            </button>
                            <button onclick="previewTemplate('whatsapp')" class="btn btn-info">
                                <i class="fas fa-eye"></i> Vista Previa
                            </button>
                            <button onclick="deleteTemplate('whatsapp')" id="deleteWhatsAppTemplateBtn" class="btn btn-danger" style="display: none;">
                                <i class="fas fa-trash"></i> Eliminar
                            </button>
                            <button onclick="clearTemplate('whatsapp')" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Limpiar
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tab: Mensajería WhatsApp -->
        <div id="tab-whatsapp" class="tab-content">
            <h2>💬 Gestión de Mensajería WhatsApp</h2>
            
            <?php if ($message && (strpos($_SERVER['REQUEST_URI'] ?? '', 'tab=whatsapp') !== false || $action === 'save-whatsapp-config' || $action === 'save-my-whatsapp-config')): ?>
            <div id="whatsappMessage" class="alert alert-<?php echo $messageType === 'error' ? 'error' : 'success'; ?>" style="background: <?php echo $messageType === 'error' ? '#fee' : '#d4edda'; ?>; color: <?php echo $messageType === 'error' ? '#c33' : '#155724'; ?>; padding: 15px; border-radius: 5px; margin-bottom: 20px; border-left: 4px solid <?php echo $messageType === 'error' ? '#c33' : '#28a745'; ?>; position: relative;">
                <strong><?php echo $messageType === 'error' ? '❌ Error:' : '✅ Éxito:'; ?></strong> <?php echo htmlspecialchars($message); ?>
                <?php if ($messageType === 'error'): ?>
                <button onclick="this.parentElement.style.display='none'" style="position: absolute; top: 10px; right: 10px; background: transparent; border: none; color: #c33; font-size: 1.2em; cursor: pointer; padding: 0 5px;">×</button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <?php
            // Verificar si el usuario tiene permiso para configurar WhatsApp personal
            $userId = getCurrentUserId();
            $canConfigureWhatsApp = false;
            $userPermissions = [];
            
            if ($userId) {
                try {
                    $userClassPaths = [
                        __DIR__ . '/../../classes/User.php',
                        __DIR__ . '/../../../classes/User.php'
                    ];
                    
                    foreach ($userClassPaths as $path) {
                        if (file_exists($path)) {
                            require_once $path;
                            if (class_exists('User')) {
                                $user = new User();
                                $token = $_COOKIE['session_token'] ?? null;
                                if ($token) {
                                    $user_data = $user->validateSession($token);
                                    if ($user_data && is_array($user_data)) {
                                        if (isset($user_data['nivel']) && $user_data['nivel'] === 'root') {
                                            $canConfigureWhatsApp = true;
                                        } else {
                                            if (isset($user_data['permisos'])) {
                                                $userPermissions = is_string($user_data['permisos']) 
                                                    ? json_decode($user_data['permisos'], true) 
                                                    : $user_data['permisos'];
                                            }
                                            $canConfigureWhatsApp = in_array('administracion_whatsapp', $userPermissions) 
                                                                 || in_array('all', $userPermissions);
                                        }
                                    }
                                }
                                break;
                            }
                        }
                    }
                } catch (Exception $e) {
                    error_log('Error verificando permisos WhatsApp: ' . $e->getMessage());
                }
            }
            
            // Cargar configuración personal del usuario si tiene permiso
            $myWahaConfig = null;
            if ($canConfigureWhatsApp && $userId) {
                try {
                    if (file_exists(__DIR__ . '/../whatsapp/WhatsAppConfig.php')) {
                        require_once __DIR__ . '/../whatsapp/WhatsAppConfig.php';
                        $myWahaConfig = WhatsAppConfig::loadForUser($userId);
                    }
                } catch (Exception $e) {
                    error_log('Error cargando configuración personal WhatsApp: ' . $e->getMessage());
                }
            }
            ?>
            
            <?php if ($canConfigureWhatsApp): ?>
            <!-- Mi Configuración de WAHA (Personal) -->
            <div class="form-section" style="margin-bottom: 30px; border: 2px solid #667eea; border-radius: 8px; padding: 20px; background: #f8f9ff;">
                <div class="form-section-title" style="color: #667eea;">
                    <i class="fas fa-user-cog me-2"></i>Mi Configuración de WAHA (Personal)
                </div>
                <p style="color: #666; margin-bottom: 15px; font-size: 0.9em;">
                    <i class="fas fa-info-circle me-1"></i>
                    Esta configuración es personal y solo se usará para tus envíos. Si no configuras una instancia personal, se usará la configuración global del sistema.
                </p>
                <form method="POST" action="?action=save-my-whatsapp-config&tab=whatsapp" id="myWhatsAppConfigForm">
                    <div class="form-group">
                        <label>URL Base de WAHA (Personal):</label>
                        <input type="text" name="waha_base_url" 
                               value="<?php echo htmlspecialchars($myWahaConfig['base_url'] ?? ''); ?>" 
                               placeholder="http://localhost:3000" required>
                        <small>Tu instancia personal de WAHA</small>
                    </div>
                    <div class="form-group">
                        <label>API Key (opcional):</label>
                        <input type="text" name="waha_api_key" 
                               value="<?php echo htmlspecialchars($myWahaConfig['api_key'] ?? ''); ?>" 
                               placeholder="Dejar vacío si no requiere autenticación">
                        <small>API Key de tu instancia personal de WAHA</small>
                    </div>
                    <div class="form-group">
                        <label>Sesión por Defecto:</label>
                        <input type="text" name="waha_default_session" 
                               value="<?php echo htmlspecialchars($myWahaConfig['default_session'] ?? 'default'); ?>" 
                               placeholder="default" required>
                        <small>Nombre de la sesión que usarás por defecto</small>
                    </div>
                    <div class="form-group">
                        <label>Código de País por Defecto:</label>
                        <input type="text" name="waha_default_country_code" 
                               value="<?php echo htmlspecialchars($myWahaConfig['default_country_code'] ?? '54'); ?>" 
                               placeholder="54" maxlength="5">
                        <small>Código de país para números sin código (ej: 54 para Argentina)</small>
                    </div>
                    <div class="form-group">
                        <label>Timeout (segundos):</label>
                        <input type="number" name="waha_timeout" 
                               value="<?php echo htmlspecialchars($myWahaConfig['timeout'] ?? 30); ?>" 
                               min="5" max="120" required>
                        <small>Tiempo de espera para las peticiones a WAHA</small>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>💾 Guardar Mi Configuración
                    </button>
                    <?php if ($myWahaConfig): ?>
                    <button type="button" class="btn btn-danger" onclick="if(confirm('¿Estás seguro de que deseas eliminar tu configuración personal? Se usará la configuración global.')) { document.getElementById('deleteMyWhatsAppConfig').submit(); }">
                        <i class="fas fa-trash me-2"></i>Eliminar Mi Configuración
                    </button>
                    <?php endif; ?>
                </form>
                <?php if ($myWahaConfig): ?>
                <form method="POST" action="?action=delete-my-whatsapp-config&tab=whatsapp" id="deleteMyWhatsAppConfig" style="display: none;">
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Configuración de WAHA (Global) -->
            <div class="form-section" style="margin-bottom: 30px;">
                <div class="form-section-title">
                    <i class="fas fa-cog me-2"></i>Configuración de WAHA
                </div>
                <form method="POST" action="?action=save-whatsapp-config" id="whatsappConfigForm">
                    <?php
                    // Cargar configuración de WhatsApp si existe
                    $whatsappConfig = null;
                    try {
                        if (file_exists(__DIR__ . '/../whatsapp/WhatsAppConfig.php')) {
                            require_once __DIR__ . '/../whatsapp/WhatsAppConfig.php';
                            $whatsappConfig = WhatsAppConfig::load();
                            $wahaConfig = $whatsappConfig->getWahaConfig();
                        }
                    } catch (Exception $e) {
                        error_log('Error cargando configuración WhatsApp: ' . $e->getMessage());
                    }
                    ?>
                    <div class="form-group">
                        <label>URL Base de WAHA:</label>
                        <input type="text" name="waha_base_url" value="<?php echo htmlspecialchars($wahaConfig['base_url'] ?? 'http://localhost:3000'); ?>" placeholder="http://localhost:3000" required>
                        <small>URL donde está corriendo WAHA (Docker)</small>
                    </div>
                    <div class="form-group">
                        <label>API Key (opcional):</label>
                        <input type="text" name="waha_api_key" value="<?php echo htmlspecialchars($wahaConfig['api_key'] ?? ''); ?>" placeholder="Dejar vacío si no requiere autenticación">
                        <small>API Key si WAHA requiere autenticación</small>
                    </div>
                    <div class="form-group">
                        <label>Sesión por Defecto:</label>
                        <input type="text" name="waha_default_session" value="<?php echo htmlspecialchars($wahaConfig['default_session'] ?? 'default'); ?>" placeholder="default" required>
                        <small>Nombre de la sesión que se usará por defecto para enviar mensajes</small>
                    </div>
                    <button type="submit" class="btn btn-primary">💾 Guardar Configuración</button>
                </form>
            </div>
            
            <!-- Gestión de Sesiones -->
            <div class="form-section">
                <div class="form-section-title">
                    <i class="fas fa-comments me-2"></i>Gestión de Sesiones de WhatsApp
                </div>
                
                <div style="margin-bottom: 20px;">
                    <button type="button" class="btn btn-success" onclick="createWhatsAppSession()">
                        <i class="fas fa-plus me-2"></i>Crear Nueva Sesión
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="loadWhatsAppSessions()">
                        <i class="fas fa-sync me-2"></i>Actualizar Lista
                    </button>
                </div>
                
                <div id="whatsappSessionsList" style="margin-top: 20px;">
                    <div class="text-center" style="padding: 40px; color: #999;">
                        <i class="fas fa-spinner fa-spin" style="font-size: 2em; margin-bottom: 10px;"></i>
                        <p>Cargando sesiones...</p>
                    </div>
                </div>
                
                <!-- Configuración de WAHA para JavaScript -->
                <script>
                    // Pasar configuración de WAHA a JavaScript
                    window.wahaConfig = {
                        baseUrl: '<?php echo htmlspecialchars($wahaConfig['base_url'] ?? 'http://localhost:3000', ENT_QUOTES); ?>',
                        apiKey: '<?php echo htmlspecialchars($wahaConfig['api_key'] ?? '', ENT_QUOTES); ?>',
                        defaultSession: '<?php echo htmlspecialchars($wahaConfig['default_session'] ?? 'default', ENT_QUOTES); ?>'
                    };
                    console.log('🔍 [WAHA Config] Configuración cargada:', window.wahaConfig);
                </script>
            </div>
        </div>
        
        <!-- Tab: Pruebas -->
        <div id="tab-test" class="tab-content">
            <h2>Enviar Email de Prueba</h2>
            <form method="POST" action="?action=send-test">
                <div class="form-group">
                    <label>Email Destinatario:</label>
                    <input type="email" name="test_email" placeholder="tu-email@ejemplo.com" required>
                    <small>Se enviará un email de prueba a esta dirección</small>
                </div>
                <?php if ($phpmailerAvailable): ?>
                <button type="submit" class="btn btn-success">📧 Enviar Email de Prueba</button>
                <?php else: ?>
                <div class="alert alert-error" style="margin-top: 20px; background: #fff3cd; color: #856404; border: 1px solid #ffeaa7;">
                    ⚠️ PHPMailer no está instalado. Instala las dependencias primero ejecutando: <code>composer install</code>
                </div>
                <?php endif; ?>
            </form>
        </div>
        
        <!-- Tab: Logs -->
        <div id="tab-logs" class="tab-content">
            <h2>Logs del Sistema</h2>
            <div class="log-container">
                <?php if (empty($logs)): ?>
                    <div>No hay logs disponibles</div>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <?php
                        $logClass = 'log-info';
                        if (strpos($log, '[error]') !== false) $logClass = 'log-error';
                        elseif (strpos($log, '[warning]') !== false) $logClass = 'log-warning';
                        ?>
                        <div class="log-line <?php echo $logClass; ?>"><?php echo htmlspecialchars($log); ?></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <button type="button" class="btn btn-secondary" onclick="location.reload()">🔄 Actualizar</button>
        </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Variables Disponibles -->
    <div id="variablesModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
        <div style="background: white; border-radius: 10px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); max-width: 700px; width: 90%; max-height: 80vh; overflow: hidden; display: flex; flex-direction: column;">
            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; display: flex; justify-content: space-between; align-items: center;">
                <h2 id="variablesModalTitle" style="margin: 0; font-size: 1.5em; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-code" style="font-size: 1.2em;"></i>
                    Variables Disponibles
                </h2>
                <button onclick="closeVariablesModal()" style="background: rgba(255,255,255,0.2); border: none; color: white; width: 35px; height: 35px; border-radius: 50%; cursor: pointer; font-size: 1.2em; display: flex; align-items: center; justify-content: center; transition: background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.3)'" onmouseout="this.style.background='rgba(255,255,255,0.2)'">
                    ×
                </button>
            </div>
            <div style="padding: 25px; overflow-y: auto; flex: 1;">
                <p id="variablesModalDescription" style="color: #666; margin-bottom: 20px; line-height: 1.6;">
                    Puedes usar estas variables en tus plantillas. Se reemplazarán automáticamente con los valores correspondientes al enviar el mensaje.
                </p>
                <div id="variablesList" style="display: grid; gap: 12px;">
                    <!-- Las variables se cargarán aquí dinámicamente -->
                </div>
            </div>
            <div style="padding: 15px 25px; background: #f8f9fa; border-top: 1px solid #e0e0e0; display: flex; justify-content: flex-end; gap: 10px;">
                <button onclick="copyAllVariables()" style="padding: 10px 20px; background: #667eea; color: white; border: none; border-radius: 5px; cursor: pointer; font-weight: 500; transition: background 0.2s;" onmouseover="this.style.background='#5568d3'" onmouseout="this.style.background='#667eea'">
                    <i class="fas fa-copy"></i> Copiar Todas
                </button>
                <button onclick="closeVariablesModal()" style="padding: 10px 20px; background: #6c757d; color: white; border: none; border-radius: 5px; cursor: pointer; font-weight: 500; transition: background 0.2s;" onmouseover="this.style.background='#5a6268'" onmouseout="this.style.background='#6c757d'">
                    Cerrar
                </button>
            </div>
        </div>
    </div>
    
    <script>
        let currentTemplateName = null;
        let currentTemplateType = 'email'; // 'email' o 'whatsapp'
        const apiBase = 'api/manage-template.php';
        const whatsappApiBase = 'api/manage-template-whatsapp.php';
        let templateEditor = null;
        let editorMode = 'wysiwyg'; // 'wysiwyg' o 'code'
        
        // Inicializar TinyMCE cuando se carga la página
        document.addEventListener('DOMContentLoaded', function() {
            initTinyMCE();
            
            // Agregar listener al formulario de configuración
            const configForm = document.getElementById('configForm');
            if (configForm) {
                console.log('🔍 [admin.php] Formulario encontrado:', configForm);
                
                // Listener para el botón
                const saveBtn = document.getElementById('saveConfigBtn');
                if (saveBtn) {
                    saveBtn.addEventListener('click', function(e) {
                        console.log('🔍 [admin.php] Botón Guardar - Event listener click');
                    });
                }
                
                // Listener para el submit del formulario
                configForm.addEventListener('submit', function(e) {
                    console.log('🔍 [admin.php] Event listener - Formulario enviado');
                    console.log('🔍 [admin.php] Action:', this.action);
                    console.log('🔍 [admin.php] Method:', this.method);
                    console.log('🔍 [admin.php] Form visible:', this.offsetParent !== null);
                    console.log('🔍 [admin.php] Form display:', window.getComputedStyle(this).display);
                    
                    const formData = new FormData(this);
                    console.log('🔍 [admin.php] Datos del formulario:');
                    for (let [key, value] of formData.entries()) {
                        if (key === 'smtp_password') {
                            console.log('  -', key, ':', value ? '***SET***' : 'EMPTY');
                        } else {
                            console.log('  -', key, ':', value);
                        }
                    }
                    
                    // No prevenir el envío, solo loguear
                });
            } else {
                console.error('🚨 [admin.php] Formulario NO encontrado!');
            }
            
            // Agregar listener al formulario de configuración de WhatsApp
            const whatsappConfigForm = document.getElementById('whatsappConfigForm');
            if (whatsappConfigForm) {
                console.log('🔍 [admin.php] Formulario WhatsApp encontrado:', whatsappConfigForm);
                
                whatsappConfigForm.addEventListener('submit', function(e) {
                    console.log('🔍 [admin.php] WhatsApp Form - Event listener - Formulario enviado');
                    console.log('🔍 [admin.php] WhatsApp Form - Action:', this.action);
                    console.log('🔍 [admin.php] WhatsApp Form - Method:', this.method);
                    
                    const formData = new FormData(this);
                    console.log('🔍 [admin.php] WhatsApp Form - Datos del formulario:');
                    for (let [key, value] of formData.entries()) {
                        console.log('  -', key, ':', value);
                    }
                    
                    // Validar que los campos requeridos estén llenos
                    const baseUrl = formData.get('waha_base_url');
                    const defaultSession = formData.get('waha_default_session');
                    
                    if (!baseUrl || !defaultSession) {
                        e.preventDefault();
                        alert('Por favor, complete todos los campos requeridos.');
                        return false;
                    }
                    
                    // No prevenir el envío, solo loguear y validar
                });
            } else {
                console.warn('⚠️ [admin.php] Formulario WhatsApp NO encontrado (puede estar en otra pestaña)');
            }
            
            // Agregar listener para actualizar vista previa de WhatsApp cuando cambie el contenido
            const whatsappTextarea = document.getElementById('whatsappTemplateContent');
            if (whatsappTextarea) {
                whatsappTextarea.addEventListener('input', function() {
                    updatePreviewFromEditor('whatsapp');
                });
            }
        });
        
        function initTinyMCE() {
            if (!window.tinymce) {
                console.warn('TinyMCE no está disponible');
                return;
            }
            
            // Esperar a que el textarea esté disponible
            const textarea = document.getElementById('emailTemplateContent');
            if (!textarea) {
                setTimeout(initTinyMCE, 100);
                return;
            }
            
            // Si ya existe un editor, removerlo primero
            const existing = tinymce.get('emailTemplateContent');
            if (existing) {
                existing.remove();
            }
            
            tinymce.init({
                selector: '#emailTemplateContent',
                height: 400,
                language: 'es', // Usar español
                language_url: '../../js/tinymce/langs/es.js', // Ruta al archivo local de idioma desde modules/email/
                menubar: 'file edit view insert format tools table help',
                plugins: [
                    'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
                    'anchor', 'searchreplace', 'visualblocks', 'visualchars', 'code', 'fullscreen',
                    'insertdatetime', 'media', 'table', 'help', 'wordcount'
                ],
                toolbar: 'undo redo | formatselect | bold italic underline strikethrough | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link image table | code fullscreen | help',
                content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; font-size: 14px; line-height: 1.6; }',
                branding: false,
                setup: function(editor) {
                    templateEditor = editor;
                    
                    editor.on('init', function() {
                        console.log('Editor TinyMCE inicializado correctamente en español para plantillas de email');
                    });
                    
                    editor.on('change', function() {
                        // Actualizar vista previa automáticamente
                        updatePreviewFromEditor('email');
                    });
                    
                    // Agregar botón personalizado para insertar variables
                    editor.ui.registry.addButton('insertvariable', {
                        text: '{{variable}}',
                        tooltip: 'Insertar variable',
                        onAction: function() {
                            showVariables();
                        }
                    });
                },
                // Configuración específica para emails HTML
                valid_elements: '*[*]',
                extended_valid_elements: '*[*]',
                invalid_elements: 'script,object,embed,iframe',
                convert_urls: false,
                relative_urls: false
            });
        }
        
        function toggleEditorMode(type = 'email') {
            if (type !== 'email' || !templateEditor) return;
            
            const btn = document.getElementById('toggleModeBtnEmail');
            if (!btn) return;
            
            if (editorMode === 'wysiwyg') {
                // Cambiar a modo código
                editorMode = 'code';
                const content = templateEditor.getContent();
                templateEditor.mode.set('code');
                btn.innerHTML = '<i class="fas fa-eye"></i> Ver Vista Previa';
            } else {
                // Cambiar a modo WYSIWYG
                editorMode = 'wysiwyg';
                templateEditor.mode.set('design');
                btn.innerHTML = '<i class="fas fa-code"></i> Ver HTML';
            }
        }
        
        async function updatePreviewFromEditor(type = 'email') {
            if (type === 'email') {
                if (!templateEditor) return;
                
                const content = templateEditor.getContent();
                const preview = document.getElementById('emailTemplatePreview');
                const placeholder = document.getElementById('emailTemplatePreviewPlaceholder');
                
                if (!content || content.trim() === '') {
                    if (placeholder) placeholder.style.display = 'block';
                    if (preview) preview.style.display = 'none';
                    return;
                }
                
                // Ocultar placeholder
                if (placeholder) placeholder.style.display = 'none';
                if (preview) preview.style.display = 'block';
                
                // Variables de ejemplo para el preview
                const variables = {
                    app_name: getAppName(),
                    app_url: window.location.origin,
                    current_year: new Date().getFullYear(),
                    current_date: new Date().toLocaleDateString('es-AR'),
                    current_datetime: new Date().toLocaleString('es-AR'),
                    nombre_usuario: 'Usuario de Ejemplo',
                    token_verificacion: 'token-ejemplo-12345',
                    titulo: 'Notificación',
                    mensaje: 'Este es un mensaje de notificación del sistema.',
                    url_accion: 'https://ejemplo.com',
                    texto_accion: 'Acceder'
                };
                
                try {
                    // Llamar al endpoint para procesar el contenido
                    const response = await fetch('modules/email/api/process-email-content.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        credentials: 'include',
                        body: JSON.stringify({
                            content: content,
                            variables: variables
                        })
                    });
                    
                    if (!response.ok) {
                        throw new Error(`Error HTTP ${response.status}: ${response.statusText}`);
                    }
                    
                    const result = await response.json();
                    
                    if (result.success && result.data && result.data.content) {
                        const previewContent = result.data.content;
                        
                        // Escribir contenido en el iframe de forma segura
                        try {
                            const iframeDoc = preview.contentDocument || preview.contentWindow.document;
                            iframeDoc.open();
                            iframeDoc.write(previewContent);
                            iframeDoc.close();
                            
                            // Ajustar altura del iframe al contenido
                            setTimeout(() => {
                                try {
                                    const iframeBody = iframeDoc.body;
                                    const iframeHtml = iframeDoc.documentElement;
                                    const height = Math.max(
                                        iframeBody.scrollHeight,
                                        iframeBody.offsetHeight,
                                        iframeHtml.clientHeight,
                                        iframeHtml.scrollHeight,
                                        iframeHtml.offsetHeight
                                    );
                                    preview.style.height = Math.min(height + 20, 400) + 'px';
                                } catch (e) {
                                    // Ignorar errores de acceso cross-origin
                                }
                            }, 100);
                        } catch (e) {
                            console.error('Error al escribir en iframe:', e);
                            throw e;
                        }
                    } else {
                        throw new Error(result.error || 'Error al procesar el contenido');
                    }
                } catch (error) {
                    console.error('Error al procesar plantilla:', error);
                    // Fallback: usar reemplazo simple en el cliente
                    let previewContent = content
                        .replace(/\{\{app_name\}\}/g, getAppName())
                        .replace(/\{\{app_url\}\}/g, window.location.origin)
                        .replace(/\{\{current_year\}\}/g, new Date().getFullYear())
                        .replace(/\{\{current_date\}\}/g, new Date().toLocaleDateString('es-AR'))
                        .replace(/\{\{current_datetime\}\}/g, new Date().toLocaleString('es-AR'))
                        .replace(/\{\{nombre_usuario\}\}/g, 'Usuario de Ejemplo')
                        .replace(/\{\{token_verificacion\}\}/g, 'token-ejemplo-12345');
                    
                    try {
                        const iframeDoc = preview.contentDocument || preview.contentWindow.document;
                        iframeDoc.open();
                        iframeDoc.write(previewContent);
                        iframeDoc.close();
                    } catch (e) {
                        console.error('Error al mostrar vista previa:', e);
                        if (preview) preview.style.display = 'none';
                        if (placeholder) {
                            placeholder.style.display = 'block';
                            placeholder.innerHTML = '<p style="color: #d32f2f;">Error al cargar vista previa. Verifique que el HTML sea válido.</p>';
                        }
                    }
                }
            } else {
                // Vista previa para WhatsApp (texto plano)
                const textarea = document.getElementById('whatsappTemplateContent');
                const preview = document.getElementById('whatsappTemplatePreview');
                
                if (!textarea || !preview) return;
                
                let content = textarea.value;
                
                if (!content || content.trim() === '') {
                    preview.innerHTML = '<div style="color: #999; font-style: italic;">La vista previa aparecerá aquí</div>';
                    return;
                }
                
                // Reemplazar variables de ejemplo
                let previewContent = content
                    .replace(/\{\{app_name\}\}/g, getAppName())
                    .replace(/\{\{app_url\}\}/g, window.location.origin)
                    .replace(/\{\{current_year\}\}/g, new Date().getFullYear())
                    .replace(/\{\{current_date\}\}/g, new Date().toLocaleDateString('es-AR'))
                    .replace(/\{\{current_datetime\}\}/g, new Date().toLocaleString('es-AR'))
                    .replace(/\{\{nombre_usuario\}\}/g, 'Usuario de Ejemplo')
                    .replace(/\{\{token_verificacion\}\}/g, 'token-ejemplo-12345');
                
                // Mostrar vista previa estilo WhatsApp
                preview.innerHTML = `<div style="white-space: pre-wrap; word-wrap: break-word; color: #000; font-size: 14px; line-height: 1.5;">${previewContent.replace(/\n/g, '<br>')}</div>`;
            }
        }
        
        function showTab(tabName, clickedButton) {
            // Ocultar todos los tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.nav-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Mostrar tab seleccionado
            const tabContent = document.getElementById('tab-' + tabName);
            if (tabContent) {
                tabContent.classList.add('active');
            }
            
            // Activar el botón correspondiente
            if (clickedButton) {
                clickedButton.classList.add('active');
            } else {
                // Si no hay botón clickeado, buscar el botón correspondiente por su onclick
                const tabs = document.querySelectorAll('.nav-tab');
                tabs.forEach(tab => {
                    const onclick = tab.getAttribute('onclick');
                    if (onclick && onclick.includes("showTab('" + tabName + "')")) {
                        tab.classList.add('active');
                    }
                });
            }
            
            // Cargar plantillas si se abre el tab de plantillas
            if (tabName === 'templates') {
                // Mostrar plantillas de email por defecto
                switchTemplateType('email');
            }
            
            // Cargar sesiones de WhatsApp si se abre el tab de mensajería
            if (tabName === 'whatsapp') {
                loadWhatsAppSessions();
            }
        }
        
        // Al cargar la página, verificar si hay un parámetro 'tab' en la URL
        document.addEventListener('DOMContentLoaded', function() {
            console.log('🔍 [admin.php] DOMContentLoaded - Verificando parámetros de URL...');
            const urlParams = new URLSearchParams(window.location.search);
            const tabParam = urlParams.get('tab');
            const actionParam = urlParams.get('action');
            const savedParam = urlParams.get('saved');
            
            console.log('🔍 [admin.php] tab param:', tabParam);
            console.log('🔍 [admin.php] action param:', actionParam);
            console.log('🔍 [admin.php] saved param:', savedParam);
            
            // Si hay action=save-config pero no tab, mostrar tab de config
            if (actionParam === 'save-config' && !tabParam) {
                console.log('🔍 [admin.php] Action es save-config, mostrando tab config');
                showTab('config');
            } else if (tabParam) {
                console.log('🔍 [admin.php] Mostrando tab desde parámetro:', tabParam);
                showTab(tabParam);
            }
            
            // Si se guardó exitosamente, recargar la página para mostrar los nuevos valores
            if (savedParam === '1') {
                console.log('🔍 [admin.php] Configuración guardada, recargando página para mostrar nuevos valores...');
                
                // Verificar si hay mensaje de error antes de recargar
                const errorMessage = document.querySelector('.alert-error, .alert.alert-error');
                if (errorMessage) {
                    console.log('🔍 [admin.php] Se detectó un mensaje de error, NO recargando automáticamente');
                    // No recargar si hay error, dejar que el usuario vea el mensaje
                    // Solo limpiar el parámetro saved de la URL sin recargar
                    const newUrl = window.location.pathname + '?tab=' + (tabParam || 'config');
                    window.history.replaceState({}, '', newUrl);
                } else {
                    // Solo recargar si no hay errores
                    setTimeout(function() {
                        // Limpiar el parámetro saved de la URL y recargar
                        const newUrl = window.location.pathname + '?tab=' + (tabParam || 'config');
                        window.location.href = newUrl;
                    }, 2000); // Aumentar delay para que el usuario pueda ver el mensaje de éxito
                }
            }
        });
        
        function testConnection() {
            if (confirm('¿Desea probar la conexión SMTP con la configuración actual?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '?action=test-connection';
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Funciones del editor de plantillas
        function switchTemplateType(type) {
            currentTemplateType = type;
            
            // Actualizar botones
            const emailBtn = document.getElementById('templateTypeEmail');
            const whatsappBtn = document.getElementById('templateTypeWhatsApp');
            const emailSection = document.getElementById('emailTemplatesSection');
            const whatsappSection = document.getElementById('whatsappTemplatesSection');
            
            if (type === 'email') {
                emailBtn.classList.remove('btn-secondary');
                emailBtn.classList.add('btn-primary');
                whatsappBtn.classList.remove('btn-primary');
                whatsappBtn.classList.add('btn-secondary');
                emailSection.style.display = 'block';
                whatsappSection.style.display = 'none';
                // Inicializar TinyMCE si no está inicializado
                setTimeout(() => {
                    if (!templateEditor) {
                        initTinyMCE();
                    }
                }, 100);
            } else {
                whatsappBtn.classList.remove('btn-secondary');
                whatsappBtn.classList.add('btn-primary');
                emailBtn.classList.remove('btn-primary');
                emailBtn.classList.add('btn-secondary');
                whatsappSection.style.display = 'block';
                emailSection.style.display = 'none';
            }
            
            // Cargar lista de plantillas
            if (type === 'email') {
                loadTemplatesList('email');
            } else {
                loadTemplatesList('whatsapp');
            }
        }
        
        async function loadTemplatesList(type = 'email') {
            try {
                const apiUrl = type === 'email' ? apiBase : whatsappApiBase;
                const listDivId = type === 'email' ? 'emailTemplatesList' : 'whatsappTemplatesList';
                
                const response = await fetch(apiUrl + '?action=list');
                const result = await response.json();
                
                if (result.success) {
                    const listDiv = document.getElementById(listDivId);
                    if (!listDiv) return;
                    
                    listDiv.innerHTML = '';
                    
                    result.data.forEach(tpl => {
                        const item = document.createElement('div');
                        item.className = `template-item ${type}-template-item`;
                        item.setAttribute('data-template-name', tpl.name);
                        const borderColor = type === 'email' ? '#667eea' : '#25d366';
                        item.style.cssText = 'padding: 10px; margin-bottom: 5px; background: white; border: 1px solid #ddd; border-radius: 3px; cursor: pointer; transition: all 0.2s;';
                        item.onmouseover = function() {
                            if (!this.classList.contains('selected')) {
                                this.style.background = '#e9ecef';
                                this.style.borderColor = borderColor;
                            }
                        };
                        item.onmouseout = function() {
                            if (!this.classList.contains('selected')) {
                                this.style.background = 'white';
                                this.style.borderColor = '#ddd';
                            }
                        };
                        item.onclick = () => selectTemplate(tpl.name, item, type);
                        
                        item.innerHTML = `
                            <div style="display: flex; justify-content: space-between; align-items: start;">
                                <div style="flex: 1;">
                                    <strong>${tpl.name}</strong>
                                    <div style="font-size: 11px; color: #666; margin-top: 5px;">
                                        ${(tpl.size / 1024).toFixed(2)} KB · 
                                        ${new Date(tpl.modified * 1000).toLocaleString('es-AR')}
                                    </div>
                                </div>
                                <button onclick="event.stopPropagation(); loadTemplate('${tpl.name}', '${type}')" 
                                        class="btn btn-sm btn-outline-primary" 
                                        style="padding: 2px 8px; font-size: 11px;"
                                        title="Editar plantilla">
                                    <i class="fas fa-edit"></i>
                                </button>
                            </div>
                        `;
                        
                        listDiv.appendChild(item);
                    });
                }
            } catch (error) {
                alert('Error al cargar plantillas: ' + error.message);
            }
        }
        
        function selectTemplate(templateName, element, type = 'email') {
            // Remover selección anterior del mismo tipo
            const selector = type === 'email' ? '.email-template-item' : '.whatsapp-template-item';
            document.querySelectorAll(selector).forEach(item => {
                item.classList.remove('selected');
                item.style.background = 'white';
                item.style.borderColor = '#ddd';
                item.style.borderLeft = '1px solid #ddd';
            });
            
            // Marcar como seleccionado
            if (element) {
                element.classList.add('selected');
                const borderColor = type === 'email' ? '#667eea' : '#25d366';
                element.style.background = type === 'email' ? '#f0f4ff' : '#dcf8c6';
                element.style.borderColor = borderColor;
                element.style.borderLeft = `4px solid ${borderColor}`;
            }
            
            // Cargar plantilla en el editor
            loadTemplate(templateName, type);
        }
        
        async function loadTemplate(name, type = 'email') {
            try {
                const apiUrl = type === 'email' ? apiBase : whatsappApiBase;
                const nameInputId = type === 'email' ? 'emailTemplateName' : 'whatsappTemplateName';
                const contentId = type === 'email' ? 'emailTemplateContent' : 'whatsappTemplateContent';
                const deleteBtnId = type === 'email' ? 'deleteEmailTemplateBtn' : 'deleteWhatsAppTemplateBtn';
                
                // Mostrar indicador de carga
                const nameInput = document.getElementById(nameInputId);
                if (nameInput) {
                    nameInput.disabled = true;
                    nameInput.value = 'Cargando...';
                }
                
                const response = await fetch(apiUrl + '?action=get&name=' + encodeURIComponent(name));
                const result = await response.json();
                
                if (result.success) {
                    currentTemplateName = result.data.name;
                    currentTemplateType = type;
                    if (nameInput) {
                        nameInput.value = result.data.name;
                        nameInput.disabled = false;
                    }
                    
                    if (type === 'email') {
                        // Cargar contenido en TinyMCE si está disponible
                        if (templateEditor && templateEditor.initialized) {
                            templateEditor.setContent(result.data.content || '');
                        } else {
                            // Si TinyMCE no está listo, establecer en textarea y esperar
                            const textarea = document.getElementById(contentId);
                            if (textarea) {
                                textarea.value = result.data.content || '';
                            }
                            
                            // Intentar inicializar TinyMCE si no está inicializado
                            if (!templateEditor) {
                                setTimeout(() => {
                                    initTinyMCE();
                                    setTimeout(() => {
                                        if (templateEditor) {
                                            templateEditor.setContent(result.data.content || '');
                                        }
                                    }, 300);
                                }, 100);
                            } else {
                                setTimeout(() => {
                                    if (templateEditor) {
                                        templateEditor.setContent(result.data.content || '');
                                    }
                                }, 300);
                            }
                        }
                        
                        // Actualizar vista previa después de cargar
                        setTimeout(() => {
                            updatePreviewFromEditor('email');
                        }, 500);
                    } else {
                        // Para WhatsApp, usar textarea simple
                        const textarea = document.getElementById(contentId);
                        if (textarea) {
                            textarea.value = result.data.content || '';
                        }
                        
                        // Actualizar vista previa de WhatsApp
                        setTimeout(() => {
                            updatePreviewFromEditor('whatsapp');
                        }, 100);
                    }
                    
                    const deleteBtn = document.getElementById(deleteBtnId);
                    if (deleteBtn) {
                        deleteBtn.style.display = 'inline-block';
                    }
                } else {
                    if (nameInput) nameInput.disabled = false;
                    alert('Error: ' + result.error);
                }
            } catch (error) {
                const nameInputId = type === 'email' ? 'emailTemplateName' : 'whatsappTemplateName';
                const nameInput = document.getElementById(nameInputId);
                if (nameInput) nameInput.disabled = false;
                alert('Error al cargar plantilla: ' + error.message);
            }
        }
        
        async function saveTemplate(type = 'email') {
            const nameInputId = type === 'email' ? 'emailTemplateName' : 'whatsappTemplateName';
            const contentId = type === 'email' ? 'emailTemplateContent' : 'whatsappTemplateContent';
            const deleteBtnId = type === 'email' ? 'deleteEmailTemplateBtn' : 'deleteWhatsAppTemplateBtn';
            
            const name = document.getElementById(nameInputId).value.trim();
            // Obtener contenido de TinyMCE si está disponible, sino del textarea
            let content;
            if (type === 'email' && templateEditor && templateEditor.initialized) {
                content = templateEditor.getContent();
            } else {
                const textarea = document.getElementById(contentId);
                content = textarea ? textarea.value : '';
            }
            
            if (!name) {
                alert('El nombre de la plantilla es requerido');
                document.getElementById(nameInputId).focus();
                return;
            }
            
            if (!content || content.trim() === '') {
                alert('El contenido de la plantilla es requerido');
                return;
            }
            
            if (!/^[a-zA-Z0-9_-]+$/.test(name)) {
                alert('El nombre solo puede contener letras, números, guiones y guiones bajos');
                document.getElementById(nameInputId).focus();
                return;
            }
            
            try {
                const apiUrl = type === 'email' ? apiBase : whatsappApiBase;
                
                // Mostrar indicador de guardado
                const saveBtn = document.querySelector(`button[onclick="saveTemplate('${type}')"]`);
                const originalText = saveBtn ? saveBtn.innerHTML : '';
                if (saveBtn) {
                    saveBtn.setAttribute('data-original-text', originalText);
                    saveBtn.disabled = true;
                    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...';
                }
                
                const method = currentTemplateName && currentTemplateType === type ? 'PUT' : 'POST';
                const response = await fetch(apiUrl, {
                    method: method,
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ name: name, content: content })
                });
                
                if (!response.ok) {
                    const errorText = await response.text();
                    console.error('Error HTTP:', response.status, errorText);
                    throw new Error(`Error HTTP ${response.status}: ${errorText}`);
                }
                
                const result = await response.json();
                
                if (saveBtn) {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = originalText;
                }
                
                if (result.success) {
                    // Mostrar mensaje de éxito
                    showSuccessMessage('Plantilla guardada exitosamente');
                    currentTemplateName = name;
                    currentTemplateType = type;
                    const deleteBtn = document.getElementById(deleteBtnId);
                    if (deleteBtn) {
                        deleteBtn.style.display = 'inline-block';
                    }
                    
                    // Actualizar lista de plantillas
                    await loadTemplatesList(type);
                    
                    // Resaltar la plantilla guardada
                    setTimeout(() => {
                        const savedItem = document.querySelector(`[data-template-name="${name}"].${type}-template-item`);
                        if (savedItem) {
                            selectTemplate(name, savedItem, type);
                        }
                    }, 100);
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (error) {
                console.error('Error al guardar plantilla:', error);
                const saveBtn = document.querySelector(`button[onclick="saveTemplate('${type}')"]`);
                if (saveBtn) {
                    saveBtn.disabled = false;
                    const originalText = saveBtn.getAttribute('data-original-text');
                    if (originalText) {
                        saveBtn.innerHTML = originalText;
                    }
                }
                const errorMessage = error.message || 'Error desconocido al guardar la plantilla';
                alert('Error: ' + errorMessage);
            }
        }
        
        function showSuccessMessage(message) {
            // Crear o actualizar mensaje de éxito
            let toast = document.getElementById('templateSaveToast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'templateSaveToast';
                toast.style.cssText = 'position: fixed; top: 20px; right: 20px; background: #28a745; color: white; padding: 12px 20px; border-radius: 5px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 10001; animation: slideIn 0.3s ease;';
                document.body.appendChild(toast);
            }
            
            toast.innerHTML = `<i class="fas fa-check"></i> ${message}`;
            toast.style.display = 'block';
            
            setTimeout(() => {
                toast.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => {
                    toast.style.display = 'none';
                }, 300);
            }, 2000);
        }
        
        function previewTemplate(type = 'email') {
            // Usar la función actualizada que obtiene el contenido del editor
            updatePreviewFromEditor(type);
        }
        
        async function deleteTemplate(type = 'email') {
            if (!currentTemplateName || currentTemplateType !== type) {
                alert('No hay plantilla seleccionada');
                return;
            }
            
            if (!confirm('¿Está seguro de eliminar la plantilla "' + currentTemplateName + '"?')) {
                return;
            }
            
            try {
                const apiUrl = type === 'email' ? apiBase : whatsappApiBase;
                const response = await fetch(apiUrl, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ name: currentTemplateName })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('Plantilla eliminada exitosamente');
                    clearTemplate(type);
                    loadTemplatesList(type);
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (error) {
                alert('Error al eliminar: ' + error.message);
            }
        }
        
        function clearTemplate(type = 'email') {
            currentTemplateName = null;
            const nameInputId = type === 'email' ? 'emailTemplateName' : 'whatsappTemplateName';
            const contentId = type === 'email' ? 'emailTemplateContent' : 'whatsappTemplateContent';
            const deleteBtnId = type === 'email' ? 'deleteEmailTemplateBtn' : 'deleteWhatsAppTemplateBtn';
            
            const nameInput = document.getElementById(nameInputId);
            if (nameInput) nameInput.value = '';
            
            // Limpiar contenido
            if (type === 'email' && templateEditor) {
                templateEditor.setContent('');
            } else {
                const textarea = document.getElementById(contentId);
                if (textarea) textarea.value = '';
            }
            
            const deleteBtn = document.getElementById(deleteBtnId);
            if (deleteBtn) deleteBtn.style.display = 'none';
            
            // Limpiar vista previa
            if (type === 'email') {
                const preview = document.getElementById('emailTemplatePreview');
                const placeholder = document.getElementById('emailTemplatePreviewPlaceholder');
                if (preview && placeholder) {
                    try {
                        const iframeDoc = preview.contentDocument || preview.contentWindow.document;
                        iframeDoc.open();
                        iframeDoc.write('');
                        iframeDoc.close();
                    } catch (e) {
                        // Ignorar errores
                    }
                    preview.style.display = 'none';
                    placeholder.style.display = 'block';
                    placeholder.innerHTML = 'La vista previa aparecerá aquí';
                }
            } else {
                const preview = document.getElementById('whatsappTemplatePreview');
                if (preview) {
                    preview.innerHTML = '<div style="color: #999; font-style: italic;">La vista previa aparecerá aquí</div>';
                }
            }
        }
        
        function createNewTemplate(type = 'email') {
            clearTemplate(type);
            const nameInputId = type === 'email' ? 'emailTemplateName' : 'whatsappTemplateName';
            const nameInput = document.getElementById(nameInputId);
            if (nameInput) nameInput.focus();
        }
        
        function updateTemplateName(type = 'email') {
            if (currentTemplateName && currentTemplateType === type) {
                const nameInputId = type === 'email' ? 'emailTemplateName' : 'whatsappTemplateName';
                const nameInput = document.getElementById(nameInputId);
                if (nameInput) {
                    currentTemplateName = nameInput.value.trim();
                }
            }
        }
        
        // Función para obtener el app_name actual
        function getAppName() {
            const appNameInput = document.getElementById('app_name_input');
            return appNameInput ? appNameInput.value : 'TJS Medical - Portal de Estudios';
        }
        
        const availableVariables = [
            {
                variable: '{{app_name}}',
                description: 'Nombre de la aplicación (configurable en Configuración SMTP)',
                category: 'Sistema',
                getExample: () => getAppName()
            },
            {
                variable: '{{app_url}}',
                description: 'URL base de la aplicación',
                category: 'Sistema',
                example: 'https://idimagenes.tanjousoft.com.ar'
            },
            {
                variable: '{{current_year}}',
                description: 'Año actual',
                category: 'Fecha/Hora',
                example: '2025'
            },
            {
                variable: '{{current_date}}',
                description: 'Fecha actual en formato dd/mm/yyyy',
                category: 'Fecha/Hora',
                example: '05/12/2025'
            },
            {
                variable: '{{current_datetime}}',
                description: 'Fecha y hora actual completa',
                category: 'Fecha/Hora',
                example: '05/12/2025 11:30:00'
            },
            {
                variable: '{{nombre_usuario}}',
                description: 'Nombre completo del usuario',
                category: 'Usuario',
                example: 'Juan Pérez'
            },
            {
                variable: '{{email}}',
                description: 'Email del destinatario',
                category: 'Usuario',
                example: 'usuario@ejemplo.com'
            },
            {
                variable: '{{nombre}}',
                description: 'Nombre del destinatario',
                category: 'Usuario',
                example: 'María González'
            },
            {
                variable: '{{token_verificacion}}',
                description: 'Token de verificación de email',
                category: 'Seguridad',
                example: 'abc123xyz789'
            },
            {
                variable: '{{id_interno}}',
                description: 'ID interno del paciente',
                category: 'Paciente',
                example: 'PAC-20251205-001'
            },
            {
                variable: '{{paciente_nombre}}',
                description: 'Nombre del paciente',
                category: 'Paciente',
                example: 'Carlos Rodríguez'
            },
            {
                variable: '{{codigo_acceso}}',
                description: 'Código de acceso del paciente (alias de id_interno)',
                category: 'Paciente',
                example: 'PAC-20251205-001'
            },
            {
                variable: '{{url_portal}}',
                description: 'URL base del portal de pacientes (sin parámetros)',
                category: 'URLs',
                getExample: () => window.location.origin + window.location.pathname.replace(/[^/]*$/, 'paciente.html')
            },
            {
                variable: '{{url_portal_con_acceso}}',
                description: 'URL del portal con parámetro para acceso directo al modal de estudios',
                category: 'URLs',
                getExample: () => {
                    const baseUrl = window.location.origin + window.location.pathname.replace(/[^/]*$/, 'paciente.html');
                    return `${baseUrl}?id_interno=PAC-20251205-001`;
                }
            },
            {
                variable: '{{url_accion}}',
                description: 'URL de acción (usa url_portal_con_acceso por defecto)',
                category: 'URLs',
                getExample: () => {
                    const baseUrl = window.location.origin + window.location.pathname.replace(/[^/]*$/, 'paciente.html');
                    return `${baseUrl}?id_interno=PAC-20251205-001`;
                }
            },
            {
                variable: '{{texto_accion}}',
                description: 'Texto del botón o enlace de acción',
                category: 'Acciones',
                example: 'Acceder al Portal'
            },
            {
                variable: '{{titulo}}',
                description: 'Título del mensaje o notificación',
                category: 'Mensaje',
                example: 'Acceso a sus estudios médicos'
            },
            {
                variable: '{{mensaje}}',
                description: 'Mensaje personalizado del email/WhatsApp',
                category: 'Mensaje',
                example: 'Puede ingresar usando su Documento o ID Interno para visualizar sus estudios.'
            },
            {
                variable: '{{estudio_tipo}}',
                description: 'Tipo de estudio médico',
                category: 'Estudio',
                example: 'Radiografía de Tórax'
            }
        ];
        
        function showVariables(type = 'email') {
            const modal = document.getElementById('variablesModal');
            const listDiv = document.getElementById('variablesList');
            const description = document.getElementById('variablesModalDescription');
            const title = document.getElementById('variablesModalTitle');
            
            // Actualizar título y descripción según el tipo
            if (type === 'whatsapp') {
                title.innerHTML = '<i class="fas fa-code" style="font-size: 1.2em;"></i> Variables Disponibles - WhatsApp';
                description.textContent = 'Puedes usar estas variables en tus plantillas de WhatsApp. Se reemplazarán automáticamente con los valores correspondientes al enviar el mensaje.';
            } else {
                title.innerHTML = '<i class="fas fa-code" style="font-size: 1.2em;"></i> Variables Disponibles - Email';
                description.textContent = 'Puedes usar estas variables en tus plantillas de email. Se reemplazarán automáticamente con los valores correspondientes al enviar el email.';
            }
            
            // Agrupar variables por categoría
            const grouped = {};
            availableVariables.forEach(v => {
                if (!grouped[v.category]) {
                    grouped[v.category] = [];
                }
                grouped[v.category].push(v);
            });
            
            // Generar HTML
            let html = '';
            Object.keys(grouped).sort().forEach(category => {
                html += `
                    <div style="margin-bottom: 25px;">
                        <h3 style="color: #667eea; font-size: 1.1em; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 2px solid #e0e0e0;">
                            <i class="fas fa-tag" style="margin-right: 8px;"></i>${category}
                        </h3>
                        <div style="display: grid; gap: 10px;">
                `;
                
                grouped[category].forEach(v => {
                    // Obtener ejemplo (puede ser función o valor directo)
                    const example = typeof v.getExample === 'function' ? v.getExample() : (v.example || 'N/A');
                    
                    html += `
                        <div style="background: #f8f9fa; border-left: 4px solid #667eea; padding: 15px; border-radius: 5px; transition: transform 0.2s, box-shadow 0.2s;" 
                             onmouseover="this.style.transform='translateX(5px)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.1)'" 
                             onmouseout="this.style.transform='translateX(0)'; this.style.boxShadow='none'">
                            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px;">
                                <code style="background: #667eea; color: white; padding: 5px 10px; border-radius: 4px; font-size: 0.95em; font-weight: 600; cursor: pointer;" 
                                      onclick="copyVariable('${v.variable}')" 
                                      title="Clic para copiar"
                                      onmouseover="this.style.background='#5568d3'" 
                                      onmouseout="this.style.background='#667eea'">
                                    ${v.variable}
                                </code>
                                <button onclick="copyVariable('${v.variable}')" 
                                        style="background: transparent; border: 1px solid #ddd; color: #666; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 0.85em; transition: all 0.2s;"
                                        onmouseover="this.style.background='#667eea'; this.style.borderColor='#667eea'; this.style.color='white'"
                                        onmouseout="this.style.background='transparent'; this.style.borderColor='#ddd'; this.style.color='#666'">
                                    <i class="fas fa-copy"></i> Copiar
                                </button>
                            </div>
                            <p style="color: #555; margin: 0 0 5px 0; font-size: 0.95em;">${v.description}</p>
                            <p style="color: #999; margin: 0; font-size: 0.85em; font-style: italic;">
                                Ejemplo: <span style="color: #667eea;">${example}</span>
                            </p>
                        </div>
                    `;
                });
                
                html += `
                        </div>
                    </div>
                `;
            });
            
            listDiv.innerHTML = html;
            modal.style.display = 'flex';
            
            // Cerrar al hacer clic fuera del modal
            modal.onclick = function(e) {
                if (e.target === modal) {
                    closeVariablesModal();
                }
            };
        }
        
        function closeVariablesModal() {
            document.getElementById('variablesModal').style.display = 'none';
        }
        
        function copyVariable(variable) {
            navigator.clipboard.writeText(variable).then(() => {
                // Mostrar feedback visual
                const event = new CustomEvent('variableCopied', { detail: variable });
                document.dispatchEvent(event);
                
                // Feedback temporal
                const toast = document.createElement('div');
                toast.style.cssText = 'position: fixed; top: 20px; right: 20px; background: #28a745; color: white; padding: 12px 20px; border-radius: 5px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 10001; animation: slideIn 0.3s ease;';
                toast.innerHTML = `<i class="fas fa-check"></i> Copiado: <code>${variable}</code>`;
                document.body.appendChild(toast);
                
                setTimeout(() => {
                    toast.style.animation = 'slideOut 0.3s ease';
                    setTimeout(() => toast.remove(), 300);
                }, 2000);
            }).catch(err => {
                alert('Error al copiar: ' + err);
            });
        }
        
        function copyAllVariables() {
            const allVars = availableVariables.map(v => v.variable).join('\n');
            navigator.clipboard.writeText(allVars).then(() => {
                const toast = document.createElement('div');
                toast.style.cssText = 'position: fixed; top: 20px; right: 20px; background: #28a745; color: white; padding: 12px 20px; border-radius: 5px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 10001; animation: slideIn 0.3s ease;';
                toast.innerHTML = '<i class="fas fa-check"></i> Todas las variables copiadas';
                document.body.appendChild(toast);
                
                setTimeout(() => {
                    toast.style.animation = 'slideOut 0.3s ease';
                    setTimeout(() => toast.remove(), 300);
                }, 2000);
            });
        }
        
        // Agregar estilos de animación
        if (!document.getElementById('variablesModalStyles')) {
            const style = document.createElement('style');
            style.id = 'variablesModalStyles';
            style.textContent = `
                @keyframes slideIn {
                    from {
                        transform: translateX(100%);
                        opacity: 0;
                    }
                    to {
                        transform: translateX(0);
                        opacity: 1;
                    }
                }
                @keyframes slideOut {
                    from {
                        transform: translateX(0);
                        opacity: 1;
                    }
                    to {
                        transform: translateX(100%);
                        opacity: 0;
                    }
                }
            `;
            document.head.appendChild(style);
        }
        
        // La vista previa se actualiza automáticamente desde el evento 'change' de TinyMCE
        // ============================================
        // Funciones para WhatsApp
        // ============================================
        
        /**
         * Obtiene la URL base para las APIs de WhatsApp
         * Construye la URL de nuestro servidor PHP (no de WAHA)
         * La URL de WAHA se usa solo en el backend PHP
         */
        function getWhatsAppApiUrl(endpoint) {
            // Usar URL absoluta desde el origen del servidor actual
            // La configuración de WAHA (baseUrl, apiKey) se usa en el backend PHP
            // no en el frontend JavaScript
            return window.location.origin + '/modules/whatsapp/api/' + endpoint;
        }
        
        /**
         * Obtiene la configuración de WAHA para usar en el frontend
         */
        function getWahaConfig() {
            return window.wahaConfig || {
                baseUrl: 'http://localhost:3000',
                apiKey: '',
                defaultSession: 'default'
            };
        }
        
        async function loadWhatsAppSessions() {
            const container = document.getElementById('whatsappSessionsList');
            if (!container) return;
            
            container.innerHTML = '<div class="text-center" style="padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Cargando...</div>';
            
            try {
                const token = await getAuthToken();
                const response = await fetch(getWhatsAppApiUrl('sessions.php'), {
                    method: 'GET',
                    headers: {
                        'Authorization': token ? `Bearer ${token}` : ''
                    },
                    credentials: 'include'
                });
                
                if (!response.ok) {
                    throw new Error(`Error HTTP: ${response.status}`);
                }
                
                const result = await response.json();
                
                if (!result.success) {
                    throw new Error(result.error || 'Error al cargar sesiones');
                }
                
                const sessions = result.data.sessions || [];
                renderWhatsAppSessions(sessions);
                
            } catch (error) {
                console.error('Error cargando sesiones:', error);
                container.innerHTML = `
                    <div class="alert alert-error" style="background: #fee; color: #c33; padding: 15px; border-radius: 5px; border-left: 4px solid #c33;">
                        <strong>Error:</strong> ${error.message}
                    </div>
                `;
            }
        }
        
        function renderWhatsAppSessions(sessions) {
            const container = document.getElementById('whatsappSessionsList');
            if (!container) return;
            
            if (sessions.length === 0) {
                container.innerHTML = `
                    <div class="alert alert-info" style="background: #e7f3ff; color: #1976d2; padding: 20px; border-radius: 5px; text-align: center;">
                        <i class="fas fa-info-circle" style="font-size: 2em; margin-bottom: 10px;"></i>
                        <p>No hay sesiones de WhatsApp creadas.</p>
                        <p>Haz clic en "Crear Nueva Sesión" para comenzar.</p>
                    </div>
                `;
                return;
            }
            
            let html = '<div style="display: grid; gap: 15px;">';
            
            sessions.forEach(session => {
                const sessionName = session.name || session.session || session || 'unknown';
                
                // WAHA puede retornar el estado en diferentes formatos
                let status = 'unknown';
                if (typeof session === 'string') {
                    // Si la sesión es solo un string, es el nombre
                    status = 'unknown';
                } else if (session.status) {
                    if (typeof session.status === 'string') {
                        status = session.status;
                    } else if (session.status.state) {
                        status = session.status.state;
                    } else if (session.status.status) {
                        status = session.status.status;
                    }
                } else if (session.state) {
                    if (typeof session.state === 'string') {
                        status = session.state;
                    } else if (session.state.state) {
                        status = session.state.state;
                    }
                }
                
                // Normalizar el estado a minúsculas para comparación
                const statusLower = status.toLowerCase();
                const isConnected = ['open', 'connected', 'authenticated', 'ready'].includes(statusLower);
                
                html += `
                    <div style="background: white; border: 2px solid ${isConnected ? '#4caf50' : '#ff9800'}; border-radius: 8px; padding: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px;">
                            <div>
                                <h3 style="margin: 0 0 5px 0; color: #333;">
                                    <i class="fab fa-whatsapp" style="color: ${isConnected ? '#25d366' : '#999'}; margin-right: 8px;"></i>
                                    ${sessionName}
                                </h3>
                                <p style="margin: 0; color: #666; font-size: 0.9em;">
                                    Estado: <strong style="color: ${isConnected ? '#4caf50' : '#ff9800'};">${status}</strong>
                                </p>
                            </div>
                            <div style="display: flex; gap: 10px;">
                                ${!isConnected ? `
                                    <button onclick="showQRCode('${sessionName}')" class="btn btn-primary" style="padding: 8px 15px; font-size: 0.9em;">
                                        <i class="fas fa-qrcode"></i> Ver QR
                                    </button>
                                ` : ''}
                                <button onclick="deleteWhatsAppSession('${sessionName}')" class="btn btn-danger" style="padding: 8px 15px; font-size: 0.9em;">
                                    <i class="fas fa-trash"></i> Eliminar
                                </button>
                            </div>
                        </div>
                        ${!isConnected ? `
                            <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 10px; border-radius: 4px; margin-top: 10px;">
                                <p style="margin: 0; color: #856404; font-size: 0.9em;">
                                    <i class="fas fa-exclamation-triangle"></i> 
                                    Esta sesión no está autenticada. Escanea el código QR para conectar.
                                </p>
                            </div>
                        ` : `
                            <div style="background: #d4edda; border-left: 4px solid #28a745; padding: 10px; border-radius: 4px; margin-top: 10px;">
                                <p style="margin: 0; color: #155724; font-size: 0.9em;">
                                    <i class="fas fa-check-circle"></i> 
                                    Sesión conectada y lista para enviar mensajes.
                                </p>
                            </div>
                        `}
                    </div>
                `;
            });
            
            html += '</div>';
            container.innerHTML = html;
        }
        
        async function createWhatsAppSession() {
            const sessionName = prompt('Ingrese el nombre de la nueva sesión:', 'default');
            
            if (!sessionName || sessionName.trim() === '') {
                return;
            }
            
            try {
                const token = await getAuthToken();
                const response = await fetch(getWhatsAppApiUrl('sessions.php'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': token ? `Bearer ${token}` : ''
                    },
                    credentials: 'include',
                    body: JSON.stringify({ name: sessionName.trim() })
                });
                
                if (!response.ok) {
                    throw new Error(`Error HTTP: ${response.status}`);
                }
                
                const result = await response.json();
                
                if (!result.success) {
                    throw new Error(result.error || 'Error al crear sesión');
                }
                
                alert('Sesión creada exitosamente. Ahora puedes escanear el código QR para autenticarla.');
                loadWhatsAppSessions();
                
                // Mostrar QR automáticamente
                setTimeout(() => {
                    showQRCode(sessionName.trim());
                }, 500);
                
            } catch (error) {
                console.error('Error creando sesión:', error);
                alert('Error al crear sesión: ' + error.message);
            }
        }
        
        async function showQRCode(sessionName) {
            // Crear modal para mostrar QR
            const modal = document.createElement('div');
            modal.id = 'qrModal';
            modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 10000; display: flex; align-items: center; justify-content: center;';
            
            modal.innerHTML = `
                <div style="background: white; border-radius: 10px; padding: 30px; max-width: 500px; width: 90%; text-align: center; box-shadow: 0 10px 40px rgba(0,0,0,0.3);">
                    <h2 style="margin: 0 0 20px 0; color: #333;">
                        <i class="fas fa-qrcode" style="color: #25d366; margin-right: 10px;"></i>
                        Escanear Código QR
                    </h2>
                        <p style="color: #666; margin-bottom: 20px;">
                        Escanea este código QR con WhatsApp para autenticar la sesión "<strong>${sessionName}</strong>"
                    </p>
                    <div id="qrCodeContainer" style="background: white; padding: 20px; border: 2px solid #ddd; border-radius: 8px; margin: 20px 0; min-height: 300px; display: flex; align-items: center; justify-content: center;">
                        <div>
                            <i class="fas fa-spinner fa-spin" style="font-size: 3em; color: #999;"></i>
                            <p class="qr-loading-msg" style="margin-top: 10px; color: #999;">Cargando código QR...</p>
                        </div>
                    </div>
                    <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 10px; border-radius: 4px; margin-top: 10px;">
                        <p style="margin: 0; color: #856404; font-size: 0.9em;">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Importante:</strong> El código QR expira en aproximadamente 20 segundos. 
                            Si no puedes escanearlo, haz clic en "Actualizar QR" para obtener uno nuevo.
                        </p>
                    </div>
                    <div style="margin-top: 20px;">
                        <button onclick="closeQRModal()" class="btn btn-secondary" style="padding: 10px 20px;">
                            Cerrar
                        </button>
                        <button onclick="refreshQRCode('${sessionName}')" class="btn btn-primary" style="padding: 10px 20px; margin-left: 10px;">
                            <i class="fas fa-sync"></i> Actualizar QR
                        </button>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // Cargar QR code
            await loadQRCode(sessionName);
            
            // Auto-refresh cada 20 segundos si no está conectado
            // Los QR de WhatsApp expiran rápidamente, así que necesitamos actualizarlos frecuentemente
            // Pero no demasiado rápido para evitar sobrecarga
            const qrInterval = setInterval(async () => {
                const status = await checkSessionStatus(sessionName);
                if (status && (status === 'open' || status === 'connected' || status === 'authenticated')) {
                    clearInterval(qrInterval);
                    alert('¡Sesión autenticada exitosamente!');
                    closeQRModal();
                    loadWhatsAppSessions();
                } else {
                    // Actualizar QR con un pequeño delay para mostrar que se está actualizando
                    const container = document.getElementById('qrCodeContainer');
                    if (container) {
                        const loadingMsg = container.querySelector('.qr-loading-msg');
                        if (loadingMsg) {
                            loadingMsg.textContent = 'Actualizando QR...';
                        }
                    }
                    await loadQRCode(sessionName);
                }
            }, 20000); // 20 segundos - tiempo típico de expiración de QR de WhatsApp
            
            // Guardar intervalo para limpiarlo al cerrar
            modal.dataset.interval = qrInterval;
        }
        
        async function loadQRCode(sessionName) {
            const container = document.getElementById('qrCodeContainer');
            if (!container) return;
            
            try {
                const token = await getAuthToken();
                // Usar la misma función que se usa para sessions.php para mantener consistencia
                const qrCodeUrl = getWhatsAppApiUrl('qrcode.php') + '?session=' + encodeURIComponent(sessionName);
                console.log('🔍 [loadQRCode] URL del QR:', qrCodeUrl);
                console.log('🔍 [loadQRCode] Pathname:', window.location.pathname);
                
                const response = await fetch(qrCodeUrl, {
                    method: 'GET',
                    headers: {
                        'Authorization': token ? `Bearer ${token}` : ''
                    },
                    credentials: 'include'
                });
                
                console.log('🔍 [loadQRCode] Response status:', response.status);
                console.log('🔍 [loadQRCode] Response headers:', response.headers);
                
                if (!response.ok) {
                    // Intentar leer el texto de la respuesta para ver qué error retorna
                    const errorText = await response.text();
                    console.error('🔍 [loadQRCode] Error response:', errorText);
                    throw new Error(`Error HTTP: ${response.status} - ${errorText.substring(0, 200)}`);
                }
                
                // Leer el texto primero para verificar que sea JSON válido
                const responseText = await response.text();
                console.log('🔍 [loadQRCode] Response text (primeros 500 chars):', responseText.substring(0, 500));
                
                if (!responseText || responseText.trim() === '') {
                    throw new Error('Respuesta vacía del servidor');
                }
                
                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (e) {
                    console.error('🔍 [loadQRCode] Error parseando JSON:', e);
                    console.error('🔍 [loadQRCode] Response completo:', responseText);
                    throw new Error('Respuesta del servidor no es JSON válido. Puede haber un error en el servidor PHP.');
                }
                
                if (!result.success) {
                    // Si el error indica que la sesión ya está autenticada, mostrar mensaje apropiado
                    if (result.error && (result.error.includes('autenticada') || result.error.includes('no requiere'))) {
                        container.innerHTML = `
                            <div style="color: #4caf50; text-align: center;">
                                <i class="fas fa-check-circle" style="font-size: 3em; margin-bottom: 15px; color: #4caf50;"></i>
                                <h3 style="color: #4caf50; margin: 0 0 10px 0;">¡Sesión Autenticada!</h3>
                                <p style="margin: 0; color: #666;">La sesión "${sessionName}" ya está autenticada y lista para usar.</p>
                                <p style="margin: 10px 0 0 0; color: #999; font-size: 0.9em;">No se requiere código QR.</p>
                            </div>
                        `;
                        return;
                    }
                    throw new Error(result.error || 'Error al obtener QR code');
                }
                
                const qrData = result.data.qr || result.data.data || result.data.qrcode;
                
                if (!qrData) {
                    container.innerHTML = `
                        <div style="color: #4caf50; text-align: center;">
                            <i class="fas fa-check-circle" style="font-size: 3em; margin-bottom: 15px; color: #4caf50;"></i>
                            <h3 style="color: #4caf50; margin: 0 0 10px 0;">¡Sesión Autenticada!</h3>
                            <p style="margin: 0; color: #666;">La sesión "${sessionName}" ya está autenticada y lista para usar.</p>
                            <p style="margin: 10px 0 0 0; color: #999; font-size: 0.9em;">No se requiere código QR.</p>
                        </div>
                    `;
                    return;
                }
                
                // WAHA puede retornar el QR en diferentes formatos
                // Si es base64, mostrar imagen
                // Si es URL, usar directamente
                let qrImage = '';
                
                if (qrData.startsWith('data:image')) {
                    qrImage = qrData;
                } else if (qrData.startsWith('http')) {
                    qrImage = qrData;
                } else if (qrData.startsWith('/9j/') || qrData.startsWith('iVBOR')) {
                    // Base64 sin prefijo
                    qrImage = 'data:image/png;base64,' + qrData;
                } else {
                    // Intentar como base64
                    qrImage = 'data:image/png;base64,' + qrData;
                }
                
                // Verificar que la imagen sea válida antes de mostrarla
                console.log('🔍 [loadQRCode] QR Image size: ' + qrImage.length + ' chars');
                console.log('🔍 [loadQRCode] QR Image starts with: ' + qrImage.substring(0, 50));
                
                // Forzar actualización de la imagen agregando timestamp para evitar cache
                const timestamp = new Date().getTime();
                const imgWithCache = qrImage.includes('?') ? qrImage + '&t=' + timestamp : qrImage + '?t=' + timestamp;
                
                // Verificar que el QR tenga un tamaño razonable antes de mostrarlo
                if (qrImage.length < 6000) {
                    console.warn('🔍 [loadQRCode] ADVERTENCIA: QR muy pequeño (' + qrImage.length + ' bytes). Puede estar incompleto.');
                    container.innerHTML = `
                        <div style="color: #ff9800; text-align: center;">
                            <i class="fas fa-exclamation-triangle" style="font-size: 2em; margin-bottom: 10px;"></i>
                            <p>El QR parece estar incompleto. Intentando obtener uno nuevo...</p>
                        </div>
                    `;
                    // Intentar obtener un nuevo QR después de un segundo
                    setTimeout(() => loadQRCode(sessionName), 1000);
                    return;
                }
                
                container.innerHTML = `
                    <div style="text-align: center;">
                        <img src="${qrImage}" alt="QR Code" id="qrImage" style="max-width: 100%; height: auto; border: 1px solid #ddd; border-radius: 4px; display: block; margin: 0 auto;">
                        <p style="margin-top: 15px; color: #666; font-size: 0.9em;">
                            <i class="fas fa-info-circle"></i> Escanea este código QR con WhatsApp<br>
                            <small style="color: #999;">El QR se actualiza automáticamente cada 20 segundos</small>
                        </p>
                    </div>
                `;
                
                // Verificar que la imagen se cargó correctamente
                const imgElement = document.getElementById('qrImage');
                if (imgElement) {
                    imgElement.onerror = function() {
                        console.error('🔍 [loadQRCode] Error cargando imagen QR');
                        container.innerHTML = `
                            <div style="color: #f44336; text-align: center;">
                                <i class="fas fa-exclamation-triangle" style="font-size: 2em; margin-bottom: 10px;"></i>
                                <p>Error: La imagen QR no se pudo cargar. Verifica que el formato sea correcto.</p>
                                <button onclick="loadQRCode('${sessionName}')" class="btn btn-primary" style="margin-top: 10px;">
                                    <i class="fas fa-redo"></i> Reintentar
                                </button>
                            </div>
                        `;
                    };
                    imgElement.onload = function() {
                        console.log('🔍 [loadQRCode] Imagen QR cargada correctamente, tamaño: ' + qrImage.length + ' bytes');
                    };
                }
                
            } catch (error) {
                console.error('Error cargando QR:', error);
                console.error('Error stack:', error.stack);
                
                let errorMessage = error.message || 'Error desconocido';
                
                // Si el error es de parsing JSON, mostrar mensaje más descriptivo
                if (error instanceof SyntaxError && error.message.includes('JSON')) {
                    errorMessage = 'El servidor retornó una respuesta inválida. Verifica los logs del servidor para más detalles.';
                }
                
                container.innerHTML = `
                    <div style="color: #f44336; text-align: center;">
                        <i class="fas fa-exclamation-triangle" style="font-size: 2em; margin-bottom: 10px;"></i>
                        <h3 style="color: #f44336; margin: 0 0 10px 0;">Error al obtener QR</h3>
                        <p style="margin: 0; color: #666;">${errorMessage}</p>
                        <p style="margin: 10px 0 0 0; color: #999; font-size: 0.9em;">Revisa la consola del navegador para más detalles.</p>
                    </div>
                `;
            }
        }
        
        async function refreshQRCode(sessionName) {
            await loadQRCode(sessionName);
        }
        
        async function forceRefreshQRCode(sessionName) {
            const container = document.getElementById('qrCodeContainer');
            if (container) {
                container.innerHTML = `
                    <div>
                        <i class="fas fa-spinner fa-spin" style="font-size: 3em; color: #999;"></i>
                        <p style="margin-top: 10px; color: #999;">Forzando actualización del QR...</p>
                    </div>
                `;
            }
            // Pequeño delay para mostrar el mensaje
            await new Promise(resolve => setTimeout(resolve, 500));
            await loadQRCode(sessionName);
        }
        
        async function checkSessionStatus(sessionName) {
            try {
                const token = await getAuthToken();
                const response = await fetch(getWhatsAppApiUrl('sessions.php') + '?session=' + encodeURIComponent(sessionName), {
                    method: 'GET',
                    headers: {
                        'Authorization': token ? `Bearer ${token}` : ''
                    },
                    credentials: 'include'
                });
                
                if (!response.ok) return null;
                
                const result = await response.json();
                if (!result.success) return null;
                
                return result.data.status?.state || result.data.status || null;
            } catch (error) {
                return null;
            }
        }
        
        function closeQRModal() {
            const modal = document.getElementById('qrModal');
            if (modal) {
                if (modal.dataset.interval) {
                    clearInterval(parseInt(modal.dataset.interval));
                }
                modal.remove();
            }
        }
        
        async function deleteWhatsAppSession(sessionName) {
            if (!confirm(`¿Está seguro de eliminar la sesión "${sessionName}"? Esta acción no se puede deshacer.`)) {
                return;
            }
            
            try {
                const token = await getAuthToken();
                const response = await fetch(getWhatsAppApiUrl('sessions.php'), {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': token ? `Bearer ${token}` : ''
                    },
                    credentials: 'include',
                    body: JSON.stringify({ name: sessionName })
                });
                
                if (!response.ok) {
                    throw new Error(`Error HTTP: ${response.status}`);
                }
                
                const result = await response.json();
                
                if (!result.success) {
                    throw new Error(result.error || 'Error al eliminar sesión');
                }
                
                alert('Sesión eliminada exitosamente.');
                loadWhatsAppSessions();
                
            } catch (error) {
                console.error('Error eliminando sesión:', error);
                alert('Error al eliminar sesión: ' + error.message);
            }
        }
        
        // Función helper para obtener token de autenticación
        async function getAuthToken() {
            const cookies = document.cookie.split(';');
            for (let cookie of cookies) {
                const [name, value] = cookie.trim().split('=');
                if (name === 'session_token') {
                    return value;
                }
            }
            return null;
        }
        
        // Configurar funcionalidad del sidebar
        function setupSidebarFunctionality() {
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarToggleDesktop = document.getElementById('sidebarToggleDesktop');
            const sidebarCloseMobile = document.getElementById('sidebarCloseMobile');
            const sidebar = document.getElementById('sidebar');
            const sidebarOverlay = document.getElementById('sidebarOverlay');
            const sidebarLayout = document.getElementById('sidebarLayout');
            const sidebarToggleShow = document.getElementById('sidebarToggleShow');

            function closeMobileSidebar() {
                if (sidebar) sidebar.classList.remove('show');
                if (sidebarOverlay) sidebarOverlay.classList.remove('show');
            }

            function openMobileSidebar() {
                if (sidebar) sidebar.classList.add('show');
                if (sidebarOverlay) sidebarOverlay.classList.add('show');
            }

            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    if (sidebar && sidebar.classList.contains('show')) {
                        closeMobileSidebar();
                    } else {
                        openMobileSidebar();
                    }
                });
            }

            if (sidebarCloseMobile) {
                sidebarCloseMobile.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    closeMobileSidebar();
                });
            }

            if (sidebarOverlay) {
                sidebarOverlay.addEventListener('click', () => {
                    closeMobileSidebar();
                });
            }

            if (sidebarToggleDesktop) {
                sidebarToggleDesktop.addEventListener('click', () => {
                    if (sidebarLayout) sidebarLayout.classList.add('sidebar-hidden');
                });
            }

            if (sidebarToggleShow) {
                sidebarToggleShow.addEventListener('click', () => {
                    if (sidebarLayout) sidebarLayout.classList.remove('sidebar-hidden');
                });
            }
            
            // Actualizar nombre de usuario en sidebar
            updateSidebarUserInfo();
        }
        
        // Función para actualizar información del usuario en el sidebar
        async function updateSidebarUserInfo() {
            try {
                await new Promise(resolve => setTimeout(resolve, 100));
                
                if (window.getCurrentUser) {
                    const user = window.getCurrentUser();
                    
                    if (user && user.nombre) {
                        const sidebarUserName = document.querySelector('.sidebar-user .user-name');
                        if (sidebarUserName) {
                            const fullName = user.apellido ? `Dr. ${user.nombre} ${user.apellido}` : `Dr. ${user.nombre}`;
                            sidebarUserName.textContent = fullName;
                        }
                        
                        const sidebarUserRole = document.querySelector('.sidebar-user .user-role');
                        if (sidebarUserRole && user.rol) {
                            sidebarUserRole.textContent = user.rol;
                        }
                    }
                } else {
                    setTimeout(updateSidebarUserInfo, 500);
                }
            } catch (error) {
                setTimeout(updateSidebarUserInfo, 1000);
            }
        }
        
        // Inicializar sidebar cuando el DOM esté listo
        document.addEventListener('DOMContentLoaded', function() {
            setupSidebarFunctionality();
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
// Limpiar output buffer al final
if (ob_get_level()) {
    ob_end_flush();
}
?>

