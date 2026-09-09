<?php
/**
 * API para eliminar pacientes (soft delete)
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

// Configurar manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Headers JSON
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: DELETE, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Verificar método HTTP (aceptar DELETE o POST)
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'DELETE' && $method !== 'POST') {
    sendJsonResponse(false, null, 'Método no permitido. Use DELETE o POST.');
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
    require_once __DIR__ . '/../../config/database.php';
    $pdo = getDBConnection();
    
    if (!$pdo) {
        throw new Exception('Error de conexión a la base de datos');
    }
    
    // Verificar sesión y permisos del usuario
    $user_id = null;
    $has_permission = false;
    
    // Verificar si hay token de autenticación
    $headers = getallheaders();
    $token = null;
    
    if (isset($headers['Authorization'])) {
        $authHeader = $headers['Authorization'];
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $token = $matches[1];
        }
    }
    
    if (!$token && isset($_COOKIE['session_token'])) {
        $token = $_COOKIE['session_token'];
    }
    
    if ($token) {
        try {
            require_once __DIR__ . '/../../classes/User.php';
            $user = new User();
            $user_data = $user->validateSession($token);
            
            if ($user_data && is_array($user_data)) {
                $user_id = $user_data['id'];
                
                $user_permisos = isset($user_data['permisos']) ? $user_data['permisos'] : [];
                
                if (is_string($user_permisos)) {
                    $user_permisos = json_decode($user_permisos, true) ?: [];
                }
                
                $has_permission = in_array('all', $user_permisos) || 
                                 in_array('pacientes', $user_permisos);
            }
        } catch (Exception $e) {
            error_log('Error validando sesión en pacientes/delete.php: ' . $e->getMessage());
        }
    }
    
    // Verificar que el usuario tenga permiso para acceder
    if (!$has_permission) {
        sendJsonResponse(false, null, 'No tienes permisos para gestionar pacientes');
    }
    
    // Obtener ID del paciente
    $input = json_decode(file_get_contents('php://input'), true);
    $id = isset($input['id']) ? intval($input['id']) : (isset($_GET['id']) ? intval($_GET['id']) : null);
    
    if (!$id) {
        sendJsonResponse(false, null, 'ID de paciente requerido');
    }
    
    // Verificar que el paciente existe
    $checkQuery = "SELECT id, nombre, id_interno, idpaciente FROM pacientes WHERE id = :id";
    $checkStmt = $pdo->prepare($checkQuery);
    $checkStmt->bindValue(':id', $id, PDO::PARAM_INT);
    $checkStmt->execute();
    $paciente = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$paciente) {
        sendJsonResponse(false, null, 'Paciente no encontrado');
    }
    
    // Eliminar físicamente el paciente de la tabla
    $query = "DELETE FROM pacientes WHERE id = :id";
    $stmt = $pdo->prepare($query);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $result = $stmt->execute();
    
    // Verificar que la eliminación se ejecutó correctamente
    if (!$result) {
        $errorInfo = $stmt->errorInfo();
        error_log('Error eliminando paciente (delete.php): ' . print_r($errorInfo, true));
        throw new Exception('Error al eliminar el paciente: ' . ($errorInfo[2] ?? 'Error desconocido'));
    }
    
    // Verificar cuántas filas se eliminaron
    $rowsAffected = $stmt->rowCount();
    if ($rowsAffected === 0) {
        error_log('Warning: No se eliminaron filas al borrar paciente ID: ' . $id);
        throw new Exception('No se pudo eliminar el paciente. Verifique que el ID sea correcto.');
    }
    
    sendJsonResponse(true, [
        'id' => $id,
        'message' => 'Paciente eliminado permanentemente',
        'paciente' => $paciente,
        'rows_affected' => $rowsAffected
    ]);
    
} catch (Exception $e) {
    error_log('Error en delete.php (pacientes): ' . $e->getMessage());
    sendJsonResponse(false, null, 'Error al eliminar paciente: ' . $e->getMessage());
}

