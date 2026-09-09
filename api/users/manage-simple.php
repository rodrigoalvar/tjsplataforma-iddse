<?php
/**
 * API Simplificada para Gestión de Usuarios
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * Endpoint: /api/users/manage.php
 */

// Configurar headers para JSON
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // Conectar a la base de datos
    require_once '../../config/database.php';
    $pdo = getDBConnection();
    
    // Obtener método HTTP
    $method = $_SERVER['REQUEST_METHOD'];
    
    // Obtener token de sesión
    $token = null;
    if (isset($_COOKIE['session_token']) && !empty($_COOKIE['session_token'])) {
        $token = $_COOKIE['session_token'];
    } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
        if (strpos($auth_header, 'Bearer ') === 0) {
            $token = substr($auth_header, 7);
        }
    }
    
    if (!$token) {
        throw new Exception('Token de sesión requerido');
    }
    
    // Verificar sesión
    $query = "SELECT u.id, u.nombre, u.apellido, u.email, u.nivel, u.permisos, u.activo 
              FROM usuarios u 
              INNER JOIN user_sessions s ON u.id = s.user_id 
              WHERE s.session_token = ? AND s.expires_at > NOW() AND u.activo = 1";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$token]);
    $currentUser = $stmt->fetch();
    
    if (!$currentUser) {
        throw new Exception('Sesión inválida o expirada');
    }
    
    // Manejar diferentes métodos HTTP
    switch ($method) {
        case 'GET':
            handleGetUsers($pdo);
            break;
        case 'POST':
            handleCreateUser($pdo, $currentUser);
            break;
        case 'PUT':
            handleUpdateUser($pdo, $currentUser);
            break;
        case 'DELETE':
            handleDeleteUser($pdo, $currentUser);
            break;
        default:
            throw new Exception('Método no permitido');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

function handleGetUsers($pdo) {
    try {
        // Obtener usuarios con información de jerarquía
        $query = "SELECT 
                    u.id,
                    u.nombre,
                    u.apellido,
                    u.email,
                    u.telefono,
                    u.matricula_profesional,
                    u.nivel,
                    u.padre_id,
                    u.especialidad,
                    u.activo,
                    u.permisos,
                    u.ultimo_acceso,
                    u.created_at,
                    p.nombre as padre_nombre,
                    p.apellido as padre_apellido,
                    p.email as padre_email,
                    (SELECT COUNT(*) FROM usuarios h WHERE h.padre_id = u.id AND h.activo = 1) as hijos_count
                  FROM usuarios u
                  LEFT JOIN usuarios p ON u.padre_id = p.id
                  WHERE u.activo = 1
                  ORDER BY u.nivel DESC, u.nombre ASC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $users = $stmt->fetchAll();
        
        // Construir jerarquía
        $hierarchy = buildHierarchy($users);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'users' => $users,
                'hierarchy' => $hierarchy
            ]
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        throw new Exception('Error obteniendo usuarios: ' . $e->getMessage());
    }
}

function handleCreateUser($pdo, $currentUser) {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            throw new Exception('Datos inválidos');
        }
        
        // Validar campos requeridos
        $required = ['nombre', 'apellido', 'email', 'password'];
        foreach ($required as $field) {
            if (!isset($input[$field]) || empty($input[$field])) {
                throw new Exception("Campo requerido: {$field}");
            }
        }
        
        // Verificar email único
        $query = "SELECT id FROM usuarios WHERE email = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([strtolower(trim($input['email']))]);
        if ($stmt->fetch()) {
            throw new Exception('El email ya está registrado');
        }
        
        // Preparar datos
        $nombre = trim($input['nombre']);
        $apellido = trim($input['apellido']);
        $email = strtolower(trim($input['email']));
        $telefono = trim($input['telefono'] ?? '');
        $matricula = trim($input['matricula_profesional'] ?? '');
        $nivel = $input['nivel'] ?? 'user';
        $padre_id = $input['padre_id'] ?? null;
        $especialidad = trim($input['especialidad'] ?? '');
        $password = $input['password'];
        
        // Preparar permisos por defecto
        $permisos = ['dashboard'];
        if ($nivel === 'admin') {
            $permisos = ['dashboard', 'estudios', 'informes', 'gestionInformes', 'usuarios', 'plantillas', 'visor'];
        } elseif ($nivel === 'root') {
            $permisos = ['all'];
        }
        
        // Insertar usuario
        $query = "INSERT INTO usuarios (
                    nombre, apellido, email, telefono, matricula_profesional, 
                    password_hash, nivel, padre_id, especialidad, activo, permisos
                  ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)";
        
        $stmt = $pdo->prepare($query);
        $result = $stmt->execute([
            $nombre, $apellido, $email, $telefono, $matricula,
            password_hash($password, PASSWORD_DEFAULT),
            $nivel, $padre_id, $especialidad, json_encode($permisos)
        ]);
        
        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Usuario creado exitosamente',
                'data' => ['id' => $pdo->lastInsertId()]
            ], JSON_UNESCAPED_UNICODE);
        } else {
            throw new Exception('Error creando usuario');
        }
        
    } catch (Exception $e) {
        throw new Exception('Error creando usuario: ' . $e->getMessage());
    }
}

function handleUpdateUser($pdo, $currentUser) {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input || !isset($input['id'])) {
            throw new Exception('ID de usuario requerido');
        }
        
        $userId = $input['id'];
        
        // Verificar que el usuario existe
        $query = "SELECT nivel FROM usuarios WHERE id = ? AND activo = 1";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            throw new Exception('Usuario no encontrado');
        }
        
        // Proteger usuario ROOT
        if ($user['nivel'] === 'root' && $currentUser['nivel'] !== 'root') {
            throw new Exception('No se puede modificar el usuario ROOT');
        }
        
        // Construir query de actualización
        $fields = [];
        $values = [];
        
        $allowedFields = ['nombre', 'apellido', 'email', 'telefono', 'matricula_profesional', 'nivel', 'padre_id', 'especialidad', 'permisos'];
        
        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                $fields[] = "{$field} = ?";
                $values[] = $input[$field];
            }
        }
        
        if (empty($fields)) {
            throw new Exception('No hay campos para actualizar');
        }
        
        $values[] = $userId;
        
        $query = "UPDATE usuarios SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $result = $stmt->execute($values);
        
        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Usuario actualizado exitosamente'
            ], JSON_UNESCAPED_UNICODE);
        } else {
            throw new Exception('Error actualizando usuario');
        }
        
    } catch (Exception $e) {
        throw new Exception('Error actualizando usuario: ' . $e->getMessage());
    }
}

function handleDeleteUser($pdo, $currentUser) {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input || !isset($input['id'])) {
            throw new Exception('ID de usuario requerido');
        }
        
        $userId = $input['id'];
        
        // Verificar que el usuario existe
        $query = "SELECT nivel FROM usuarios WHERE id = ? AND activo = 1";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            throw new Exception('Usuario no encontrado');
        }
        
        // Proteger usuario ROOT
        if ($user['nivel'] === 'root') {
            throw new Exception('No se puede eliminar el usuario ROOT');
        }
        
        // Desactivar usuario (soft delete)
        $query = "UPDATE usuarios SET activo = 0 WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $result = $stmt->execute([$userId]);
        
        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Usuario eliminado exitosamente'
            ], JSON_UNESCAPED_UNICODE);
        } else {
            throw new Exception('Error eliminando usuario');
        }
        
    } catch (Exception $e) {
        throw new Exception('Error eliminando usuario: ' . $e->getMessage());
    }
}

function buildHierarchy($users) {
    $hierarchy = [];
    $userMap = [];
    
    // Crear mapa de usuarios
    foreach ($users as $user) {
        $userMap[$user['id']] = $user;
        $userMap[$user['id']]['children'] = [];
    }
    
    // Construir jerarquía
    foreach ($users as $user) {
        if ($user['padre_id'] && isset($userMap[$user['padre_id']])) {
            $userMap[$user['padre_id']]['children'][] = $user;
        } else {
            $hierarchy[] = $user;
        }
    }
    
    return $hierarchy;
}
?>
