<?php
// API para eliminar plantillas de informes
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../classes/User.php';
    
    // Obtener datos del request
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Datos inválidos en el request');
    }
    
    $template_id = isset($input['id']) || isset($input['template_id']) ? 
                   trim($input['id'] ?? $input['template_id']) : null;
    
    if (!$template_id) {
        throw new Exception('ID de plantilla requerido');
    }
    
    // Validar sesión del usuario
    $token = null;
    
    // Obtener headers (compatible con servidores que no tienen getallheaders())
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    } else {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
    }
    
    if (isset($headers['Authorization'])) {
        $authHeader = $headers['Authorization'];
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $token = $matches[1];
        }
    }
    
    if (!$token) {
        http_response_code(401);
        throw new Exception('Token de sesión requerido');
    }
    
    $user = new User();
    $user_data = $user->validateSession($token);
    
    if (!$user_data) {
        http_response_code(401);
        throw new Exception('Sesión inválida o expirada');
    }
    
    $user_id = $user_data['id'];
    
    // Verificar permisos del usuario
    $user_permisos = isset($user_data['permisos']) ? $user_data['permisos'] : [];
    if (is_string($user_permisos)) {
        $user_permisos = json_decode($user_permisos, true) ?: [];
    }
    
    // Para gestionar plantillas (usar el gestor): necesita 'plantillas' o 'all'
    $can_manage_plantillas = in_array('all', $user_permisos) || 
                            in_array('plantillas', $user_permisos);
    
    // Para editar/eliminar plantillas de otros: necesita 'ver_todas_plantillas', 'plantillas' o 'all'
    // Pero además debe tener 'plantillas' o 'all' para poder usar el gestor
    $can_view_all_plantillas = in_array('all', $user_permisos) || 
                               in_array('plantillas', $user_permisos) ||
                               in_array('ver_todas_plantillas', $user_permisos);
    
    $db = getDBConnection();
    
    // Verificar si la plantilla existe y obtener información
    $checkQuery = "SELECT id, template_id, usuario_id FROM plantillas WHERE template_id = :template_id AND activo = 1";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->bindValue(':template_id', $template_id);
    $checkStmt->execute();
    $template = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$template) {
        http_response_code(404);
        throw new Exception('Plantilla no encontrada');
    }
    
    // Verificar permisos para eliminar
    // Solo el dueño puede eliminar su propia plantilla
    // O usuarios con 'plantillas'/'all' Y 'ver_todas_plantillas'/'plantillas'/'all' pueden eliminar cualquier plantilla
    $isOwner = ($template['usuario_id'] == $user_id);
    
    if (!$isOwner) {
        // Para eliminar plantillas de otros, necesita:
        // 1. Tener permiso para gestionar plantillas ('plantillas' o 'all')
        // 2. Tener permiso para ver todas las plantillas ('ver_todas_plantillas', 'plantillas' o 'all')
        if (!$can_manage_plantillas) {
            http_response_code(403);
            throw new Exception('No tiene permisos para gestionar plantillas. Se requiere el permiso "Gestión de Plantillas".');
        }
        if (!$can_view_all_plantillas) {
            http_response_code(403);
            throw new Exception('No tiene permisos para eliminar plantillas de otros usuarios. Se requiere el permiso "Ver Todas".');
        }
    }
    
    // Marcar como inactiva en lugar de eliminar físicamente (soft delete)
    $deleteQuery = "UPDATE plantillas 
                    SET activo = FALSE,
                        fecha_modificacion = CURRENT_TIMESTAMP
                    WHERE template_id = :template_id";
    
    $deleteStmt = $db->prepare($deleteQuery);
    $deleteStmt->bindValue(':template_id', $template_id);
    $deleteStmt->execute();
    
    echo json_encode([
        'success' => true,
        'message' => 'Plantilla eliminada exitosamente',
        'data' => [
            'template_id' => $template_id
        ]
    ]);
    
} catch (PDOException $e) {
    error_log('[PLANTILLAS][DELETE] Error PDO: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error de base de datos: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log('[PLANTILLAS][DELETE] Error general: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error del servidor: ' . $e->getMessage()
    ]);
}
?>

