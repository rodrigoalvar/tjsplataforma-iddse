<?php
/**
 * API Simplificada con Datos Reales
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

// Desactivar display de errores
ini_set('display_errors', 0);
error_reporting(E_ERROR | E_PARSE);

// Configurar headers para JSON
header('Content-Type: application/json; charset=utf-8');

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
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    
    switch ($method) {
        case 'GET':
            // Obtener usuarios reales
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
                        u.created_at,
                        p.nombre as padre_nombre,
                        p.apellido as padre_apellido,
                        p.email as padre_email
                      FROM usuarios u
                      LEFT JOIN usuarios p ON u.padre_id = p.id
                      WHERE u.activo = 1
                      ORDER BY u.nivel DESC, u.nombre ASC";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute();
            $users = $stmt->fetchAll();
            
            // Procesar permisos
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
            
            sendJsonResponse(true, [
                'users' => $users,
                'hierarchy' => [] // Simplificado por ahora
            ]);
            break;
            
        case 'PUT':
            $userId = $_GET['id'] ?? null;
            
            if (!$userId) {
                sendJsonResponse(false, null, 'ID de usuario requerido');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                sendJsonResponse(false, null, 'Datos inválidos');
            }
            
            // Verificar que el usuario existe
            $query = "SELECT id FROM usuarios WHERE id = ? AND activo = 1";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            if (!$user) {
                sendJsonResponse(false, null, 'Usuario no encontrado');
            }
            
            // Actualizar usuario
            $fields = [];
            $values = [];
            
            $allowedFields = ['nombre', 'apellido', 'email', 'telefono', 'matricula_profesional', 'nivel', 'padre_id', 'especialidad'];
            
            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    $fields[] = "{$field} = ?";
                    $values[] = $input[$field];
                }
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
                    'message' => 'Usuario actualizado exitosamente',
                    'user_id' => $userId
                ]);
            } else {
                sendJsonResponse(false, null, 'Error actualizando usuario');
            }
            break;
            
        case 'DELETE':
            $userId = $_GET['id'] ?? null;
            
            if (!$userId) {
                sendJsonResponse(false, null, 'ID de usuario requerido');
            }
            
            // Desactivar usuario (soft delete)
            $query = "UPDATE usuarios SET activo = 0 WHERE id = ?";
            $stmt = $pdo->prepare($query);
            $result = $stmt->execute([$userId]);
            
            if ($result) {
                sendJsonResponse(true, [
                    'message' => 'Usuario eliminado exitosamente',
                    'user_id' => $userId
                ]);
            } else {
                sendJsonResponse(false, null, 'Error eliminando usuario');
            }
            break;
            
        default:
            sendJsonResponse(false, null, 'Método no permitido');
    }
    
} catch (Exception $e) {
    sendJsonResponse(false, null, $e->getMessage());
}
?>


