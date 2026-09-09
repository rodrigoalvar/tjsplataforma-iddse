<?php
/**
 * API Endpoint para logout de usuarios
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../../classes/User.php';
require_once '../../config/database.php';

// Solo permitir método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('success' => false, 'message' => 'Método no permitido'));
    exit;
}

try {
    // Obtener token de sesión de cookie o header
    $session_token = null;
    
    if (isset($_COOKIE['session_token'])) {
        $session_token = $_COOKIE['session_token'];
    } else {
        // Buscar en headers
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            $auth_header = $headers['Authorization'];
            if (strpos($auth_header, 'Bearer ') === 0) {
                $session_token = substr($auth_header, 7);
            }
        }
    }
    
    if (!$session_token) {
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => 'Token de sesión no encontrado'));
        exit;
    }
    
    // Crear instancia de usuario
    $user = new User();
    
    // Obtener información del usuario antes de cerrar sesión (para cerrar sesiones móviles)
    $userData = $user->validateSession($session_token);
    $userId = $userData ? $userData['id'] : null;

    if ($userId) {
        $auditLoggerPath = __DIR__ . '/../../modules/audit-manager/AuditLogger.php';
        if (file_exists($auditLoggerPath)) {
            require_once $auditLoggerPath;
            try {
                $pdo = getDBConnection();
                if ($pdo && AuditLogger::tableExists($pdo)) {
                    AuditLogger::log($pdo, [
                        'user_id' => (int) $userId,
                        'action_key' => 'auth.logout',
                        'description' => 'Cierre de sesión',
                    ]);
                }
            } catch (Exception $e) {
                error_log('Audit logout log: ' . $e->getMessage());
            }
        }
    }
    
    // Cerrar todas las sesiones móviles activas del usuario antes de cerrar sesión
    if ($userId) {
        try {
            $pdo = getDBConnection();
            
            // Marcar todas las sesiones móviles del usuario como expiradas
            // Establecer last_activity a hace 5 minutos para que el móvil detecte inmediatamente
            // que el workspace cerró sesión (el móvil verifica si last_activity es < 2 minutos)
            $closeMobileSessionsSql = "UPDATE mobile_sessions 
                                      SET status = 'expired', 
                                          expires_at = NOW(),
                                          last_activity = DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                                      WHERE created_by = ? AND status IN ('active', 'connected')";
            $closeStmt = $pdo->prepare($closeMobileSessionsSql);
            $closeStmt->execute([$userId]);
            $closedCount = $closeStmt->rowCount();
            
            if ($closedCount > 0) {
                error_log("✅ Cerradas $closedCount sesión(es) móvil(es) del usuario $userId durante logout");
            }
        } catch (Exception $e) {
            // No fallar el logout si hay error al cerrar sesiones móviles
            error_log("⚠️ Error cerrando sesiones móviles durante logout: " . $e->getMessage());
        }
    }
    
    // Cerrar sesión
    if ($user->logout($session_token)) {
        // Eliminar cookie
        setcookie('session_token', '', time() - 3600, '/', '', false, true);
        
        http_response_code(200);
        echo json_encode(array('success' => true, 'message' => 'Sesión cerrada exitosamente'));
    } else {
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => 'Error al cerrar sesión'));
    }
    
} catch (Exception $e) {
    error_log("Error en logout: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(array(
        'success' => false, 
        'message' => 'Error interno del servidor'
    ));
}
?>