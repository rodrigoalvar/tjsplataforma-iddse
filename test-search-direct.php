<?php
/**
 * Test directo de search.php con token válido
 */

// Obtener token de sesión válido
require_once 'config/database.php';
require_once 'classes/User.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h2>Test Search Directo - TJSMEDICAL</h2>";

try {
    // Obtener un token válido de la base de datos
    $database = new Database();
    $pdo = $database->getConnection();
    
    $query = "SELECT token FROM sesiones WHERE expira > NOW() ORDER BY creado DESC LIMIT 1";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $session = $stmt->fetch();
    
    if (!$session) {
        echo "<p style='color: red;'>No hay sesiones válidas. Creando una nueva...</p>";
        
        // Crear una nueva sesión
        $user = new User($pdo);
        $token = bin2hex(random_bytes(32));
        $expira = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        $insert_query = "INSERT INTO sesiones (usuario_id, token, expira) VALUES (1, ?, ?)";
        $insert_stmt = $pdo->prepare($insert_query);
        $insert_stmt->execute([$token, $expira]);
        
        echo "<p style='color: green;'>Nueva sesión creada con token: " . substr($token, 0, 20) . "...</p>";
    } else {
        $token = $session['token'];
        echo "<p style='color: green;'>Token válido encontrado: " . substr($token, 0, 20) . "...</p>";
    }
    
    // Crear URL con token
    $url = "http://localhost:8000/api/informes/search.php?page=1&limit=20&token=" . urlencode($token);
    
    echo "<h3>Probando API:</h3>";
    echo "<p><a href='{$url}' target='_blank'>Probar search.php con token</a></p>";
    
    // Hacer petición usando cURL
    echo "<h3>Resultado de la petición:</h3>";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "<p>Código HTTP: {$httpCode}</p>";
    
    if ($response) {
        echo "<pre>" . htmlspecialchars($response) . "</pre>";
    } else {
        echo "<p style='color: red;'>No se recibió respuesta</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
    echo "<p>Archivo: " . $e->getFile() . " Línea: " . $e->getLine() . "</p>";
}
?>