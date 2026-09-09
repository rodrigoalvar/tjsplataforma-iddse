<?php
// Test de validacion de sesion
header("Content-Type: application/json");

try {
    require_once "classes/User.php";
    
    $token = $_COOKIE["session_token"] ?? null;
    
    if (!$token) {
        echo json_encode(["success" => false, "message" => "No hay cookie session_token"]);
        exit;
    }
    
    $user = new User();
    $result = $user->validateSession($token);
    
    if ($result) {
        echo json_encode([
            "success" => true,
            "user" => $result,
            "token" => $token
        ]);
    } else {
        echo json_encode(["success" => false, "message" => "Token invalido"]);
    }
    
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>