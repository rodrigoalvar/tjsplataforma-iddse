<?php
/**
 * Script de depuración para validar sesión
 */

require_once 'classes/User.php';

// Obtener el token más reciente de la base de datos
try {
    $user = new User();
    
    // Obtener el token completo de la base de datos primero
    $database = new Database();
    $conn = $database->getConnection();
    
    $query = "SELECT token_sesion FROM sesiones WHERE activa = 1 ORDER BY fecha_creacion DESC LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    
    $test_token = null;
    if ($stmt->rowCount() > 0) {
        $session = $stmt->fetch();
        $test_token = $session['token_sesion'];
    }
    
    echo "<h2>Debug de Validación de Sesión</h2>";
    echo "<p>Token de prueba: {$test_token}...</p>";
    
    // Probar validación
    $result = $user->validateSession($test_token);
    
    echo "<h3>Resultado de validateSession:</h3>";
    if ($result) {
        echo "<pre>" . print_r($result, true) . "</pre>";
        echo "<p style='color: green;'>✓ Validación exitosa</p>";
    } else {
        echo "<p style='color: red;'>✗ Validación falló</p>";
    }
    
    // Probar con token completo si tenemos acceso a la base de datos
    echo "<h3>Probando con consulta directa a la base de datos:</h3>";
    
    $database = new Database();
    $conn = $database->getConnection();
    
    $query = "SELECT token_sesion, fecha_expiracion, activa FROM sesiones WHERE activa = 1 ORDER BY fecha_creacion DESC LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $session = $stmt->fetch();
        echo "<p>Token completo encontrado: " . $session['token_sesion'] . "</p>";
        echo "<p>Fecha expiración: " . $session['fecha_expiracion'] . "</p>";
        echo "<p>Activa: " . ($session['activa'] ? 'Sí' : 'No') . "</p>";
        
        // Probar con el token completo
        $full_result = $user->validateSession($session['token_sesion']);
        echo "<h4>Resultado con token completo:</h4>";
        if ($full_result) {
            echo "<pre>" . print_r($full_result, true) . "</pre>";
            echo "<p style='color: green;'>✓ Validación con token completo exitosa</p>";
        } else {
            echo "<p style='color: red;'>✗ Validación con token completo falló</p>";
        }
    } else {
        echo "<p style='color: orange;'>No se encontraron sesiones activas</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>