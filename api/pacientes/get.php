<?php
/**
 * API para obtener un paciente específico por ID
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

// Configurar manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Headers JSON
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

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
            error_log('Error validando sesión en pacientes/get.php: ' . $e->getMessage());
        }
    }
    
    // Verificar que el usuario tenga permiso para acceder
    if (!$has_permission) {
        sendJsonResponse(false, null, 'No tienes permisos para acceder a la gestión de pacientes');
    }
    
    // Obtener ID del paciente
    $id = isset($_GET['id']) ? intval($_GET['id']) : null;
    $paciente_id = isset($_GET['paciente_id']) ? trim($_GET['paciente_id']) : null;
    
    if (!$id && !$paciente_id) {
        sendJsonResponse(false, null, 'Se requiere el parámetro id o paciente_id');
    }
    
    // Construir consulta
    if ($id) {
        $query = "SELECT * FROM pacientes WHERE id = :id";
        $stmt = $pdo->prepare($query);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    } else {
        // Buscar por id_interno o idpaciente
        $query = "SELECT * FROM pacientes WHERE id_interno = :paciente_id OR idpaciente = :paciente_id";
        $stmt = $pdo->prepare($query);
        $stmt->bindValue(':paciente_id', $paciente_id);
    }
    
    $stmt->execute();
    $paciente = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$paciente) {
        sendJsonResponse(false, null, 'Paciente no encontrado');
    }
    
    // Formatear datos para compatibilidad
    $paciente['paciente_id'] = $paciente['id_interno'] ?? $paciente['idpaciente'] ?? '';
    $paciente['apellido'] = ''; // La tabla no tiene apellido separado
    $paciente['direccion'] = $paciente['domicilio'] ?? '';
    $paciente['edad'] = null; // No hay fecha de nacimiento en la tabla
    
    sendJsonResponse(true, $paciente);
    
} catch (Exception $e) {
    error_log('Error en get.php (pacientes): ' . $e->getMessage());
    sendJsonResponse(false, null, 'Error al obtener paciente: ' . $e->getMessage());
}

