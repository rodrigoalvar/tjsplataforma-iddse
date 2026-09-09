<?php
/**
 * API para Verificación de Permisos desde JavaScript
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * Endpoint: /api/users/check-permission.php
 */

// Desactivar display de errores para producción
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Iniciar output buffering para capturar errores
ob_start();

// Manejar errores de includes de forma silenciosa
$dbLoaded = false;
$authLoaded = false;
$permissionsLoaded = false;

if (file_exists('../../config/database.php')) {
    try {
        require_once '../../config/database.php';
        $dbLoaded = true;
    } catch (Exception $e) {
        error_log("Error cargando database.php: " . $e->getMessage());
    } catch (Error $e) {
        error_log("Error fatal cargando database.php: " . $e->getMessage());
    }
} else {
    error_log("check-permission.php: No se encontró database.php en: " . realpath('../../config/'));
}

if (file_exists('../../middleware/auth.php')) {
    try {
        require_once '../../middleware/auth.php';
        $authLoaded = true;
    } catch (Exception $e) {
        error_log("Error cargando auth.php: " . $e->getMessage());
    } catch (Error $e) {
        error_log("Error fatal cargando auth.php: " . $e->getMessage());
    }
} else {
    error_log("check-permission.php: No se encontró auth.php en: " . realpath('../../middleware/'));
}

if (file_exists('../../middleware/permissions.php')) {
    try {
        require_once '../../middleware/permissions.php';
        $permissionsLoaded = true;
    } catch (Exception $e) {
        error_log("Warning: No se pudo cargar permissions.php: " . $e->getMessage());
    } catch (Error $e) {
        error_log("Warning: Error fatal cargando permissions.php: " . $e->getMessage());
    }
}

// Solo permitir método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Método no permitido'
    ]);
    exit;
}

try {
    // Obtener datos del POST
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['permission'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Permiso requerido'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $permission = $input['permission'];
    
    // Verificar sesión - obtener token de múltiples fuentes
    // PRIORIDAD: Cookie primero (como todos los demás endpoints del sistema que funcionan)
    // El token del localStorage puede estar desactualizado/expirado
    $token = null;
    $tokenSource = '';
    
    // Prioridad 1: Cookie session_token (IGUAL que save-config.php, get-config.php y otros endpoints)
    if (isset($_COOKIE['session_token']) && !empty($_COOKIE['session_token'])) {
        $token = trim($_COOKIE['session_token']);
        $tokenSource = 'Cookie session_token';
    }
    // Prioridad 2: Otras cookies posibles
    elseif (isset($_COOKIE['sessionToken']) && !empty($_COOKIE['sessionToken'])) {
        $token = trim($_COOKIE['sessionToken']);
        $tokenSource = 'Cookie sessionToken';
    }
    // Prioridad 3: Body del POST (session_token) - puede ser stale desde localStorage
    elseif (isset($input['session_token']) && !empty($input['session_token'])) {
        $token = trim($input['session_token']);
        $tokenSource = 'POST body';
    }
    // Prioridad 4: Header Authorization
    elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
        if (strpos($auth_header, 'Bearer ') === 0) {
            $token = trim(substr($auth_header, 7));
            $tokenSource = 'Header Authorization (Bearer)';
        } elseif (!empty($auth_header)) {
            $token = trim($auth_header);
            $tokenSource = 'Header Authorization (raw)';
        }
    }
    
    if (!$token) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        error_log("check-permission.php: No se encontró token. Cookie session_token: " . (isset($_COOKIE['session_token']) ? 'presente' : 'ausente') . ", POST session_token: " . (isset($input['session_token']) ? 'presente' : 'ausente') . ", Header Authorization: " . (isset($_SERVER['HTTP_AUTHORIZATION']) ? 'presente' : 'ausente'));
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Token de sesión requerido'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Log del token recibido (solo primeros caracteres para seguridad)
    error_log("check-permission.php: Token desde: $tokenSource, longitud: " . strlen($token) . ", preview: " . substr($token, 0, 20) . "...");
    
    // Validar sesión con manejo de errores robusto
    $userData = null;
    $validationMethod = '';
    
    // Intentar múltiples métodos para obtener datos del usuario
    if (function_exists('getUserFromToken')) {
        try {
            $userData = getUserFromToken($token);
            if ($userData) {
                $validationMethod = 'getUserFromToken';
            }
        } catch (Exception $e) {
            error_log("Error en getUserFromToken: " . $e->getMessage());
        } catch (Error $e) {
            error_log("Error fatal en getUserFromToken: " . $e->getMessage());
        }
    }
    
    // Fallback: intentar con clase User directamente
    if (!$userData && class_exists('User')) {
        try {
            $user = new User();
            $userData = $user->validateSession($token);
            if ($userData) {
                $validationMethod = 'User->validateSession';
            }
        } catch (Exception $e) {
            error_log("Error en User->validateSession: " . $e->getMessage());
        } catch (Error $e) {
            error_log("Error fatal en User->validateSession: " . $e->getMessage());
        }
    }
    
    // Fallback: consultar directamente la base de datos
    if (!$userData && $dbLoaded && function_exists('getDBConnection')) {
        try {
            $pdo = getDBConnection();
            
            // Intentar con tabla sesiones (formato estándar)
            $query = "SELECT u.id, u.nombre, u.apellido, u.email, u.nivel, u.permisos, u.activo, u.especialidad
                     FROM usuarios u 
                     INNER JOIN sesiones s ON u.id = s.usuario_id 
                     WHERE s.token_sesion = ? AND s.activa = 1 AND s.fecha_expiracion > NOW() AND u.activo = 1
                     LIMIT 1";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$token]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($userData) {
                $validationMethod = 'BD-sesiones';
            } else {
                // Si no funciona, intentar con user_sessions
                $query = "SELECT u.id, u.nombre, u.apellido, u.email, u.nivel, u.permisos, u.activo, u.especialidad
                         FROM usuarios u 
                         INNER JOIN user_sessions s ON u.id = s.user_id 
                         WHERE s.session_token = ? AND s.expires_at > NOW() AND u.activo = 1
                         LIMIT 1";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$token]);
                $userData = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($userData) {
                    $validationMethod = 'BD-user_sessions';
                }
            }
        } catch (Exception $e) {
            error_log("Error consultando BD directamente: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
        } catch (Error $e) {
            error_log("Error fatal consultando BD: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
        }
    }
    
    // Log para diagnóstico (solo si falla)
    if (!$userData) {
        $tokenPreview = substr($token, 0, 20) . '...';
        error_log("check-permission.php: No se pudo validar token (preview: $tokenPreview). Métodos disponibles: getUserFromToken=" . (function_exists('getUserFromToken') ? 'Sí' : 'No') . ", User class=" . (class_exists('User') ? 'Sí' : 'No') . ", DB=" . ($dbLoaded ? 'Sí' : 'No') . ", token_length=" . strlen($token));
    } else {
        error_log("check-permission.php: Token validado exitosamente usando método: $validationMethod, usuario_id: " . ($userData['id'] ?? 'N/A') . ", nivel: " . ($userData['nivel'] ?? 'N/A'));
    }
    
    if (!$userData || !is_array($userData)) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Sesión inválida'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Verificar que tiene id
    if (!isset($userData['id'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Datos de usuario incompletos'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Verificar permiso (con manejo de errores robusto)
    $hasPermission = false;
    $userPermissions = [];
    $userNivel = $userData['nivel'] ?? 'user';
    
    // Si es ROOT, tiene todos los permisos
    if ($userNivel === 'root') {
        $hasPermission = true;
    } else {
        // Primero intentar verificar directamente desde los permisos del usuario
        try {
            $permisosJson = $userData['permisos'] ?? '[]';
            $permisos = json_decode($permisosJson, true);
            if (!is_array($permisos)) {
                $permisos = [];
            }
            
            // Verificar permiso específico o 'all'
            if (in_array($permission, $permisos) || in_array('all', $permisos)) {
                $hasPermission = true;
            } else {
                // Si no está en los permisos directos, intentar con PermissionManager
                if (class_exists('PermissionManager')) {
                    try {
                        $permissionManager = new PermissionManager();
                        $hasPermission = $permissionManager->hasPermission($permission, $userData['id']);
                        
                        // Obtener información adicional del usuario
                        if (method_exists($permissionManager, 'getUserPermissions')) {
                            try {
                                $userPermissions = $permissionManager->getUserPermissions($userData['id']);
                            } catch (Exception $e) {
                                error_log("Error obteniendo permisos del usuario: " . $e->getMessage());
                                // Continuar sin permisos detallados si falla
                            }
                        }
                    } catch (Exception $e) {
                        error_log("Error verificando permiso con PermissionManager: " . $e->getMessage());
                        // Ya verificamos directamente arriba, así que mantener el resultado
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Error verificando permiso: " . $e->getMessage());
            // Si todo falla, asumir que no tiene permiso
            $hasPermission = false;
        }
    }
    
    // Limpiar output buffer antes de enviar respuesta
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    echo json_encode([
        'success' => true,
        'hasPermission' => $hasPermission,
        'permission' => $permission,
        'user' => [
            'id' => $userData['id'],
            'nivel' => $userNivel,
            'permisos' => $userPermissions
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    error_log("Error en check-permission.php: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error interno del servidor'
    ], JSON_UNESCAPED_UNICODE);
} catch (Error $e) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    error_log("Error fatal en check-permission.php: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error interno del servidor'
    ], JSON_UNESCAPED_UNICODE);
}
?>


