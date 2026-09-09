<?php
/**
 * API para listar pacientes
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

// Configurar manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Headers JSON
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Función para enviar respuesta JSON
function sendJsonResponse($success, $data = null, $error = null, $meta = null) {
    $response = ['success' => $success];
    
    if ($success && $data !== null) {
        $response['data'] = $data;
    }
    
    if (!$success && $error !== null) {
        $response['error'] = $error;
    }
    
    if ($meta !== null) {
        $response['meta'] = $meta;
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    // Conectar a la base de datos
    require_once __DIR__ . '/../../config/database.php';
    $pdo = getDBConnection();
    
    if (!$pdo) {
        throw new Exception('Error de conexión a la base de datos');
    }
    
    // Verificar sesión y permisos del usuario
    $user_id = null;
    $has_permission = false;
    
    // Verificar si hay token de autenticación
    $headers = getallheaders();
    $token = null;
    
    // Intentar obtener token de Authorization header
    if (isset($headers['Authorization'])) {
        $authHeader = $headers['Authorization'];
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $token = $matches[1];
        }
    }
    
    // Si no hay token en header, intentar desde cookie
    if (!$token && isset($_COOKIE['session_token'])) {
        $token = $_COOKIE['session_token'];
    }
    
    if ($token) {
        try {
            // Cargar la clase User
            require_once __DIR__ . '/../../classes/User.php';
            $user = new User();
            $user_data = $user->validateSession($token);
            
            if ($user_data && is_array($user_data)) {
                $user_id = $user_data['id'];
                
                // Verificar si el usuario tiene el permiso 'pacientes' o 'all'
                $user_permisos = isset($user_data['permisos']) ? $user_data['permisos'] : [];
                
                // Convertir permisos a array si es necesario
                if (is_string($user_permisos)) {
                    $user_permisos = json_decode($user_permisos, true) ?: [];
                }
                
                $has_permission = in_array('all', $user_permisos) || 
                                 in_array('pacientes', $user_permisos);
            }
        } catch (Exception $e) {
            error_log('Error validando sesión en pacientes/list.php: ' . $e->getMessage());
        }
    }
    
    // Verificar que el usuario tenga permiso para acceder
    if (!$has_permission) {
        sendJsonResponse(false, null, 'No tienes permisos para acceder a la gestión de pacientes');
    }
    
    // Verificar si la tabla existe
    $checkTable = $pdo->query("SHOW TABLES LIKE 'pacientes'");
    if ($checkTable->rowCount() === 0) {
        sendJsonResponse(false, null, 'La tabla pacientes no existe. Ejecuta el script database/crear_tabla_pacientes.sql');
    }
    
    // Obtener parámetros de paginación y filtros
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? max(1, min(100, intval($_GET['limit']))) : 20;
    $offset = ($page - 1) * $limit;
    
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $activo = isset($_GET['activo']) ? $_GET['activo'] : null;
    
    // Construir consulta base
    $where = ['1=1'];
    $params = [];
    
    // Filtro de búsqueda
    if (!empty($search)) {
        $where[] = "(nombre LIKE :search_nombre OR id_interno LIKE :search_idinterno OR idpaciente LIKE :search_idpaciente OR email LIKE :search_email OR telefono LIKE :search_telefono)";
        $searchParam = "%$search%";
        $params[':search_nombre'] = $searchParam;
        $params[':search_idinterno'] = $searchParam;
        $params[':search_idpaciente'] = $searchParam;
        $params[':search_email'] = $searchParam;
        $params[':search_telefono'] = $searchParam;
    }
    
    // Filtro de activo
    if ($activo !== null) {
        $where[] = "activo = :activo";
        $params[':activo'] = $activo === 'true' || $activo === '1' ? 1 : 0;
    }
    
    $whereClause = implode(' AND ', $where);
    
    // Contar total de registros
    $countQuery = "SELECT COUNT(*) as total FROM pacientes WHERE $whereClause";
    $countStmt = $pdo->prepare($countQuery);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Obtener pacientes con paginación
    $query = "SELECT 
                id,
                id_interno,
                idpaciente,
                nombre,
                telefono,
                email,
                domicilio,
                search_enabled_types,
                activo,
                fecha_creacion,
                fecha_actualizacion
              FROM pacientes 
              WHERE $whereClause
              ORDER BY fecha_creacion DESC, nombre ASC
              LIMIT :limit OFFSET :offset";
    
    $stmt = $pdo->prepare($query);
    
    // Bindear parámetros de búsqueda
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    // Bindear parámetros de paginación
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $pacientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatear datos para compatibilidad
    foreach ($pacientes as &$paciente) {
        // Mapear campos a nombres más familiares para el frontend
        $paciente['paciente_id'] = $paciente['id_interno'] ?? $paciente['idpaciente'] ?? '';
        $paciente['apellido'] = ''; // La tabla no tiene apellido separado
        $paciente['direccion'] = $paciente['domicilio'] ?? '';
        $paciente['edad'] = null; // No hay fecha de nacimiento en la tabla
    }
    
    sendJsonResponse(true, $pacientes, null, [
        'total' => intval($total),
        'page' => $page,
        'limit' => $limit,
        'total_pages' => ceil($total / $limit)
    ]);
    
} catch (Exception $e) {
    error_log('Error en list.php (pacientes): ' . $e->getMessage());
    sendJsonResponse(false, null, 'Error al obtener pacientes: ' . $e->getMessage());
}

