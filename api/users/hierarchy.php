<?php
/**
 * API para Gestión de Jerarquías
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

// Desactivar display de errores
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
    require_once '../../config/database.php';
    $pdo = getDBConnection();
    
    // Obtener método HTTP
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($method) {
        case 'GET':
            handleGetHierarchy($pdo);
            break;
        case 'POST':
            handleAssignHierarchy($pdo);
            break;
        case 'PUT':
            handleUpdateHierarchy($pdo);
            break;
        default:
            sendJsonResponse(false, null, 'Método no permitido');
    }
    
} catch (Exception $e) {
    sendJsonResponse(false, null, $e->getMessage());
}

function handleGetHierarchy($pdo) {
    try {
        $action = $_GET['action'] ?? 'list';
        
        switch ($action) {
            case 'list':
                // Obtener todos los usuarios con información de jerarquía
                $query = "SELECT 
                            u.id,
                            u.nombre,
                            u.apellido,
                            u.email,
                            u.nivel,
                            u.padre_id,
                            u.especialidad,
                            u.activo,
                            p.nombre as padre_nombre,
                            p.apellido as padre_apellido,
                            p.email as padre_email,
                            (SELECT COUNT(*) FROM usuarios h WHERE h.padre_id = u.id AND h.activo = 1) as dependientes_count
                          FROM usuarios u
                          LEFT JOIN usuarios p ON u.padre_id = p.id
                          WHERE u.activo = 1
                          ORDER BY u.nivel DESC, u.nombre ASC";
                
                $stmt = $pdo->prepare($query);
                $stmt->execute();
                $users = $stmt->fetchAll();
                
                // Construir jerarquía
                $hierarchy = buildHierarchy($users);
                
                sendJsonResponse(true, [
                    'users' => $users,
                    'hierarchy' => $hierarchy
                ]);
                break;
                
            case 'possible_parents':
                // Obtener usuarios que pueden ser padres (cualquier usuario activo)
                $excludeUserId = $_GET['exclude'] ?? null;
                
                $query = "SELECT id, nombre, apellido, email, nivel 
                          FROM usuarios 
                          WHERE activo = 1";
                
                if ($excludeUserId) {
                    $query .= " AND id != ?";
                }
                
                $query .= " ORDER BY nivel DESC, nombre ASC";
                
                $stmt = $pdo->prepare($query);
                if ($excludeUserId) {
                    $stmt->execute([$excludeUserId]);
                } else {
                    $stmt->execute();
                }
                
                $parents = $stmt->fetchAll();
                sendJsonResponse(true, $parents);
                break;
                
            case 'possible_dependents':
                // Obtener usuarios que pueden ser dependientes (USER)
                $parentId = $_GET['parent'] ?? null;
                
                $query = "SELECT id, nombre, apellido, email, nivel, padre_id
                          FROM usuarios 
                          WHERE activo = 1 
                          AND nivel = 'user'";
                
                if ($parentId) {
                    $query .= " AND (padre_id IS NULL OR padre_id = ?)";
                }
                
                $query .= " ORDER BY nombre ASC";
                
                $stmt = $pdo->prepare($query);
                if ($parentId) {
                    $stmt->execute([$parentId]);
                } else {
                    $stmt->execute();
                }
                
                $dependents = $stmt->fetchAll();
                sendJsonResponse(true, $dependents);
                break;
                
            default:
                sendJsonResponse(false, null, 'Acción no válida');
        }
        
    } catch (Exception $e) {
        sendJsonResponse(false, null, 'Error obteniendo jerarquía: ' . $e->getMessage());
    }
}

function handleAssignHierarchy($pdo) {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            sendJsonResponse(false, null, 'Datos inválidos');
        }
        
        $userId = $input['user_id'] ?? null;
        $parentId = $input['parent_id'] ?? null;
        
        if (!$userId) {
            sendJsonResponse(false, null, 'ID de usuario requerido');
        }
        
        // Verificar que el usuario existe
        $query = "SELECT id, nivel FROM usuarios WHERE id = ? AND activo = 1";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            sendJsonResponse(false, null, 'Usuario no encontrado');
        }
        
        // Si se asigna un padre, verificar que existe y es válido
        if ($parentId) {
            $query = "SELECT id, nivel FROM usuarios WHERE id = ? AND activo = 1";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$parentId]);
            $parent = $stmt->fetch();
            
            if (!$parent) {
                sendJsonResponse(false, null, 'Usuario padre no encontrado');
            }
            
            // Verificar que no se crea un ciclo
            if ($userId == $parentId) {
                sendJsonResponse(false, null, 'Un usuario no puede ser padre de sí mismo');
            }
            
            // Verificar que no se crea un ciclo indirecto (el padre no puede ser descendiente del hijo)
            if (wouldCreateCycle($pdo, $userId, $parentId)) {
                sendJsonResponse(false, null, 'No se puede crear un ciclo en la jerarquía');
            }
        }
        
        // Actualizar la jerarquía
        $query = "UPDATE usuarios SET padre_id = ? WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $result = $stmt->execute([$parentId, $userId]);
        
        if ($result) {
            $message = $parentId ? 
                "Usuario asignado como dependiente exitosamente" : 
                "Usuario removido de la jerarquía exitosamente";
                
            sendJsonResponse(true, [
                'message' => $message,
                'user_id' => $userId,
                'parent_id' => $parentId
            ]);
        } else {
            sendJsonResponse(false, null, 'Error actualizando jerarquía');
        }
        
    } catch (Exception $e) {
        sendJsonResponse(false, null, 'Error asignando jerarquía: ' . $e->getMessage());
    }
}

function handleUpdateHierarchy($pdo) {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            sendJsonResponse(false, null, 'Datos inválidos');
        }
        
        $userId = $input['user_id'] ?? null;
        $newLevel = $input['level'] ?? null;
        
        if (!$userId || !$newLevel) {
            sendJsonResponse(false, null, 'ID de usuario y nivel requeridos');
        }
        
        // Verificar que el usuario existe
        $query = "SELECT id, nivel FROM usuarios WHERE id = ? AND activo = 1";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            sendJsonResponse(false, null, 'Usuario no encontrado');
        }
        
        // Verificar que el nuevo nivel es válido
        if (!in_array($newLevel, ['root', 'admin', 'user'])) {
            sendJsonResponse(false, null, 'Nivel no válido');
        }
        
        // Actualizar el nivel
        $query = "UPDATE usuarios SET nivel = ? WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $result = $stmt->execute([$newLevel, $userId]);
        
        if ($result) {
            sendJsonResponse(true, [
                'message' => 'Nivel de usuario actualizado exitosamente',
                'user_id' => $userId,
                'new_level' => $newLevel
            ]);
        } else {
            sendJsonResponse(false, null, 'Error actualizando nivel');
        }
        
    } catch (Exception $e) {
        sendJsonResponse(false, null, 'Error actualizando jerarquía: ' . $e->getMessage());
    }
}

function wouldCreateCycle($pdo, $userId, $parentId) {
    // Verificar si el padre propuesto es descendiente del usuario actual
    $currentParent = $parentId;
    
    while ($currentParent) {
        if ($currentParent == $userId) {
            return true; // Se crearía un ciclo
        }
        
        $stmt = $pdo->prepare("SELECT padre_id FROM usuarios WHERE id = ? AND activo = 1");
        $stmt->execute([$currentParent]);
        $result = $stmt->fetch();
        
        $currentParent = $result ? $result['padre_id'] : null;
    }
    
    return false;
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