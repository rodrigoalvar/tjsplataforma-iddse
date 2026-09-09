<?php
/**
 * API Endpoint para verificación de email
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../../classes/User.php';

// Solo permitir método GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(array('success' => false, 'message' => 'Método no permitido'));
    exit;
}

try {
    // Validar que se proporcione el token
    if (!isset($_GET['token']) || empty(trim($_GET['token']))) {
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => 'Token de verificación requerido'));
        exit;
    }
    
    $token = trim($_GET['token']);
    
    // Crear instancia de usuario
    $user = new User();
    
    // Verificar email
    $result = $user->verifyEmail($token);
    
    if ($result['success']) {
        http_response_code(200);
        echo json_encode($result);
    } else {
        http_response_code(400);
        echo json_encode($result);
    }
    
} catch (Exception $e) {
    error_log("Error en verificación de email: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(array(
        'success' => false, 
        'message' => 'Error interno del servidor'
    ));
}
?>