<?php
/**
 * Script para probar directamente la API get.php
 */

// Simular una solicitud GET directa
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['informe_id'] = '35';

// Obtener un token válido del usuario root
require_once 'classes/User.php';
require_once 'config/database.php';

try {
    $db = getDBConnection();
    
    // Buscar usuario root
    $query = "SELECT * FROM usuarios WHERE nivel = 'root' AND activo = 1 LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $rootUser = $stmt->fetch();
    
    if (!$rootUser) {
        echo "Error: No se encontró usuario root\n";
        exit;
    }
    
    echo "=== USUARIO ROOT ENCONTRADO ===\n";
    echo "ID: {$rootUser['id']}\n";
    echo "Nombre: {$rootUser['nombre']}\n";
    echo "Email: {$rootUser['email']}\n";
    echo "Permisos: {$rootUser['permisos']}\n";
    
    // Crear un token de sesión válido
    $user = new User();
    $sessionToken = $user->createSession($rootUser['id']);
    
    if (!$sessionToken) {
        echo "Error: No se pudo crear token de sesión\n";
        exit;
    }
    
    echo "Token creado: " . substr($sessionToken, 0, 20) . "...\n";
    
    // Simular el header de autorización
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $sessionToken;
    
    echo "\n=== PROBANDO API GET.PHP ===\n";
    
    // Capturar la salida de la API
    ob_start();
    include 'api/informes/get.php';
    $output = ob_get_clean();
    
    echo "Respuesta de la API:\n";
    echo $output . "\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>