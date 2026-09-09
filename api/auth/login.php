<?php
/**
 * API Endpoint simplificado para login de usuarios
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
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

try {
    // Obtener datos del request
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validar datos requeridos
    if (empty($input['email']) || empty($input['password'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email y contraseña son requeridos']);
        exit;
    }
    
    // Validar formato de email
    if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Formato de email inválido']);
        exit;
    }
    
    // Crear instancia de usuario y hacer login
    $user = new User();
    $result = $user->login($input['email'], $input['password']);
    
    if ($result['success']) {
        // Establecer cookie de sesión
        $cookie_success = setcookie(
            'session_token',
            $result['session_token'],
            [
                'expires' => time() + (24 * 60 * 60), // 24 horas
                'path' => '/',
                'domain' => '',
                'secure' => false, // Cambiar a true en producción con HTTPS
                'httponly' => false, // Permitir acceso desde JavaScript
                'samesite' => 'Lax'
            ]
        );
        
        if (!$cookie_success) {
            error_log('Advertencia: No se pudo establecer la cookie de sesión');
        }

        $auditLoggerPath = __DIR__ . '/../../modules/audit-manager/AuditLogger.php';
        if (file_exists($auditLoggerPath)) {
            require_once $auditLoggerPath;
            try {
                $pdo = getDBConnection();
                if ($pdo && AuditLogger::tableExists($pdo)) {
                    AuditLogger::log($pdo, [
                        'user_id' => (int) $result['user']['id'],
                        'action_key' => 'auth.login',
                        'description' => 'Inicio de sesión',
                    ]);
                }
            } catch (Exception $e) {
                error_log('Audit login log: ' . $e->getMessage());
            }
        }
        
        http_response_code(200);
        echo json_encode($result);
    } else {
        http_response_code(401);
        echo json_encode($result);
    }
    
} catch (Exception $e) {
    error_log('Error en login: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Error interno del servidor'
    ]);
}
?>