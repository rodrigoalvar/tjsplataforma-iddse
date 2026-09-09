<?php
/**
 * API Endpoint para registro de usuarios
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../../classes/User.php';

// Solo permitir método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('success' => false, 'message' => 'Método no permitido'));
    exit;
}

try {
    // Obtener datos JSON del request
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validar datos requeridos
    $required_fields = ['nombre', 'apellido', 'email', 'telefono', 'matricula_profesional', 'password'];
    $missing_fields = [];
    
    foreach ($required_fields as $field) {
        if (!isset($input[$field]) || empty(trim($input[$field]))) {
            $missing_fields[] = $field;
        }
    }
    
    if (!empty($missing_fields)) {
        http_response_code(400);
        echo json_encode(array(
            'success' => false, 
            'message' => 'Campos requeridos faltantes: ' . implode(', ', $missing_fields)
        ));
        exit;
    }
    
    // Validar formato de email
    if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => 'Formato de email inválido'));
        exit;
    }
    
    // Validar longitud de contraseña
    if (strlen($input['password']) < 6) {
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => 'La contraseña debe tener al menos 6 caracteres'));
        exit;
    }
    
    // Validar formato de teléfono (básico)
    if (!preg_match('/^[0-9+\-\s()]+$/', $input['telefono'])) {
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => 'Formato de teléfono inválido'));
        exit;
    }
    
    // Crear instancia de usuario
    $user = new User();
    $user->nombre = trim($input['nombre']);
    $user->apellido = trim($input['apellido']);
    $user->email = trim(strtolower($input['email']));
    $user->telefono = trim($input['telefono']);
    $user->matricula_profesional = trim(strtoupper($input['matricula_profesional']));
    $user->password = $input['password'];
    $user->nivel = 'user'; // Siempre USER por defecto
    $user->padre_id = null; // Sin jerarquía por defecto
    
    // Intentar registrar usuario
    $result = $user->register();
    
    if ($result['success']) {
        http_response_code(201);
        echo json_encode($result);
    } else {
        http_response_code(400);
        echo json_encode($result);
    }
    
} catch (Exception $e) {
    error_log("Error en registro: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(array(
        'success' => false, 
        'message' => 'Error interno del servidor'
    ));
}
?>