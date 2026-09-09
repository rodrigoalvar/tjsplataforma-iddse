<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'config/database.php';

try {
    // Get a valid token from the database
    $pdo = getDBConnection();
    
    $query = "SELECT token_sesion FROM sesiones WHERE fecha_expiracion > NOW() AND activa = 1 ORDER BY fecha_creacion DESC LIMIT 1";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $session = $stmt->fetch();
    
    if (!$session) {
        // Create a new session
        $token = bin2hex(random_bytes(32));
        $expira = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        $insert_query = "INSERT INTO sesiones (usuario_id, token_sesion, fecha_expiracion, activa) VALUES (1, ?, ?, 1)";
        $insert_stmt = $pdo->prepare($insert_query);
        $insert_stmt->execute([$token, $expira]);
        
        echo json_encode(['success' => true, 'token' => $token, 'created' => true]);
    } else {
        $token = $session['token_sesion'];
        echo json_encode(['success' => true, 'token' => $token, 'created' => false]);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>