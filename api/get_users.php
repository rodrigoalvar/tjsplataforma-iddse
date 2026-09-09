<?php
/**
 * API para obtener usuarios del sistema
 * Devuelve la lista de usuarios registrados para asignación de estudios
 * Si el usuario no tiene PACS QUERY, solo muestra sus hijos
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Asegurar que no haya salida antes de los headers
if (ob_get_level()) {
    ob_clean();
}

// Desactivar display_errors para evitar que se muestren errores en la salida
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Incluir configuración de base de datos
require_once __DIR__ . '/../config/database.php';

// Cargar User.php primero (middleware/auth.php lo necesita)
require_once __DIR__ . '/../classes/User.php';

// Verificar si existe middleware/auth.php antes de incluirlo (opcional)
if (file_exists(__DIR__ . '/../middleware/auth.php')) {
    require_once __DIR__ . '/../middleware/auth.php';
}

try {
    // Crear conexión a la base de datos usando la clase Database
    $pdo = getDBConnection();
    
    if (!$pdo) {
        throw new Exception('No se pudo conectar a la base de datos');
    }
    
    // Verificar sesión y obtener permisos del usuario
    $user_id = null;
    $has_pacs_query = false;
    
    // Verificar si hay token de autenticación
    $session_token = null;
    if (isset($_COOKIE['session_token']) && !empty($_COOKIE['session_token'])) {
        $session_token = $_COOKIE['session_token'];
    }
    
    if ($session_token) {
        try {
            $user = new User();
            $user_data = $user->validateSession($session_token);
            
            if ($user_data) {
                $user_id = $user_data['id'];
                
                // Verificar permisos
                $user_permisos = $user_data['permisos'] ?? [];
                if (is_string($user_permisos)) {
                    $user_permisos = json_decode($user_permisos, true) ?: [];
                }
                
                $has_pacs_query = in_array('all', $user_permisos) || 
                                  in_array('pacs_query', $user_permisos);
            }
        } catch (Exception $e) {
            // Si hay error, continuar sin filtros de jerarquía
            error_log("Error validando sesión en get_users.php: " . $e->getMessage());
        }
    }
    
    // Verificar si la tabla usuarios existe
    $stmt = $pdo->query("SHOW TABLES LIKE 'usuarios'");
    if ($stmt->rowCount() === 0) {
        throw new Exception('La tabla usuarios no existe en la base de datos');
    }
    
    // Verificar qué columnas existen en la tabla usuarios
    $stmt = $pdo->query("DESCRIBE usuarios");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $hasFechaCreacion = in_array('fecha_creacion', $columns);
    $hasCreatedAt = in_array('created_at', $columns);
    
    // Construir SELECT con columnas disponibles
    $selectFields = ['id', 'nombre', 'apellido', 'email', 'matricula_profesional', 'nivel', 'padre_id'];
    if ($hasFechaCreacion) {
        $selectFields[] = 'fecha_creacion';
    } elseif ($hasCreatedAt) {
        $selectFields[] = 'created_at as fecha_creacion';
    }
    
    $selectClause = implode(', ', $selectFields);
    
    // Construir consulta según permisos
    if (!$has_pacs_query && $user_id) {
        // Usuario sin PACS QUERY: Solo mostrar sus hijos
        $sql = "SELECT $selectClause
                FROM usuarios 
                WHERE activo = 1 
                AND padre_id = ?
                ORDER BY apellido, nombre";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id]);
    } else {
        // Usuario con PACS QUERY o sin sesión: Mostrar todos los usuarios
        $sql = "SELECT $selectClause
                FROM usuarios 
                WHERE activo = 1 
                ORDER BY apellido, nombre";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
    }
    
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatear respuesta
    $response = [
        'success' => true,
        'data' => $users,
        'count' => count($users),
        'has_pacs_query' => isset($has_pacs_query) ? $has_pacs_query : false,
        'user_id' => isset($user_id) ? $user_id : null,
        'message' => 'Usuarios obtenidos exitosamente'
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    // Error de base de datos
    error_log("PDOException en get_users.php: " . $e->getMessage());
    $response = [
        'success' => false,
        'error' => 'Error de base de datos: ' . $e->getMessage(),
        'data' => []
    ];
    
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
    
} catch (Exception $e) {
    // Error general
    error_log("Exception en get_users.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    $response = [
        'success' => false,
        'error' => 'Error interno del servidor: ' . $e->getMessage(),
        'data' => []
    ];
    
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
} catch (Error $e) {
    // Error fatal de PHP
    error_log("Fatal Error en get_users.php: " . $e->getMessage());
    $response = [
        'success' => false,
        'error' => 'Error fatal: ' . $e->getMessage(),
        'data' => []
    ];
    
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
}
?>