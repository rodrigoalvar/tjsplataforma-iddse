<?php
/**
 * Sistema de Permisos Granular
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * Middleware para verificación de permisos por sección
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';

class PermissionManager {
    private $pdo;
    private $currentUser;
    
    public function __construct() {
        $this->pdo = getDBConnection();
    }
    
    /**
     * Verificar si el usuario actual tiene un permiso específico
     */
    public function hasPermission($permission, $userId = null) {
        try {
            if ($userId === null) {
                $userId = $this->getCurrentUserId();
            }
            
            if (!$userId) {
                return false;
            }
            
            $user = $this->getUserById($userId);
            if (!$user) {
                return false;
            }
            
            // ROOT tiene todos los permisos
            if ($user['nivel'] === 'root') {
                return true;
            }
            
            // Verificar permisos específicos
            $permissions = json_decode($user['permisos'], true) ?: [];
            
            // Verificar permiso específico
            if (in_array($permission, $permissions)) {
                return true;
            }
            
            // Verificar permiso 'all'
            if (in_array('all', $permissions)) {
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("Error verificando permisos: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Verificar si puede gestionar usuarios
     */
    public function canManageUsers($userId = null) {
        return $this->hasPermission('usuarios', $userId);
    }
    
    /**
     * Verificar si puede gestionar estudios
     */
    public function canManageStudies($userId = null) {
        return $this->hasPermission('estudios', $userId);
    }
    
    /**
     * Verificar si puede crear informes
     */
    public function canCreateReports($userId = null) {
        return $this->hasPermission('informes', $userId);
    }
    
    /**
     * Verificar si puede gestionar informes
     */
    public function canManageReports($userId = null) {
        return $this->hasPermission('gestionInformes', $userId);
    }
    
    /**
     * Verificar si puede grabar audio
     */
    public function canRecordAudio($userId = null) {
        return $this->hasPermission('grabacion', $userId);
    }
    
    /**
     * Verificar si puede acceder a configuración
     */
    public function canAccessConfig($userId = null) {
        return $this->hasPermission('configuracion', $userId);
    }
    
    /**
     * Verificar si puede gestionar plantillas
     */
    public function canManageTemplates($userId = null) {
        return $this->hasPermission('plantillas', $userId);
    }
    
    /**
     * Verificar si puede acceder al visor DICOM
     */
    public function canAccessViewer($userId = null) {
        return $this->hasPermission('visor', $userId);
    }
    
    /**
     * Verificar si puede asignar estudios a un usuario específico
     */
    public function canAssignToUser($currentUserId, $targetUserId) {
        try {
            $currentUser = $this->getUserById($currentUserId);
            $targetUser = $this->getUserById($targetUserId);
            
            if (!$currentUser || !$targetUser) {
                return false;
            }
            
            // Verificar permisos del usuario actual
            $permissions = json_decode($currentUser['permisos'], true) ?: [];
            
            // Si tiene permiso 'all', puede asignar a cualquiera
            if (in_array('all', $permissions)) {
                return true;
            }
            
            // Si tiene permiso 'asignaciones', puede asignar a cualquiera
            if (in_array('asignaciones', $permissions)) {
                return true;
            }
            
            // Si no tiene permiso de asignaciones, verificar por nivel
            switch($currentUser['nivel']) {
                case 'root':
                    return true; // ROOT puede asignar a cualquiera
                    
                case 'admin':
                    return true; // ADMIN puede asignar a cualquiera
                    
                case 'user':
                    // USER solo puede asignar a sus hijos directos
                    return $this->isDirectChild($currentUserId, $targetUserId);
                    
                default:
                    return false;
            }
            
        } catch (Exception $e) {
            error_log("Error verificando permisos de asignación: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Verificar si puede modificar un usuario específico
     */
    public function canModifyUser($currentUserId, $targetUserId) {
        try {
            $currentUser = $this->getUserById($currentUserId);
            $targetUser = $this->getUserById($targetUserId);
            
            if (!$currentUser || !$targetUser) {
                return false;
            }
            
            // ROOT puede modificar a todos
            if ($currentUser['nivel'] === 'root') {
                return true;
            }
            
            // ADMIN no puede modificar ROOT
            if ($currentUser['nivel'] === 'admin' && $targetUser['nivel'] === 'root') {
                return false;
            }
            
            // ADMIN puede modificar a otros usuarios
            if ($currentUser['nivel'] === 'admin') {
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("Error verificando permisos de modificación: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obtener permisos disponibles del sistema
     */
    public function getSystemPermissions() {
        try {
            $query = "SELECT * FROM system_permissions ORDER BY category, permission_name";
            $stmt = $this->pdo->prepare($query);
            $stmt->execute();
            
            $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Agrupar por categoría
            $grouped = [];
            foreach ($permissions as $permission) {
                $grouped[$permission['category']][] = $permission;
            }
            
            return $grouped;
            
        } catch (Exception $e) {
            error_log("Error obteniendo permisos del sistema: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Obtener permisos de un usuario
     */
    public function getUserPermissions($userId) {
        try {
            $user = $this->getUserById($userId);
            if (!$user) {
                return [];
            }
            
            return json_decode($user['permisos'], true) ?: [];
            
        } catch (Exception $e) {
            error_log("Error obteniendo permisos de usuario: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Actualizar permisos de un usuario
     */
    public function updateUserPermissions($userId, $permissions) {
        try {
            // Validar permisos
            $validPermissions = $this->getValidPermissions();
            foreach ($permissions as $permission) {
                if (!in_array($permission, $validPermissions)) {
                    throw new Exception("Permiso inválido: {$permission}");
                }
            }
            
            $query = "UPDATE usuarios SET permisos = ?, updated_at = NOW() WHERE id = ?";
            $stmt = $this->pdo->prepare($query);
            $result = $stmt->execute([json_encode($permissions), $userId]);
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Error actualizando permisos: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obtener lista de permisos válidos
     */
    private function getValidPermissions() {
        try {
            $query = "SELECT permission_key FROM system_permissions";
            $stmt = $this->pdo->prepare($query);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
            
        } catch (Exception $e) {
            error_log("Error obteniendo permisos válidos: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Verificar si un usuario es hijo directo de otro
     */
    private function isDirectChild($parentId, $childId) {
        try {
            $query = "SELECT id FROM usuarios WHERE id = ? AND padre_id = ? AND activo = 1";
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([$childId, $parentId]);
            
            return $stmt->rowCount() > 0;
            
        } catch (Exception $e) {
            error_log("Error verificando relación padre-hijo: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obtener usuario por ID
     */
    private function getUserById($userId) {
        try {
            $query = "SELECT * FROM usuarios WHERE id = ? AND activo = 1";
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([$userId]);
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Error obteniendo usuario: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Obtener ID del usuario actual desde sesión
     */
    private function getCurrentUserId() {
        try {
            $token = $this->getSessionToken();
            if (!$token) {
                return null;
            }
            
            $userData = getUserFromToken($token);
            return $userData ? $userData['id'] : null;
            
        } catch (Exception $e) {
            error_log("Error obteniendo usuario actual: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Obtener token de sesión
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
}

/**
 * Funciones de conveniencia para verificación de permisos
 */

function requirePermission($permission) {
    $permissionManager = new PermissionManager();
    if (!$permissionManager->hasPermission($permission)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'No tienes permisos para realizar esta acción'
        ]);
        exit;
    }
}

function requireUserManagement() {
    $permissionManager = new PermissionManager();
    if (!$permissionManager->canManageUsers()) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'No tienes permisos para gestionar usuarios'
        ]);
        exit;
    }
}

function requireStudyManagement() {
    $permissionManager = new PermissionManager();
    if (!$permissionManager->canManageStudies()) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'No tienes permisos para gestionar estudios'
        ]);
        exit;
    }
}

function requireReportManagement() {
    $permissionManager = new PermissionManager();
    if (!$permissionManager->canManageReports()) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'No tienes permisos para gestionar informes'
        ]);
        exit;
    }
}

function requireConfigAccess() {
    $permissionManager = new PermissionManager();
    if (!$permissionManager->canAccessConfig()) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'No tienes permisos para acceder a la configuración'
        ]);
        exit;
    }
}

/**
 * Función para verificar permisos en JavaScript
 */
function getPermissionCheckScript() {
    return "
    <script>
    // Verificar permisos del usuario actual
    async function checkUserPermission(permission) {
        try {
            const response = await fetch('api/users/check-permission.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ permission: permission })
            });
            
            const result = await response.json();
            return result.success && result.hasPermission;
        } catch (error) {
            console.error('Error verificando permisos:', error);
            return false;
        }
    }
    
    // Verificar si puede gestionar usuarios
    async function canManageUsers() {
        return await checkUserPermission('usuarios');
    }
    
    // Verificar si puede gestionar estudios
    async function canManageStudies() {
        return await checkUserPermission('estudios');
    }
    
    // Verificar si puede gestionar informes
    async function canManageReports() {
        return await checkUserPermission('gestionInformes');
    }
    
    // Verificar si puede acceder a configuración
    async function canAccessConfig() {
        return await checkUserPermission('configuracion');
    }
    </script>
    ";
}
?>


