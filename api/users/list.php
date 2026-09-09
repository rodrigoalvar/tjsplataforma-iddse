<?php
/**
 * API para gestión de usuarios con jerarquías
 * Endpoint: /api/users/list.php
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

class UserManager {
    private $db;
    
    public function __construct($database) {
        $this->db = $database;
    }
    
    /**
     * Obtener lista de usuarios con jerarquías
     */
    public function getUsers() {
        try {
            $query = "
                SELECT 
                    u.id,
                    u.nombre,
                    u.apellido,
                    u.email,
                    u.nivel,
                    u.padre_id,
                    u.especialidad,
                    u.activo,
                    u.ultimo_acceso,
                    u.permisos,
                    u.created_at,
                    u.updated_at,
                    p.nombre as padre_nombre,
                    p.apellido as padre_apellido
                FROM usuarios u
                LEFT JOIN usuarios p ON u.padre_id = p.id
                ORDER BY u.nivel DESC, u.nombre ASC
            ";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute();
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Procesar permisos (convertir de JSON string a array)
            foreach ($users as &$user) {
                $user['permisos'] = json_decode($user['permisos'], true) ?: [];
                $user['hijos'] = []; // Se llenará después
            }
            
            // Construir jerarquía
            $hierarchy = $this->buildHierarchy($users);
            
            return [
                'success' => true,
                'data' => [
                    'users' => $users,
                    'hierarchy' => $hierarchy,
                    'total' => count($users)
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Error obteniendo usuarios: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Construir jerarquía de usuarios
     */
    private function buildHierarchy($users) {
        $userMap = [];
        $hierarchy = [];
        
        // Crear mapa de usuarios
        foreach ($users as $user) {
            $userMap[$user['id']] = $user;
            $userMap[$user['id']]['hijos'] = [];
        }
        
        // Construir jerarquía
        foreach ($users as $user) {
            if ($user['padre_id'] && isset($userMap[$user['padre_id']])) {
                $userMap[$user['padre_id']]['hijos'][] = $userMap[$user['id']];
            } else {
                $hierarchy[] = $userMap[$user['id']];
            }
        }
        
        return $hierarchy;
    }
    
    /**
     * Crear nuevo usuario
     */
    public function createUser($userData) {
        try {
            // Validar datos requeridos
            $required = ['nombre', 'apellido', 'email', 'nivel', 'password'];
            foreach ($required as $field) {
                if (empty($userData[$field])) {
                    throw new Exception("El campo {$field} es requerido");
                }
            }
            
            // Verificar si el email ya existe
            $checkQuery = "SELECT id FROM usuarios WHERE email = ?";
            $checkStmt = $this->db->prepare($checkQuery);
            $checkStmt->execute([$userData['email']]);
            if ($checkStmt->fetch()) {
                throw new Exception("El email ya está registrado");
            }
            
            // Hash de la contraseña
            $hashedPassword = password_hash($userData['password'], PASSWORD_DEFAULT);
            
            // Preparar permisos
            $permisos = isset($userData['permisos']) ? json_encode($userData['permisos']) : json_encode([]);
            
            $query = "
                INSERT INTO usuarios (
                    nombre, apellido, email, password, nivel, padre_id, 
                    especialidad, activo, permisos, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ";
            
            $stmt = $this->db->prepare($query);
            $result = $stmt->execute([
                $userData['nombre'],
                $userData['apellido'],
                $userData['email'],
                $hashedPassword,
                $userData['nivel'],
                $userData['padre_id'] ?: null,
                $userData['especialidad'] ?: null,
                $userData['activo'] ? 1 : 0,
                $permisos
            ]);
            
            if ($result) {
                $userId = $this->db->lastInsertId();
                return [
                    'success' => true,
                    'data' => [
                        'id' => $userId,
                        'message' => 'Usuario creado exitosamente'
                    ]
                ];
            } else {
                throw new Exception("Error al crear el usuario");
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Actualizar usuario
     */
    public function updateUser($userId, $userData) {
        try {
            // Verificar si el usuario existe
            $checkQuery = "SELECT id FROM usuarios WHERE id = ?";
            $checkStmt = $this->db->prepare($checkQuery);
            $checkStmt->execute([$userId]);
            if (!$checkStmt->fetch()) {
                throw new Exception("Usuario no encontrado");
            }
            
            // Verificar email único (si se está cambiando)
            if (isset($userData['email'])) {
                $emailQuery = "SELECT id FROM usuarios WHERE email = ? AND id != ?";
                $emailStmt = $this->db->prepare($emailQuery);
                $emailStmt->execute([$userData['email'], $userId]);
                if ($emailStmt->fetch()) {
                    throw new Exception("El email ya está registrado");
                }
            }
            
            // Construir query dinámicamente
            $fields = [];
            $values = [];
            
            $allowedFields = ['nombre', 'apellido', 'email', 'nivel', 'padre_id', 'especialidad', 'activo', 'permisos'];
            
            foreach ($allowedFields as $field) {
                if (isset($userData[$field])) {
                    $fields[] = "{$field} = ?";
                    if ($field === 'permisos') {
                        $values[] = json_encode($userData[$field]);
                    } else {
                        $values[] = $userData[$field];
                    }
                }
            }
            
            // Agregar password si se proporciona
            if (isset($userData['password']) && !empty($userData['password'])) {
                $fields[] = "password = ?";
                $values[] = password_hash($userData['password'], PASSWORD_DEFAULT);
            }
            
            $fields[] = "updated_at = NOW()";
            $values[] = $userId;
            
            $query = "UPDATE usuarios SET " . implode(', ', $fields) . " WHERE id = ?";
            
            $stmt = $this->db->prepare($query);
            $result = $stmt->execute($values);
            
            if ($result) {
                return [
                    'success' => true,
                    'data' => [
                        'message' => 'Usuario actualizado exitosamente'
                    ]
                ];
            } else {
                throw new Exception("Error al actualizar el usuario");
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Eliminar usuario
     */
    public function deleteUser($userId) {
        try {
            // Verificar si el usuario tiene hijos
            $childrenQuery = "SELECT COUNT(*) as count FROM usuarios WHERE padre_id = ?";
            $childrenStmt = $this->db->prepare($childrenQuery);
            $childrenStmt->execute([$userId]);
            $childrenCount = $childrenStmt->fetch()['count'];
            
            if ($childrenCount > 0) {
                throw new Exception("No se puede eliminar un usuario que tiene cuentas hijas asignadas");
            }
            
            // Eliminar usuario
            $query = "DELETE FROM usuarios WHERE id = ?";
            $stmt = $this->db->prepare($query);
            $result = $stmt->execute([$userId]);
            
            if ($result) {
                return [
                    'success' => true,
                    'data' => [
                        'message' => 'Usuario eliminado exitosamente'
                    ]
                ];
            } else {
                throw new Exception("Error al eliminar el usuario");
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}

// Procesar request
try {
    $userManager = new UserManager($pdo);
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($method) {
        case 'GET':
            $result = $userManager->getUsers();
            break;
            
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            $result = $userManager->createUser($input);
            break;
            
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            $userId = $_GET['id'] ?? null;
            if (!$userId) {
                throw new Exception("ID de usuario requerido");
            }
            $result = $userManager->updateUser($userId, $input);
            break;
            
        case 'DELETE':
            $userId = $_GET['id'] ?? null;
            if (!$userId) {
                throw new Exception("ID de usuario requerido");
            }
            $result = $userManager->deleteUser($userId);
            break;
            
        default:
            throw new Exception("Método no permitido");
    }
    
    echo json_encode($result);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>

