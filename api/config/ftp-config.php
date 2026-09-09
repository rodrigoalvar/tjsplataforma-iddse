<?php
/**
 * API para gestión de configuración FTP por usuario
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
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
        echo json_encode(['success' => false, 'message' => 'No tienes permisos para acceder a la configuración FTP']);
        exit();
    }
    
    $db = getDBConnection();
    
    if (!$db) {
        throw new Exception('No se pudo conectar a la base de datos');
    }
    
    // Asegurar que la tabla existe
    ensureFtpConfigTable($db);
    
    // GET: Obtener lista de usuarios y sus configuraciones FTP
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? 'list';
        
        if ($action === 'list') {
            // Listar todas las configuraciones FTP por usuario
            listFtpConfigs($db);
        } elseif ($action === 'get') {
            // Obtener configuración específica
            $configId = $_GET['id'] ?? null;
            if ($configId) {
                getFtpConfig($db, $configId);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'ID de configuración requerido']);
            }
        } elseif ($action === 'users') {
            // Listar usuarios disponibles para asociar
            listUsers($db);
        } elseif ($action === 'get-password') {
            // Obtener contraseña desencriptada para pruebas (solo para pruebas)
            $configId = $_GET['id'] ?? null;
            if ($configId) {
                getFtpPasswordForTest($db, $configId);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'ID de configuración requerido']);
            }
        } else {
            listFtpConfigs($db);
        }
    }
    
    // POST: Crear nueva configuración FTP o probar conexión
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_GET['action'] ?? null;
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            $input = $_POST;
        }
        
        if ($action === 'test') {
            // Probar conexión FTP
            testFtpConnection($db);
        } else {
            // Crear nueva configuración FTP
            createFtpConfig($db, $input);
        }
    }
    
    // PUT: Actualizar configuración FTP
    elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true);
        $configId = $_GET['id'] ?? $input['id'] ?? null;
        
        if (!$configId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID de configuración requerido']);
            exit();
        }
        
        updateFtpConfig($db, $configId, $input);
    }
    
    // DELETE: Eliminar configuración FTP
    elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $configId = $_GET['id'] ?? null;
        
        if (!$configId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID de configuración requerido']);
            exit();
        }
        
        deleteFtpConfig($db, $configId);
    }
    
    else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    }
    
} catch (Exception $e) {
    error_log("Error en config/ftp-config.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor',
        'error' => $e->getMessage()
    ]);
}

/**
 * Listar todas las configuraciones FTP
 */
function listFtpConfigs($db) {
    $query = "SELECT 
                ftp.id,
                ftp.usuario_id,
                ftp.nombre_config,
                ftp.ftp_host,
                ftp.ftp_port,
                ftp.ftp_username,
                ftp.ftp_remote_path,
                ftp.ftp_passive_mode,
                ftp.ftp_timeout,
                ftp.ftp_retry_attempts,
                ftp.ftp_auto_send,
                ftp.activo,
                u.nombre,
                u.apellido,
                u.email
              FROM usuarios_ftp_config ftp
              LEFT JOIN usuarios u ON ftp.usuario_id = u.id
              ORDER BY u.nombre, u.apellido, ftp.nombre_config";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Enmascarar contraseñas
    foreach ($configs as &$config) {
        $config['ftp_password'] = '••••••••';
    }
    
    echo json_encode([
        'success' => true,
        'configs' => $configs
    ]);
}

/**
 * Obtener configuración FTP específica
 */
function getFtpConfig($db, $configId) {
    $query = "SELECT * FROM usuarios_ftp_config WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$configId]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$config) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Configuración no encontrada']);
        return;
    }
    
    // Verificar si hay contraseña guardada
    $hasPassword = !empty($config['ftp_password']) && trim($config['ftp_password']) !== '';
    
    // Enmascarar contraseña pero indicar que existe
    $config['ftp_password'] = $hasPassword ? '••••••••' : '';
    $config['has_password'] = $hasPassword;
    
    echo json_encode([
        'success' => true,
        'config' => $config
    ]);
}

/**
 * Obtener contraseña desencriptada para pruebas (solo para uso en pruebas)
 */
function getFtpPasswordForTest($db, $configId) {
    $query = "SELECT ftp_password FROM usuarios_ftp_config WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$configId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result || empty($result['ftp_password'])) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Configuración no encontrada o sin contraseña']);
        return;
    }
    
    // Desencriptar contraseña
    $decryptedPassword = decryptPassword($result['ftp_password']);
    
    echo json_encode([
        'success' => true,
        'password' => $decryptedPassword
    ]);
}

/**
 * Listar usuarios disponibles
 */
function listUsers($db) {
    $query = "SELECT id, nombre, apellido, email, nivel, activo 
              FROM usuarios 
              WHERE activo = 1 
              ORDER BY nombre, apellido";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'users' => $users
    ]);
}

/**
 * Crear nueva configuración FTP
 */
function createFtpConfig($db, $input) {
    // Validar campos requeridos
    $required = ['usuario_id', 'nombre_config', 'ftp_host', 'ftp_username', 'ftp_password'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Campo requerido: $field"]);
            exit();
        }
    }
    
    // Encriptar contraseña
    $encryptionKey = getEncryptionKey();
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('AES-256-CBC'));
    $encrypted = openssl_encrypt($input['ftp_password'], 'AES-256-CBC', $encryptionKey, 0, $iv);
    $encryptedPassword = base64_encode($encrypted . '::' . $iv);
    
    $query = "INSERT INTO usuarios_ftp_config 
              (usuario_id, nombre_config, ftp_host, ftp_port, ftp_username, ftp_password, 
               ftp_remote_path, ftp_passive_mode, ftp_timeout, ftp_retry_attempts, ftp_auto_send, activo)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        $input['usuario_id'],
        $input['nombre_config'],
        $input['ftp_host'],
        (int)($input['ftp_port'] ?? 21),
        $input['ftp_username'],
        $encryptedPassword,
        $input['ftp_remote_path'] ?? '/audios/',
        isset($input['ftp_passive_mode']) ? (int)$input['ftp_passive_mode'] : 1,
        (int)($input['ftp_timeout'] ?? 30),
        (int)($input['ftp_retry_attempts'] ?? 3),
        isset($input['ftp_auto_send']) ? (int)$input['ftp_auto_send'] : 1,
        isset($input['activo']) ? (int)$input['activo'] : 1
    ]);
    
    $configId = $db->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => 'Configuración FTP creada exitosamente',
        'config_id' => $configId
    ]);
}

/**
 * Actualizar configuración FTP
 */
function updateFtpConfig($db, $configId, $input) {
    // Verificar que existe
    $checkQuery = "SELECT id FROM usuarios_ftp_config WHERE id = ?";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->execute([$configId]);
    if (!$checkStmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Configuración no encontrada']);
        exit();
    }
    
    // Construir query de actualización
    $fields = [];
    $values = [];
    
    $allowedFields = [
        'usuario_id', 'nombre_config', 'ftp_host', 'ftp_port', 'ftp_username',
        'ftp_remote_path', 'ftp_passive_mode', 'ftp_timeout', 'ftp_retry_attempts',
        'ftp_auto_send', 'activo'
    ];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $fields[] = "$field = ?";
            if ($field === 'ftp_port' || $field === 'ftp_timeout' || $field === 'ftp_retry_attempts') {
                $values[] = (int)$input[$field];
            } elseif (in_array($field, ['ftp_passive_mode', 'ftp_auto_send', 'activo'])) {
                $values[] = (int)$input[$field];
            } else {
                $values[] = $input[$field];
            }
        }
    }
    
    // Si se proporciona nueva contraseña, encriptarla
    if (!empty($input['ftp_password']) && $input['ftp_password'] !== '••••••••') {
        $encryptionKey = getEncryptionKey();
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('AES-256-CBC'));
        $encrypted = openssl_encrypt($input['ftp_password'], 'AES-256-CBC', $encryptionKey, 0, $iv);
        $encryptedPassword = base64_encode($encrypted . '::' . $iv);
        
        $fields[] = "ftp_password = ?";
        $values[] = $encryptedPassword;
    }
    
    if (empty($fields)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No hay campos para actualizar']);
        exit();
    }
    
    $values[] = $configId;
    
    $query = "UPDATE usuarios_ftp_config SET " . implode(', ', $fields) . " WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute($values);
    
    echo json_encode([
        'success' => true,
        'message' => 'Configuración FTP actualizada exitosamente'
    ]);
}

/**
 * Eliminar configuración FTP
 */
function deleteFtpConfig($db, $configId) {
    $query = "DELETE FROM usuarios_ftp_config WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$configId]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode([
            'success' => true,
            'message' => 'Configuración FTP eliminada exitosamente'
        ]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Configuración no encontrada']);
    }
}

/**
 * Probar conexión FTP
 */
function testFtpConnection($db) {
    require_once __DIR__ . '/../../classes/FtpClient.php';
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST;
    }
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Datos de conexión requeridos']);
        exit();
    }
    
    // Si se proporciona config_id y no hay contraseña, intentar obtener la guardada
    if (!empty($input['config_id']) && empty($input['ftp_password'])) {
        $configId = $input['config_id'];
        $query = "SELECT ftp_password FROM usuarios_ftp_config WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$configId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && !empty($result['ftp_password'])) {
            $input['ftp_password'] = decryptPassword($result['ftp_password']);
        }
    }
    
    // Validar campos requeridos
    if (empty($input['ftp_host']) || empty($input['ftp_username']) || empty($input['ftp_password'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => 'Servidor FTP, usuario y contraseña son requeridos'
        ]);
        exit();
    }
    
    $ftpConfig = [
        'host' => $input['ftp_host'] ?? '',
        'port' => (int)($input['ftp_port'] ?? 21),
        'username' => $input['ftp_username'] ?? '',
        'password' => $input['ftp_password'] ?? '',
        'passive_mode' => isset($input['ftp_passive_mode']) ? (bool)$input['ftp_passive_mode'] : true,
        'timeout' => (int)($input['ftp_timeout'] ?? 30)
    ];
    
    try {
        $ftpClient = new FtpClient($ftpConfig);
        $result = $ftpClient->testConnection();
        
        echo json_encode($result);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Obtener clave de encriptación
 */
function getEncryptionKey() {
    $key = 'tjsmedical_ftp_encryption_key_2024';
    return hash('sha256', $key, true);
}

/**
 * Desencriptar contraseña FTP
 */
function decryptPassword($encryptedValue) {
    if (empty($encryptedValue) || $encryptedValue === '••••••••') {
        return '';
    }
    
    try {
        $decoded = base64_decode($encryptedValue, true);
        if ($decoded === false || strpos($decoded, '::') === false) {
            // Formato antiguo o sin encriptar, retornar tal cual
            return $encryptedValue;
        }
        
        list($encrypted, $iv) = explode('::', $decoded, 2);
        $encryptionKey = getEncryptionKey();
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $encryptionKey, 0, $iv);
        
        return $decrypted !== false ? $decrypted : '';
    } catch (Exception $e) {
        error_log("Error al desencriptar contraseña FTP: " . $e->getMessage());
        return ''; // Retornar vacío si falla
    }
}

/**
 * Asegurar que la tabla existe
 */
function ensureFtpConfigTable($db) {
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS usuarios_ftp_config (
                id INT(11) NOT NULL AUTO_INCREMENT,
                usuario_id INT(11) NOT NULL,
                nombre_config VARCHAR(100) NOT NULL,
                ftp_host VARCHAR(255) NOT NULL,
                ftp_port INT(11) NOT NULL DEFAULT 21,
                ftp_username VARCHAR(255) NOT NULL,
                ftp_password TEXT NOT NULL,
                ftp_remote_path VARCHAR(500) NOT NULL DEFAULT '/audios/',
                ftp_passive_mode TINYINT(1) NOT NULL DEFAULT 1,
                ftp_timeout INT(11) NOT NULL DEFAULT 30,
                ftp_retry_attempts INT(11) NOT NULL DEFAULT 3,
                ftp_auto_send TINYINT(1) NOT NULL DEFAULT 1,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_usuario_id (usuario_id),
                INDEX idx_activo (activo)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        // La tabla ya existe o hay un error
    }
}
