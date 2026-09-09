<?php
/**
 * API para subir el logo de la aplicación
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
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
        echo json_encode(['success' => false, 'message' => 'No tienes permisos para subir el logo']);
        exit();
    }
    
    // Verificar que se haya subido un archivo
    if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No se recibió archivo válido']);
        exit();
    }
    
    $file = $_FILES['logo'];
    
    // Validar tipo de archivo (solo imágenes)
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/svg+xml', 'image/webp'];
    if (!in_array($file['type'], $allowedTypes)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Tipo de archivo no permitido. Solo se permiten imágenes (JPG, PNG, GIF, SVG, WEBP)']);
        exit();
    }
    
    // Validar tamaño (máximo 5MB)
    if ($file['size'] > 5 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'El archivo es demasiado grande (máximo 5MB)']);
        exit();
    }
    
    // Crear directorio de logos si no existe
    $uploadDir = __DIR__ . '/../../uploads/logos/';
    
    // Verificar y crear directorio uploads si no existe
    $uploadsBaseDir = __DIR__ . '/../../uploads/';
    if (!file_exists($uploadsBaseDir)) {
        if (!mkdir($uploadsBaseDir, 0755, true)) {
            throw new Exception('No se pudo crear el directorio uploads. Verifique los permisos del servidor.');
        }
    }
    
    // Verificar y crear directorio logos si no existe
    if (!file_exists($uploadDir)) {
        if (!mkdir($uploadDir, 0775, true)) {
            throw new Exception('No se pudo crear el directorio uploads/logos. Verifique los permisos del servidor.');
        }
        // Intentar cambiar el grupo al del servidor web si es posible
        if (function_exists('posix_getgrnam')) {
            $wwwDataGroup = posix_getgrnam('www-data');
            if ($wwwDataGroup && function_exists('chgrp')) {
                @chgrp($uploadDir, $wwwDataGroup['gid']);
            }
        }
    }
    
    // Verificar que el directorio sea escribible
    if (!is_writable($uploadDir)) {
        // Intentar cambiar permisos a más permisivos
        $permissions = [0775, 0777, 0755];
        $changed = false;
        foreach ($permissions as $perm) {
            if (@chmod($uploadDir, $perm)) {
                $changed = true;
                break;
            }
        }
        
        if (!$changed || !is_writable($uploadDir)) {
            // Obtener información del directorio para el mensaje de error
            $dirInfo = [
                'exists' => file_exists($uploadDir),
                'readable' => is_readable($uploadDir),
                'writable' => is_writable($uploadDir),
                'perms' => substr(sprintf('%o', fileperms($uploadDir)), -4)
            ];
            error_log("Directorio no escribible: " . print_r($dirInfo, true));
            throw new Exception('El directorio uploads/logos no tiene permisos de escritura. El administrador debe ejecutar: chmod 775 /var/www/tjsiddse/uploads/logos && chown dicomsuites:www-data /var/www/tjsiddse/uploads/logos');
        }
    }
    
    // Generar nombre único para el archivo
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fileName = 'app_logo_' . time() . '.' . $extension;
    $filePath = $uploadDir . $fileName;
    
    // Mover archivo subido
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        $errorMsg = 'Error al guardar el archivo';
        if (function_exists('error_get_last')) {
            $lastError = error_get_last();
            if ($lastError) {
                $errorMsg .= ': ' . $lastError['message'];
            }
        }
        throw new Exception($errorMsg);
    }
    
    // Asegurar permisos del archivo subido
    chmod($filePath, 0644);
    
    // Guardar la ruta relativa en la configuración
    $relativePath = 'uploads/logos/' . $fileName;
    $db = getDBConnection();
    
    if (!$db) {
        // Si falla la conexión, eliminar el archivo subido
        unlink($filePath);
        throw new Exception('No se pudo conectar a la base de datos');
    }
    
    // Asegurar que la tabla configuracion existe
    $db->exec("
        CREATE TABLE IF NOT EXISTS configuracion (
            clave VARCHAR(100) PRIMARY KEY,
            valor TEXT,
            descripcion TEXT,
            fecha_modificacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Eliminar logo anterior si existe
    $stmt = $db->prepare("SELECT valor FROM configuracion WHERE clave = 'app_logo'");
    $stmt->execute();
    $oldLogo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($oldLogo && $oldLogo['valor'] && file_exists(__DIR__ . '/../../' . $oldLogo['valor'])) {
        unlink(__DIR__ . '/../../' . $oldLogo['valor']);
    }
    
    // Guardar nueva configuración
    $stmt = $db->prepare("
        INSERT INTO configuracion (clave, valor, descripcion) 
        VALUES ('app_logo', ?, 'Ruta del logo de la aplicación')
        ON DUPLICATE KEY UPDATE valor = ?, descripcion = 'Ruta del logo de la aplicación'
    ");
    $stmt->execute([$relativePath, $relativePath]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Logo subido exitosamente',
        'path' => $relativePath,
        'url' => $relativePath // URL relativa para usar en el frontend
    ]);
    
} catch (Exception $e) {
    $errorMessage = $e->getMessage();
    $errorDetails = [
        'message' => $errorMessage,
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ];
    
    // Log completo del error
    error_log("Error en config/upload-logo.php: " . print_r($errorDetails, true));
    
    http_response_code(500);
    
    // En producción, no mostrar detalles técnicos al usuario
    $userMessage = 'Error al subir el logo';
    if (strpos($errorMessage, 'permisos') !== false || strpos($errorMessage, 'permission') !== false) {
        $userMessage = 'Error de permisos: El servidor no tiene permisos para guardar archivos. Contacte al administrador.';
    } elseif (strpos($errorMessage, 'directorio') !== false || strpos($errorMessage, 'directory') !== false) {
        $userMessage = 'Error al crear el directorio de almacenamiento. Contacte al administrador.';
    }
    
    echo json_encode([
        'success' => false,
        'message' => $userMessage,
        'debug' => (defined('DEBUG') && DEBUG) ? $errorDetails : null
    ]);
}
