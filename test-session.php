<?php
/**
 * Script de prueba para verificar y crear sesión de prueba
 */

require_once 'classes/User.php';
require_once 'config/database.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h2>Test de Sesión - TJSMEDICAL</h2>";

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Verificar si hay usuarios en la base de datos
    $query = "SELECT COUNT(*) as total FROM usuarios WHERE activo = 1";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $userCount = $stmt->fetch()['total'];
    
    echo "<p>Usuarios activos en la base de datos: {$userCount}</p>";
    
    if ($userCount == 0) {
        echo "<p style='color: red;'>No hay usuarios en la base de datos. Necesitas crear un usuario primero.</p>";
        echo "<p><a href='install.php'>Ir a instalación</a></p>";
        exit;
    }
    
    // Verificar sesiones activas
    $query = "SELECT COUNT(*) as total FROM sesiones WHERE activa = 1 AND fecha_expiracion > NOW()";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $sessionCount = $stmt->fetch()['total'];
    
    echo "<p>Sesiones activas: {$sessionCount}</p>";
    
    // Obtener la sesión más reciente
    $query = "SELECT s.token_sesion, s.fecha_expiracion, u.email, u.nombre 
             FROM sesiones s 
             JOIN usuarios u ON s.usuario_id = u.id 
             WHERE s.activa = 1 AND s.fecha_expiracion > NOW() 
             ORDER BY s.fecha_creacion DESC LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $session = $stmt->fetch();
        echo "<p>Sesión más reciente:</p>";
        echo "<ul>";
        echo "<li>Usuario: {$session['nombre']} ({$session['email']})</li>";
        echo "<li>Token: " . substr($session['token_sesion'], 0, 20) . "...</li>";
        echo "<li>Expira: {$session['fecha_expiracion']}</li>";
        echo "</ul>";
        
        // Establecer cookie de prueba
        $cookie_set = setcookie(
            'session_token',
            $session['token_sesion'],
            [
                'expires' => strtotime($session['fecha_expiracion']),
                'path' => '/',
                'domain' => '',
                'secure' => false,
                'httponly' => false,
                'samesite' => 'Lax'
            ]
        );
        
        if ($cookie_set) {
            echo "<p style='color: green;'>✓ Cookie de sesión establecida correctamente</p>";
            echo "<p><a href='components/informes-manager.html'>Probar Gestor de Informes</a></p>";
        } else {
            echo "<p style='color: red;'>✗ Error al establecer cookie</p>";
        }
        
    } else {
        echo "<p style='color: orange;'>No hay sesiones activas válidas.</p>";
        echo "<p><a href='login.html'>Iniciar sesión</a></p>";
    }
    
    // Mostrar cookies actuales
    echo "<h3>Cookies actuales:</h3>";
    if (empty($_COOKIE)) {
        echo "<p>No hay cookies establecidas</p>";
    } else {
        echo "<ul>";
        foreach ($_COOKIE as $name => $value) {
            if ($name === 'session_token') {
                echo "<li><strong>{$name}:</strong> " . substr($value, 0, 20) . "...</li>";
            } else {
                echo "<li><strong>{$name}:</strong> {$value}</li>";
            }
        }
        echo "</ul>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>