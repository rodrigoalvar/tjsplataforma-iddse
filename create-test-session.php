<?php
/**
 * Script para crear una sesión de prueba válida
 */

require_once 'config/database.php';

try {
    $db = getDBConnection();
    
    // Verificar si existe un usuario de prueba
    $checkUser = "SELECT id FROM usuarios WHERE email = 'test@example.com' LIMIT 1";
    $stmt = $db->prepare($checkUser);
    $stmt->execute();
    $user = $stmt->fetch();
    
    $userId = null;
    
    if (!$user) {
        // Crear usuario de prueba
        $insertUser = "INSERT INTO usuarios (nombre, apellido, email, password_hash, activo, fecha_creacion) 
                       VALUES (?, ?, ?, ?, 1, NOW())";
        $stmt = $db->prepare($insertUser);
        $stmt->execute([
            'Usuario',
            'Prueba', 
            'test@example.com',
            password_hash('test123', PASSWORD_DEFAULT)
        ]);
        $userId = $db->lastInsertId();
        echo "Usuario de prueba creado con ID: $userId\n";
    } else {
        $userId = $user['id'];
        echo "Usuario de prueba existente con ID: $userId\n";
    }
    
    // Crear token de sesión
    $sessionToken = 'test_session_' . bin2hex(random_bytes(16));
    
    // Limpiar sesiones anteriores del usuario
    $cleanSessions = "UPDATE sesiones SET activa = 0 WHERE usuario_id = ?";
    $stmt = $db->prepare($cleanSessions);
    $stmt->execute([$userId]);
    
    // Crear nueva sesión válida por 24 horas
    $insertSession = "INSERT INTO sesiones (usuario_id, token_sesion, fecha_creacion, fecha_expiracion, activa) 
                      VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR), 1)";
    $stmt = $db->prepare($insertSession);
    $stmt->execute([$userId, $sessionToken]);
    
    echo "Sesión creada exitosamente\n";
    echo "Token de sesión: $sessionToken\n";
    echo "\nPara usar este token, ejecuta en la consola del navegador:\n";
    echo "localStorage.setItem('sessionToken', '$sessionToken');\n";
    echo "document.cookie = 'session_token=$sessionToken; path=/';\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>