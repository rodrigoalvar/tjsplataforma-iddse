<?php
/**
 * API Robusta de Gestión de Usuarios
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * Endpoint: /api/users/manage-robust.php
 * 
 * NOTA: Esta API es solo para pruebas. En producción debe tener autenticación.
 */

// Desactivar display de errores para evitar HTML en respuestas JSON
ini_set('display_errors', 0);
error_reporting(E_ERROR | E_PARSE);

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

// Función para enviar respuesta JSON
function sendJsonResponse($success, $data = null, $error = null) {
    $response = ['success' => $success];
    
    if ($success && $data !== null) {
        $response['data'] = $data;
    }
    
    if (!$success && $error !== null) {
        $response['error'] = $error;
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    // Conectar a la base de datos
    require_once '../config/database.php';
    $pdo = getDBConnection();
    
    // Obtener método HTTP
    $method = $_SERVER['REQUEST_METHOD'];
    
    // Manejar diferentes métodos HTTP
    switch ($method) {
        case 'GET':
            handleGetUsers($pdo);
            break;
        case 'POST':
            handleCreateUser($pdo);
            break;
        case 'PUT':
            handleUpdateUser($pdo);
            break;
        case 'DELETE':
            handleDeleteUser($pdo);
            break;
        default:
            sendJsonResponse(false, null, 'Método no permitido');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    sendJsonResponse(false, null, $e->getMessage());
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
                    u.rol,
                    u.padre_id,
                    u.especialidad,
                    u.activo,
                    u.permisos,
                    u.instituciones_permitidas,
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
        
        // Procesar permisos para cada usuario
        foreach ($users as &$user) {
            if ($user['permisos']) {
                try {
                    $user['permisos'] = json_decode($user['permisos'], true);
                } catch (Exception $e) {
                    $user['permisos'] = [];
                }
            } else {
                $user['permisos'] = [];
            }
        }
        
        // Construir jerarquía
        $hierarchy = buildHierarchy($users);
        
        sendJsonResponse(true, [
            'users' => $users,
            'hierarchy' => $hierarchy
        ]);
        
    } catch (Exception $e) {
        sendJsonResponse(false, null, 'Error obteniendo usuarios: ' . $e->getMessage());
    }
}

function handleCreateUser($pdo) {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            sendJsonResponse(false, null, 'Datos inválidos');
        }
        
        // Validar campos requeridos
        $required = ['nombre', 'apellido', 'email', 'password'];
        foreach ($required as $field) {
            if (!isset($input[$field]) || empty($input[$field])) {
                sendJsonResponse(false, null, "Campo requerido: {$field}");
            }
        }
        
        // Verificar email único
        $query = "SELECT id FROM usuarios WHERE email = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([strtolower(trim($input['email']))]);
        if ($stmt->fetch()) {
            sendJsonResponse(false, null, 'El email ya está registrado');
        }
        
        // Preparar datos
        $nombre = trim($input['nombre']);
        $apellido = trim($input['apellido']);
        $email = strtolower(trim($input['email']));
        $telefono = trim($input['telefono'] ?? '');
        $matricula = trim($input['matricula_profesional'] ?? '');
        $nivel = $input['nivel'] ?? 'user';
        $rol = $input['rol'] ?? null;
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
                    password_hash, nivel, rol, padre_id, especialidad, activo, permisos
                  ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)";
        
        $stmt = $pdo->prepare($query);
        $result = $stmt->execute([
            $nombre, $apellido, $email, $telefono, $matricula,
            password_hash($password, PASSWORD_DEFAULT),
            $nivel, $rol, $padre_id, $especialidad, json_encode($permisos)
        ]);
        
        if ($result) {
            sendJsonResponse(true, [
                'id' => $pdo->lastInsertId(),
                'message' => 'Usuario creado exitosamente'
            ]);
        } else {
            sendJsonResponse(false, null, 'Error creando usuario');
        }
        
    } catch (Exception $e) {
        sendJsonResponse(false, null, 'Error creando usuario: ' . $e->getMessage());
    }
}

function handleUpdateUser($pdo) {
    try {
        // Obtener ID desde parámetro de URL o JSON
        $userId = $_GET['id'] ?? null;
        
        if (!$userId) {
            $input = json_decode(file_get_contents('php://input'), true);
            $userId = $input['id'] ?? null;
        }
        
        if (!$userId) {
            sendJsonResponse(false, null, 'ID de usuario requerido');
        }
        
        // Obtener datos del JSON
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            sendJsonResponse(false, null, 'Datos inválidos');
        }
        
        // Verificar que el usuario existe
        $query = "SELECT nivel FROM usuarios WHERE id = ? AND activo = 1";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            sendJsonResponse(false, null, 'Usuario no encontrado');
        }
        
        // Construir query de actualización
        $fields = [];
        $values = [];
        
        $allowedFields = ['nombre', 'apellido', 'email', 'telefono', 'matricula_profesional', 'nivel', 'rol', 'padre_id', 'especialidad', 'permisos', 'instituciones_permitidas'];
        
        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                $fields[] = "{$field} = ?";
                // Si es instituciones_permitidas, procesar como JSON
                if ($field === 'instituciones_permitidas') {
                    if (is_string($input[$field])) {
                        // Si ya es JSON válido, usarlo tal cual
                        $decoded = json_decode($input[$field], true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $values[] = $input[$field];
                        } else {
                            // Si no es JSON válido, intentar convertirlo
                            $values[] = json_encode([$input[$field]], JSON_UNESCAPED_UNICODE);
                        }
                    } elseif (is_array($input[$field])) {
                        $values[] = json_encode($input[$field], JSON_UNESCAPED_UNICODE);
                    } else {
                        $values[] = json_encode([], JSON_UNESCAPED_UNICODE);
                    }
                } else {
                    $values[] = $input[$field];
                }
            }
        }
        
        if (!empty($input['password']) && is_string($input['password'])) {
            $plain = trim($input['password']);
            if (strlen($plain) < 6) {
                sendJsonResponse(false, null, 'La contraseña debe tener al menos 6 caracteres');
            }
            $fields[] = 'password_hash = ?';
            $values[] = password_hash($plain, PASSWORD_DEFAULT);
        }
        
        if (empty($fields)) {
            sendJsonResponse(false, null, 'No hay campos para actualizar');
        }
        
        $values[] = $userId;
        
        $query = "UPDATE usuarios SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $result = $stmt->execute($values);
        
        if ($result) {
            sendJsonResponse(true, [
                'message' => 'Usuario actualizado exitosamente'
            ]);
        } else {
            sendJsonResponse(false, null, 'Error actualizando usuario');
        }
        
    } catch (Exception $e) {
        sendJsonResponse(false, null, 'Error actualizando usuario: ' . $e->getMessage());
    }
}

function handleDeleteUser($pdo) {
    try {
        // Obtener ID desde parámetro de URL o JSON
        $userId = $_GET['id'] ?? null;
        
        if (!$userId) {
            $input = json_decode(file_get_contents('php://input'), true);
            $userId = $input['id'] ?? null;
        }
        
        if (!$userId) {
            sendJsonResponse(false, null, 'ID de usuario requerido');
        }
        
        // Verificar que el usuario existe
        $query = "SELECT nivel FROM usuarios WHERE id = ? AND activo = 1";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            sendJsonResponse(false, null, 'Usuario no encontrado');
        }
        
        // Desactivar usuario (soft delete)
        $query = "UPDATE usuarios SET activo = 0 WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $result = $stmt->execute([$userId]);
        
        if ($result) {
            sendJsonResponse(true, [
                'message' => 'Usuario eliminado exitosamente'
            ]);
        } else {
            sendJsonResponse(false, null, 'Error eliminando usuario');
        }
        
    } catch (Exception $e) {
        sendJsonResponse(false, null, 'Error eliminando usuario: ' . $e->getMessage());
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