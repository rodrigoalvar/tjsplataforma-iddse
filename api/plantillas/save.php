<?php
// API para guardar/actualizar plantillas de informes
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../classes/User.php';
    require_once __DIR__ . '/plantillas_common.php';
    
    // Obtener datos del request
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Datos inválidos en el request');
    }
    
    $template_id = isset($input['id']) ? trim($input['id']) : null;
    $nombre = isset($input['name']) ? trim($input['name']) : null;
    $contenido_html = isset($input['content']) ? $input['content'] : null;
    
    // Validar datos requeridos
    if (!$template_id || !$nombre || !$contenido_html) {
        throw new Exception('Faltan datos requeridos: id, name y content son obligatorios');
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
    
    // Para crear plantillas globales: necesita 'plantillas_globales' o 'all'
    $can_create_global_templates = in_array('all', $user_permisos) || 
                                   in_array('plantillas_globales', $user_permisos);
    
    $db = getDBConnection();
    plantillasEnsureTraceColumns($db);
    
    // Verificar si la plantilla ya existe
    $checkQuery = "SELECT id, template_id, usuario_id FROM plantillas WHERE template_id = :template_id";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->bindValue(':template_id', $template_id);
    $checkStmt->execute();
    $existingTemplate = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    $isUpdate = $existingTemplate !== false;
    
    if ($isUpdate) {
        // Verificar permisos para editar
        // Solo el dueño puede editar su propia plantilla
        // O usuarios con 'plantillas'/'all' Y 'ver_todas_plantillas'/'plantillas'/'all' pueden editar cualquier plantilla
        $isOwner = ($existingTemplate['usuario_id'] == $user_id);
        
        if (!$isOwner) {
            // Para editar plantillas de otros, necesita:
            // 1. Tener permiso para gestionar plantillas ('plantillas' o 'all')
            // 2. Tener permiso para ver todas las plantillas ('ver_todas_plantillas', 'plantillas' o 'all')
            if (!$can_manage_plantillas) {
                http_response_code(403);
                throw new Exception('No tiene permisos para gestionar plantillas. Se requiere el permiso "Gestión de Plantillas".');
            }
            if (!$can_view_all_plantillas) {
                http_response_code(403);
                throw new Exception('No tiene permisos para editar plantillas de otros usuarios. Se requiere el permiso "Ver Todas".');
            }
        }
        
        // Actualizar plantilla existente
        // Si es el dueño o tiene permiso 'plantillas'/'all', puede actualizar
        $updateQuery = "UPDATE plantillas 
                       SET nombre = :nombre, 
                           contenido_html = :contenido_html,
                           fecha_modificacion = CURRENT_TIMESTAMP
                       WHERE template_id = :template_id";
        
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->bindValue(':template_id', $template_id);
        $updateStmt->bindValue(':nombre', $nombre);
        $updateStmt->bindValue(':contenido_html', $contenido_html);
        
        $updateStmt->execute();
        
        $action = 'actualizada';
    } else {
        // Insertar nueva plantilla
        // Si el usuario tiene permiso "plantillas_globales", crear plantilla global (usuario_id = NULL)
        // Si no, crear plantilla personal (usuario_id = user_id)
        $template_usuario_id = $can_create_global_templates ? null : $user_id;
        
        $insertQuery = "INSERT INTO plantillas (template_id, nombre, contenido_html, usuario_id, creado_por, activo)
                       VALUES (:template_id, :nombre, :contenido_html, :usuario_id, :creado_por, TRUE)";
        
        $insertStmt = $db->prepare($insertQuery);
        $insertStmt->bindValue(':template_id', $template_id);
        $insertStmt->bindValue(':nombre', $nombre);
        $insertStmt->bindValue(':contenido_html', $contenido_html);
        $insertStmt->bindValue(':usuario_id', $template_usuario_id, $template_usuario_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $insertStmt->bindValue(':creado_por', $user_id, PDO::PARAM_INT);
        
        $insertStmt->execute();
        
        $action = $can_create_global_templates ? 'creada (global)' : 'creada';
        
        // Log para depuración
        error_log(sprintf(
            '[PLANTILLAS][SAVE] Plantilla %s por usuario %d - Global: %s',
            $template_id,
            $user_id,
            $can_create_global_templates ? 'Sí' : 'No'
        ));
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Plantilla ' . $action . ' exitosamente',
        'data' => [
            'template_id' => $template_id,
            'name' => $nombre,
            'action' => $action
        ]
    ]);
    
} catch (PDOException $e) {
    error_log('[PLANTILLAS][SAVE] Error PDO: ' . $e->getMessage());
    
    // Manejar error de duplicado
    if ($e->getCode() == 23000) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Ya existe una plantilla con ese ID. Por favor, use un ID diferente.'
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Error de base de datos: ' . $e->getMessage()
        ]);
    }
} catch (Exception $e) {
    error_log('[PLANTILLAS][SAVE] Error general: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error del servidor: ' . $e->getMessage()
    ]);
}
?>

