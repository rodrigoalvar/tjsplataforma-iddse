<?php
/**
 * API para buscar paciente por ID PACS
 * Utilizado para autocompletar formulario al crear nuevo paciente
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
    $has_permission = false;
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
                $user_permisos = isset($user_data['permisos']) ? $user_data['permisos'] : [];
                
                if (is_string($user_permisos)) {
                    $user_permisos = json_decode($user_permisos, true) ?: [];
                }
                
                $has_permission = in_array('all', $user_permisos) || 
                                 in_array('pacientes', $user_permisos);
            }
        } catch (Exception $e) {
            error_log('Error validando sesión en pacientes/search-by-idpacs.php: ' . $e->getMessage());
        }
    }
    
    if (!$has_permission) {
        sendJsonResponse(false, null, 'No tienes permisos para gestionar pacientes');
    }
    
    // Obtener ID PACS
    $idpaciente = isset($_GET['idpaciente']) ? trim($_GET['idpaciente']) : null;
    
    if (empty($idpaciente)) {
        sendJsonResponse(false, null, 'ID PACS requerido');
    }
    
    // Buscar en la tabla pacientes
    $query = "SELECT id_interno, idpaciente, nombre, telefono, email, domicilio, activo 
              FROM pacientes 
              WHERE idpaciente = :idpaciente 
              ORDER BY fecha_creacion DESC 
              LIMIT 1";
    
    $stmt = $pdo->prepare($query);
    $stmt->bindValue(':idpaciente', $idpaciente);
    $stmt->execute();
    $paciente = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($paciente) {
        // Formatear datos para compatibilidad
        $paciente['paciente_id'] = $paciente['id_interno'] ?? '';
        $paciente['direccion'] = $paciente['domicilio'] ?? '';
        
        sendJsonResponse(true, [
            'found_in_db' => true,
            'paciente' => $paciente
        ]);
    } else {
        // No encontrado en BD, buscar en Orthanc
        require_once __DIR__ . '/../OrthancClient.php';
        
        try {
            $orthancClient = new OrthancClient();
            $serverStatus = $orthancClient->getServerStatus();
            
            if ($serverStatus['status'] !== 'connected') {
                sendJsonResponse(false, null, 'No se puede conectar al servidor Orthanc');
            }
            
            // Buscar estudios por PatientID
            $studies = $orthancClient->findStudiesByPatientId($idpaciente);
            
            if (!empty($studies)) {
                // Obtener nombre del paciente del primer estudio
                $patientName = $studies[0]['patient_name'] ?? '';
                
                // Limpiar nombre anonimizado
                if (preg_match('/^Anonymized/i', $patientName)) {
                    $patientName = '';
                }
                
                sendJsonResponse(true, [
                    'found_in_db' => false,
                    'found_in_pacs' => true,
                    'nombre' => $patientName,
                    'idpaciente' => $idpaciente
                ]);
            } else {
                sendJsonResponse(true, [
                    'found_in_db' => false,
                    'found_in_pacs' => false,
                    'message' => 'No se encontró el paciente en PACS ni en la base de datos'
                ]);
            }
            
        } catch (Exception $e) {
            error_log('Error buscando en Orthanc: ' . $e->getMessage());
            sendJsonResponse(false, null, 'Error al buscar en PACS: ' . $e->getMessage());
        }
    }
    
} catch (Exception $e) {
    error_log('Error en search-by-idpacs.php: ' . $e->getMessage());
    sendJsonResponse(false, null, 'Error al buscar paciente: ' . $e->getMessage());
}


