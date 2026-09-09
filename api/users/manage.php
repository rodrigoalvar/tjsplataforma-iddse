<?php
/**
 * API Principal para Gestión de Usuarios Profesionales
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * Endpoint: /api/users/manage.php
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
require_once '../middleware/auth.php';

class UserManagementAPI {
    private $pdo;
    private $currentUser;
    
    public function __construct() {
        $this->pdo = getDBConnection();
        $this->currentUser = $this->getCurrentUser();
    }
    
    /**
     * Obtener usuario actual desde token de sesión
     */
    private function getCurrentUser() {
        $token = $this->getSessionToken();
        if (!$token) {
            throw new Exception('Token de sesión requerido');
        }
        
        $userData = getUserFromToken($token);
        if (!$userData) {
            throw new Exception('Sesión inválida');
        }
        
        return $userData;
    }
    
    /**
     * Obtener token de sesión desde headers o cookies
     */
    private function getSessionToken() {
        // Prioridad 1: Cookie
        if (isset($_COOKIE['session_token']) && !empty($_COOKIE['session_token'])) {
            return $_COOKIE['session_token'];
        }
        
        // Prioridad 2: Header Authorization
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
            if (strpos($auth_header, 'Bearer ') === 0) {
                return substr($auth_header, 7);
            }
        }
        
        return null;
    }
    
    /**
     * Verificar permisos de gestión de usuarios
     */
    private function checkUserManagementPermission() {
        if (!in_array($this->currentUser['nivel'], ['root', 'admin'])) {
            throw new Exception('No tienes permisos para gestionar usuarios');
        }
    }
    
    /**
     * Verificar si puede modificar un usuario específico
     */
    private function canModifyUser($targetUserId) {
        $targetUser = $this->getUserById($targetUserId);
        
        // ROOT puede modificar a todos
        if ($this->currentUser['nivel'] === 'root') {
            return true;
        }
        
        // ADMIN no puede modificar ROOT
        if ($this->currentUser['nivel'] === 'admin' && $targetUser['nivel'] === 'root') {
            return false;
        }
        
        // ADMIN no puede modificar a otro ADMIN
        if ($this->currentUser['nivel'] === 'admin' && $targetUser['nivel'] === 'admin') {
            return false;
        }
        
        // ADMIN solo puede modificar usuarios nivel 'user'
        if ($this->currentUser['nivel'] === 'admin' && $targetUser['nivel'] === 'user') {
            return true;
        }
        
        return false;
    }
    
    /**
     * Obtener usuario por ID
     */
    private function getUserById($userId) {
        $query = "SELECT * FROM usuarios WHERE id = ? AND activo = 1";
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([$userId]);
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            throw new Exception('Usuario no encontrado');
        }
        
        // Procesar permisos JSON
        $user['permisos'] = json_decode($user['permisos'], true) ?: [];
        
        return $user;
    }
    
    /**
     * Obtener lista de usuarios con jerarquías
     */
    public function getUsers() {
        $this->checkUserManagementPermission();
        
        try {
            $query = "
                SELECT 
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
                    u.updated_at,
                    u.session_timeout_hours,
                    p.nombre as padre_nombre,
                    p.apellido as padre_apellido,
                    p.email as padre_email,
                    p.nivel as padre_nivel,
                    COUNT(hijos.id) as hijos_count
                FROM usuarios u
                LEFT JOIN usuarios p ON u.padre_id = p.id
                LEFT JOIN usuarios hijos ON hijos.padre_id = u.id AND hijos.activo = 1
                WHERE u.activo = 1
                GROUP BY u.id
                ORDER BY u.nivel DESC, u.nombre ASC
            ";
            
            $stmt = $this->pdo->prepare($query);
            $stmt->execute();
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Procesar permisos JSON
            foreach ($users as &$user) {
                $user['permisos'] = json_decode($user['permisos'], true) ?: [];
            }
            
            // Construir jerarquía
            $hierarchy = $this->buildHierarchy($users);
            
            // Obtener estadísticas
            $stats = $this->getUserStats();
            
            return [
                'success' => true,
                'data' => [
                    'users' => $users,
                    'hierarchy' => $hierarchy,
                    'stats' => $stats,
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
     * Obtener estadísticas de usuarios
     */
    private function getUserStats() {
        $query = "
            SELECT 
                nivel,
                COUNT(*) as total,
                SUM(CASE WHEN padre_id IS NULL THEN 1 ELSE 0 END) as sin_jerarquia,
                SUM(CASE WHEN padre_id IS NOT NULL THEN 1 ELSE 0 END) as con_jerarquia
            FROM usuarios 
            WHERE activo = 1 
            GROUP BY nivel
        ";
        
        $stmt = $this->pdo->prepare($query);
        $stmt->execute();
        $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $stats;
    }
    
    /**
     * Crear nuevo usuario
     */
    public function createUser($userData) {
        $this->checkUserManagementPermission();
        
        try {
            // Validar datos requeridos
            $required = ['nombre', 'apellido', 'email', 'nivel'];
            foreach ($required as $field) {
                if (empty($userData[$field])) {
                    throw new Exception("El campo {$field} es requerido");
                }
            }
            
            // Validar nivel
            if (!in_array($userData['nivel'], ['root', 'admin', 'user'])) {
                throw new Exception("Nivel de usuario inválido");
            }
            
            // Solo ROOT puede crear otros ROOT
            if ($userData['nivel'] === 'root' && $this->currentUser['nivel'] !== 'root') {
                throw new Exception("Solo usuarios ROOT pueden crear otros usuarios ROOT");
            }
            
            // Verificar si el email ya existe
            $checkQuery = "SELECT id FROM usuarios WHERE email = ?";
            $checkStmt = $this->pdo->prepare($checkQuery);
            $checkStmt->execute([$userData['email']]);
            if ($checkStmt->fetch()) {
                throw new Exception("El email ya está registrado");
            }
            
            // Verificar si la matrícula ya existe
            if (!empty($userData['matricula_profesional'])) {
                $matriculaQuery = "SELECT id FROM usuarios WHERE matricula_profesional = ?";
                $matriculaStmt = $this->pdo->prepare($matriculaQuery);
                $matriculaStmt->execute([$userData['matricula_profesional']]);
                if ($matriculaStmt->fetch()) {
                    throw new Exception("La matrícula profesional ya está registrada");
                }
            }
            
            // Generar contraseña temporal si no se proporciona
            $password = $userData['password'] ?? $this->generateTemporaryPassword();
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            // Preparar permisos según nivel
            $permisos = $this->getDefaultPermissions($userData['nivel']);
            if (isset($userData['permisos']) && is_array($userData['permisos'])) {
                $permisos = $userData['permisos'];
            }
            
            // session_timeout_hours: NULL = default global, 0 = sin timeout, N = N horas
            $sessionTimeoutHours = isset($userData['session_timeout_hours']) && $userData['session_timeout_hours'] !== ''
                ? (int) $userData['session_timeout_hours']
                : null;

            $query = "
                INSERT INTO usuarios (
                    nombre, apellido, email, telefono, matricula_profesional, 
                    password_hash, nivel, padre_id, especialidad, activo, permisos,
                    session_timeout_hours, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ";
            
            $stmt = $this->pdo->prepare($query);
            $result = $stmt->execute([
                $userData['nombre'],
                $userData['apellido'],
                $userData['email'],
                $userData['telefono'] ?? null,
                $userData['matricula_profesional'] ?? null,
                $hashedPassword,
                $userData['nivel'],
                $userData['padre_id'] ?? null,
                $userData['especialidad'] ?? null,
                $userData['activo'] ?? 1,
                json_encode($permisos),
                $sessionTimeoutHours
            ]);
            
            if ($result) {
                $userId = $this->pdo->lastInsertId();
                
                // Log de auditoría
                $this->logUserAction('create', $userId, [
                    'nivel' => $userData['nivel'],
                    'email' => $userData['email']
                ]);
                
                return [
                    'success' => true,
                    'data' => [
                        'id' => $userId,
                        'password' => $password, // Solo para mostrar una vez
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
        $this->checkUserManagementPermission();
        
        try {
            // Verificar si puede modificar este usuario
            if (!$this->canModifyUser($userId)) {
                throw new Exception("No tienes permisos para modificar este usuario");
            }
            
            $targetUser = $this->getUserById($userId);
            
            // Verificar email único (si se está cambiando)
            if (isset($userData['email']) && $userData['email'] !== $targetUser['email']) {
                $emailQuery = "SELECT id FROM usuarios WHERE email = ? AND id != ?";
                $emailStmt = $this->pdo->prepare($emailQuery);
                $emailStmt->execute([$userData['email'], $userId]);
                if ($emailStmt->fetch()) {
                    throw new Exception("El email ya está registrado");
                }
            }
            
            // Verificar matrícula única (si se está cambiando)
            if (isset($userData['matricula_profesional']) && 
                $userData['matricula_profesional'] !== $targetUser['matricula_profesional']) {
                $matriculaQuery = "SELECT id FROM usuarios WHERE matricula_profesional = ? AND id != ?";
                $matriculaStmt = $this->pdo->prepare($matriculaQuery);
                $matriculaStmt->execute([$userData['matricula_profesional'], $userId]);
                if ($matriculaStmt->fetch()) {
                    throw new Exception("La matrícula profesional ya está registrada");
                }
            }
            
            // Construir query dinámicamente
            $fields = [];
            $values = [];
            
            $allowedFields = ['nombre', 'apellido', 'email', 'telefono', 'matricula_profesional', 
                             'nivel', 'padre_id', 'especialidad', 'activo', 'permisos',
                             'session_timeout_hours'];
            
            foreach ($allowedFields as $field) {
                if (isset($userData[$field])) {
                    $fields[] = "{$field} = ?";
                    if ($field === 'permisos') {
                        $values[] = json_encode($userData[$field]);
                    } elseif ($field === 'session_timeout_hours') {
                        // String vacío → NULL (usar default global)
                        $val = $userData[$field];
                        $values[] = ($val === '' || $val === null) ? null : (int) $val;
                    } else {
                        $values[] = $userData[$field];
                    }
                }
            }
            
            // Agregar password si se proporciona
            if (isset($userData['password']) && !empty($userData['password'])) {
                $fields[] = "password_hash = ?";
                $values[] = password_hash($userData['password'], PASSWORD_DEFAULT);
            }
            
            $fields[] = "updated_at = NOW()";
            $values[] = $userId;
            
            $query = "UPDATE usuarios SET " . implode(', ', $fields) . " WHERE id = ?";
            
            $stmt = $this->pdo->prepare($query);
            $result = $stmt->execute($values);
            
            if ($result) {
                // Log de auditoría
                $this->logUserAction('update', $userId, $userData);
                
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
        $this->checkUserManagementPermission();
        
        try {
            // Verificar si puede modificar este usuario
            if (!$this->canModifyUser($userId)) {
                throw new Exception("No tienes permisos para eliminar este usuario");
            }
            
            $targetUser = $this->getUserById($userId);
            
            // Verificar si el usuario tiene hijos
            $childrenQuery = "SELECT COUNT(*) as count FROM usuarios WHERE padre_id = ? AND activo = 1";
            $childrenStmt = $this->pdo->prepare($childrenQuery);
            $childrenStmt->execute([$userId]);
            $childrenCount = $childrenStmt->fetch()['count'];
            
            if ($childrenCount > 0) {
                throw new Exception("No se puede eliminar un usuario que tiene cuentas hijas asignadas");
            }
            
            // No permitir eliminación de sí mismo
            if ($userId == $this->currentUser['id']) {
                throw new Exception("No puedes eliminarte a ti mismo");
            }
            
            // Eliminar usuario (soft delete)
            $query = "UPDATE usuarios SET activo = 0, updated_at = NOW() WHERE id = ?";
            $stmt = $this->pdo->prepare($query);
            $result = $stmt->execute([$userId]);
            
            if ($result) {
                // Log de auditoría
                $this->logUserAction('delete', $userId, [
                    'email' => $targetUser['email'],
                    'nivel' => $targetUser['nivel']
                ]);
                
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
    
    /**
     * Obtener permisos por defecto según nivel
     */
    private function getDefaultPermissions($nivel) {
        switch ($nivel) {
            case 'root':
                return ['all'];
            case 'admin':
                return ['dashboard', 'estudios', 'informes', 'gestionInformes', 'usuarios', 'plantillas', 'visor'];
            case 'user':
                return ['dashboard', 'informes', 'grabacion'];
            default:
                return ['dashboard'];
        }
    }
    
    /**
     * Generar contraseña temporal
     */
    private function generateTemporaryPassword() {
        return 'Temp' . rand(1000, 9999);
    }
    
    /**
     * Log de auditoría
     */
    private function logUserAction($action, $targetUserId, $details = []) {
        try {
            $query = "
                INSERT INTO user_audit_logs (
                    user_id, action, target_user_id, description, 
                    ip_address, user_agent, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, NOW())
            ";
            
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([
                $this->currentUser['id'],
                $action,
                $targetUserId,
                json_encode($details),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            error_log("Error logging user action: " . $e->getMessage());
        }
    }
}

// Procesar request
try {
    $api = new UserManagementAPI();
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($method) {
        case 'GET':
            $result = $api->getUsers();
            break;
            
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            $result = $api->createUser($input);
            break;
            
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            $userId = $_GET['id'] ?? null;
            if (!$userId) {
                throw new Exception("ID de usuario requerido");
            }
            $result = $api->updateUser($userId, $input);
            break;
            
        case 'DELETE':
            $userId = $_GET['id'] ?? null;
            if (!$userId) {
                throw new Exception("ID de usuario requerido");
            }
            $result = $api->deleteUser($userId);
            break;
            
        default:
            throw new Exception("Método no permitido");
    }
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>


