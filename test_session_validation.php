<?php
/**
 * Script para probar validación de sesión con token existente
 */

require_once 'classes/User.php';
require_once 'config/database.php';

try {
    $db = getDBConnection();
    
    // Buscar una sesión activa del usuario root
    $query = "SELECT s.token_sesion as token, u.id, u.nombre, u.email, u.nivel, u.permisos 
              FROM sesiones s 
              JOIN usuarios u ON s.usuario_id = u.id 
              WHERE u.nivel = 'root' AND s.fecha_expiracion > NOW() AND s.activa = 1
              ORDER BY s.fecha_creacion DESC LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $session = $stmt->fetch();
    
    if (!$session) {
        echo "No se encontró sesión activa para usuario root\n";
        echo "Creando nueva sesión...\n";
        
        // Buscar usuario root
        $query = "SELECT * FROM usuarios WHERE nivel = 'root' AND activo = 1 LIMIT 1";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $rootUser = $stmt->fetch();
        
        if (!$rootUser) {
            echo "Error: No se encontró usuario root\n";
            exit;
        }
        
        // Crear sesión manualmente
        $token = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        $query = "INSERT INTO sesiones (usuario_id, token_sesion, fecha_expiracion, fecha_creacion, activa) VALUES (?, ?, ?, NOW(), 1)";
        $stmt = $db->prepare($query);
        $stmt->execute([$rootUser['id'], $token, $expiry]);
        
        echo "Sesión creada con token: " . substr($token, 0, 20) . "...\n";
        
        // Obtener la sesión recién creada
        $query = "SELECT s.token_sesion as token, u.id, u.nombre, u.email, u.nivel, u.permisos 
                  FROM sesiones s 
                  JOIN usuarios u ON s.usuario_id = u.id 
                  WHERE s.token_sesion = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$token]);
        $session = $stmt->fetch();
    }
    
    echo "=== SESIÓN ENCONTRADA ===\n";
    echo "Token: " . substr($session['token'], 0, 20) . "...\n";
    echo "Usuario: {$session['nombre']} ({$session['email']})\n";
    echo "Nivel: {$session['nivel']}\n";
    echo "Permisos: {$session['permisos']}\n";
    
    // Probar validación
    $user = new User();
    $userData = $user->validateSession($session['token']);
    
    if ($userData) {
        echo "\n✓ Token válido\n";
        echo "Usuario validado: {$userData['nombre']}\n";
        echo "Permisos: {$userData['permisos']}\n";
        
        // Ahora probar la API
        echo "\n=== PROBANDO API GET.PHP ===\n";
        
        // Simular la solicitud
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET['informe_id'] = '35';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $session['token'];
        
        // Capturar la salida de la API
        ob_start();
        include 'api/informes/get.php';
        $output = ob_get_clean();
        
        echo "Respuesta de la API:\n";
        echo $output . "\n";
        
    } else {
        echo "\n✗ Token inválido\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>