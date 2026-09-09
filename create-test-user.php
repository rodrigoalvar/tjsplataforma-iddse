<?php
/**
 * Script para crear usuario de prueba y sesión activa
 */

require_once 'classes/User.php';
require_once 'config/database.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h2>Crear Usuario de Prueba - TJSMEDICAL</h2>";

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Verificar si ya existe un usuario
    $query = "SELECT COUNT(*) as total FROM usuarios WHERE email = 'test@tjsmedical.com'";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $userExists = $stmt->fetch()['total'] > 0;
    
    if (!$userExists) {
        // Crear usuario de prueba
        $password_hash = password_hash('123456', PASSWORD_DEFAULT);
        
        $query = "INSERT INTO usuarios (nombre, apellido, email, telefono, matricula_profesional, password_hash, email_verificado, activo) 
                 VALUES (?, ?, ?, ?, ?, ?, 1, 1)";
        
        $stmt = $conn->prepare($query);
        $result = $stmt->execute([
            'Usuario',
            'Prueba',
            'test@tjsmedical.com',
            '123456789',
            'MP12345',
            $password_hash
        ]);
        
        if ($result) {
            echo "<p style='color: green;'>✓ Usuario de prueba creado exitosamente</p>";
            echo "<p><strong>Email:</strong> test@tjsmedical.com</p>";
            echo "<p><strong>Contraseña:</strong> 123456</p>";
        } else {
            echo "<p style='color: red;'>✗ Error al crear usuario</p>";
            exit;
        }
    } else {
        echo "<p>Usuario de prueba ya existe</p>";
    }
    
    // Obtener ID del usuario
    $query = "SELECT id FROM usuarios WHERE email = 'test@tjsmedical.com'";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $user = $stmt->fetch();
    $user_id = $user['id'];
    
    // Limpiar sesiones expiradas del usuario
    $query = "UPDATE sesiones SET activa = 0 WHERE usuario_id = ? AND (fecha_expiracion < NOW() OR activa = 0)";
    $stmt = $conn->prepare($query);
    $stmt->execute([$user_id]);
    
    // Crear nueva sesión
    $session_token = bin2hex(random_bytes(32));
    $expiration = date('Y-m-d H:i:s', strtotime('+24 hours'));
    
    $query = "INSERT INTO sesiones (usuario_id, token_sesion, fecha_expiracion, fecha_creacion, activa) 
             VALUES (?, ?, ?, NOW(), 1)";
    
    $stmt = $conn->prepare($query);
    $result = $stmt->execute([$user_id, $session_token, $expiration]);
    
    if ($result) {
        echo "<p style='color: green;'>✓ Sesión creada exitosamente</p>";
        
        // Establecer cookie
        $cookie_set = setcookie(
            'session_token',
            $session_token,
            [
                'expires' => strtotime($expiration),
                'path' => '/',
                'domain' => '',
                'secure' => false,
                'httponly' => false,
                'samesite' => 'Lax'
            ]
        );
        
        if ($cookie_set) {
            echo "<p style='color: green;'>✓ Cookie de sesión establecida</p>";
            echo "<p><strong>Token:</strong> " . substr($session_token, 0, 20) . "...</p>";
            echo "<p><strong>Expira:</strong> {$expiration}</p>";
            
            echo "<hr>";
            echo "<h3>Pruebas disponibles:</h3>";
            echo "<ul>";
            echo "<li><a href='components/informes-manager.html'>Gestor de Informes</a></li>";
            echo "<li><a href='test-session.php'>Verificar Sesión</a></li>";
            echo "<li><a href='api/auth/validate-session.php'>Validar Sesión API</a></li>";
            echo "</ul>";
            
        } else {
            echo "<p style='color: red;'>✗ Error al establecer cookie</p>";
        }
        
    } else {
        echo "<p style='color: red;'>✗ Error al crear sesión</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
    echo "<p>Detalles del error: " . $e->getTraceAsString() . "</p>";
}
?>