<?php
/**
 * Script para establecer cookie de sesión válida
 */

require_once 'config/database.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h2>Establecer Cookie de Sesión - TJSMEDICAL</h2>";

try {
    // Obtener un token válido de la base de datos
    $database = new Database();
    $pdo = $database->getConnection();
    
    $query = "SELECT token_sesion FROM sesiones WHERE fecha_expiracion > NOW() AND activa = 1 ORDER BY fecha_creacion DESC LIMIT 1";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $session = $stmt->fetch();
    
    if (!$session) {
        echo "<p style='color: red;'>No hay sesiones válidas. Creando una nueva...</p>";
        
        // Crear una nueva sesión
        $token = bin2hex(random_bytes(32));
        $expira = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        $insert_query = "INSERT INTO sesiones (usuario_id, token_sesion, fecha_expiracion, activa) VALUES (1, ?, ?, 1)";
        $insert_stmt = $pdo->prepare($insert_query);
        $insert_stmt->execute([$token, $expira]);
        
        echo "<p style='color: green;'>Nueva sesión creada</p>";
    } else {
        $token = $session['token_sesion'];
        echo "<p style='color: green;'>Token válido encontrado</p>";
    }
    
    // Establecer la cookie
    $cookieSet = setcookie('session_token', $token, [
        'expires' => time() + (24 * 60 * 60), // 24 horas
        'path' => '/',
        'domain' => '',
        'secure' => false, // Cambiar a true en HTTPS
        'httponly' => false, // Permitir acceso desde JavaScript
        'samesite' => 'Lax'
    ]);
    
    if ($cookieSet) {
        echo "<p style='color: green;'>✓ Cookie 'session_token' establecida correctamente</p>";
        echo "<p>Token: " . substr($token, 0, 20) . "...</p>";
    } else {
        echo "<p style='color: red;'>✗ Error estableciendo la cookie</p>";
    }
    
    echo "<hr>";
    echo "<h3>Verificar Cookie:</h3>";
    
    if (isset($_COOKIE['session_token'])) {
        echo "<p style='color: green;'>✓ Cookie encontrada: " . substr($_COOKIE['session_token'], 0, 20) . "...</p>";
    } else {
        echo "<p style='color: orange;'>Cookie no encontrada (puede tardar en aparecer)</p>";
    }
    
    echo "<h3>Probar Gestor de Informes:</h3>";
    echo "<p><a href='components/informes-manager.html'>Abrir Gestor de Informes</a></p>";
    
    echo "<h3>JavaScript para verificar cookie:</h3>";
    echo "<script>";
    echo "console.log('Cookies disponibles:', document.cookie);";
    echo "const sessionToken = document.cookie.split('; ').find(row => row.startsWith('session_token='));";
    echo "if (sessionToken) {";
    echo "    console.log('Token encontrado:', sessionToken.split('=')[1].substring(0, 20) + '...');";
    echo "    document.getElementById('js-result').innerHTML = '<p style=\"color: green;\">✓ Cookie accesible desde JavaScript</p>';";
    echo "} else {";
    echo "    console.log('Token no encontrado en cookies');";
    echo "    document.getElementById('js-result').innerHTML = '<p style=\"color: red;\">✗ Cookie no accesible desde JavaScript</p>';";
    echo "}";
    echo "</script>";
    
    echo "<div id='js-result'></div>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
    echo "<p>Archivo: " . $e->getFile() . " Línea: " . $e->getLine() . "</p>";
}
?>