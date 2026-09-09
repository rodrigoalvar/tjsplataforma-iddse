<?php
/**
 * Script para probar la API get.php con token de sesión válido
 */

// Leer el token de sesión
$token = trim(file_get_contents('root_session_token.txt'));

echo "=== Probando API get.php con token válido ===\n";
echo "Token: " . substr($token, 0, 20) . "...\n";

// Simular la solicitud GET
$_GET['informe_id'] = '35';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

echo "\nSimulando GET request para informe_id = 35\n";
echo "Authorization header: Bearer " . substr($token, 0, 20) . "...\n";

// Capturar la salida de la API
ob_start();

try {
    // Cambiar al directorio de la API para que las rutas relativas funcionen
    $originalDir = getcwd();
    chdir('api/informes');
    
    // Incluir la API
    include 'get.php';
    
    // Restaurar directorio original
    chdir($originalDir);
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

$output = ob_get_clean();

echo "\n=== Respuesta de la API ===\n";
echo $output;
echo "\n=== Fin de la respuesta ===\n";
?>