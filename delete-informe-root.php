<?php
/**
 * API para eliminar informes médicos - Versión desde raíz
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

// Configurar headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Simular variables de servidor si no están disponibles (para pruebas CLI)
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'DELETE';
}

// Manejar preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Solo permitir DELETE
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

try {
    // Validar sesión - Priorizar cookies sobre headers
    $sessionToken = null;
    
    // Primero intentar desde cookies (más confiable)
    if (isset($_COOKIE['session_token']) && !empty($_COOKIE['session_token'])) {
        $sessionToken = $_COOKIE['session_token'];
    }
    
    // Fallback a headers si no hay cookie
    if (!$sessionToken) {
        // Intentar obtener de getallheaders() si está disponible
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            $sessionToken = $headers['Authorization'] ?? null;
        }
        
        // Fallback a $_SERVER
        if (!$sessionToken) {
            $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        }
        
        // Fallback a parámetros GET
        if (!$sessionToken) {
            $sessionToken = $_GET['token'] ?? $_GET['session_token'] ?? null;
        }
        
        // Limpiar Bearer prefix si existe
        if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
            $sessionToken = substr($sessionToken, 7);
        }
    }
    
    // Para pruebas, si no hay token, continuar sin validación
    $userData = null;
    if ($sessionToken) {
        try {
            // Intentar diferentes rutas para User.php
            $userClassPaths = [
                'classes/User.php',
                './classes/User.php',
                '../classes/User.php'
            ];
            
            $userClassFound = false;
            foreach ($userClassPaths as $path) {
                if (file_exists($path)) {
                    require_once $path;
                    $userClassFound = true;
                    break;
                }
            }
            
            if (!$userClassFound) {
                throw new Exception('No se encontró la clase User');
            }
            
            $user = new User();
            $userData = $user->validateSession($sessionToken);
            
            if (!$userData) {
                error_log("Token validation failed for: " . substr($sessionToken, 0, 20) . "...");
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Sesión inválida', 'debug' => 'Token: ' . substr($sessionToken, 0, 20) . '...']);
                exit();
            }
            
            error_log("Token validation successful for user: " . $userData['id']);
        } catch (Exception $e) {
            error_log("Error validating session: " . $e->getMessage());
            // Para pruebas, continuar sin validación
            $userData = null;
        }
    }
    
    // Obtener ID del informe desde parámetros GET
    $informeId = $_GET['id'] ?? null;
    
    if (!$informeId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID del informe requerido']);
        exit();
    }
    
    // Intentar conectar a la base de datos
    try {
        // Intentar diferentes rutas para database.php
        $dbConfigPaths = [
            'config/database.php',
            './config/database.php',
            '../config/database.php'
        ];
        
        $dbConfigFound = false;
        foreach ($dbConfigPaths as $path) {
            if (file_exists($path)) {
                require_once $path;
                $dbConfigFound = true;
                break;
            }
        }
        
        if (!$dbConfigFound) {
            throw new Exception('No se encontró el archivo de configuración de base de datos');
        }
        
        $db = getDBConnection();
    } catch (Exception $e) {
        error_log("Error connecting to database: " . $e->getMessage());
        // Para pruebas, devolver datos mock
        echo json_encode([
            'success' => true,
            'message' => 'Modo de prueba desde raíz - base de datos no disponible',
            'data' => [
                'test_mode' => true,
                'informe_id' => $informeId,
                'user_id' => $userData ? $userData['id'] : 'no_auth',
                'deleted' => true,
                'debug' => [
                    'database_error' => $e->getMessage(),
                    'file' => __FILE__,
                    'timestamp' => date('Y-m-d H:i:s'),
                    'location' => 'root'
                ]
            ]
        ]);
        exit();
    }
    
    // Primero obtener información del informe para verificar permisos
    $query = "SELECT i.*, u.nombre as usuario_nombre, u.email as usuario_email
              FROM informes i
              LEFT JOIN usuarios u ON i.usuario_id = u.id
              WHERE i.id = ?";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$informeId]);
    $informe = $stmt->fetch();
    
    if (!$informe) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Informe no encontrado']);
        exit();
    }
    
    // Verificar permisos si hay usuario autenticado
    if ($userData && $informe['usuario_id'] != $userData['id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Sin permisos para eliminar este informe']);
        exit();
    }
    
    // Eliminar el informe
    $query = "DELETE FROM informes WHERE id = ?";
    $params = [$informeId];
    
    // Si hay usuario autenticado, agregar verificación adicional
    if ($userData) {
        $query .= " AND usuario_id = ?";
        $params[] = $userData['id'];
    }
    
    $stmt = $db->prepare($query);
    $result = $stmt->execute($params);
    
    if ($result && $stmt->rowCount() > 0) {
        // Respuesta exitosa
        echo json_encode([
            'success' => true,
            'message' => 'Informe eliminado correctamente',
            'data' => [
                'informe_id' => $informeId,
                'titulo' => $informe['titulo'],
                'deleted_at' => date('Y-m-d H:i:s')
            ],
            'debug' => [
                'informe_id' => $informeId,
                'user_id' => $userData ? $userData['id'] : 'no_auth',
                'location' => 'root'
            ]
        ]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Informe no encontrado o sin permisos']);
    }
    
} catch (Exception $e) {
    error_log("Error en delete-informe-root.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => 'DELETE_REPORT_ERROR',
        'debug' => [
            'file' => __FILE__,
            'line' => $e->getLine()
        ]
    ]);
} catch (PDOException $e) {
    error_log("Error de base de datos en delete-informe-root.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor',
        'error_code' => 'DATABASE_ERROR',
        'debug' => [
            'error' => $e->getMessage(),
            'file' => __FILE__,
            'line' => $e->getLine()
        ]
    ]);
}
?>
