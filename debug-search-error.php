<?php
/**
 * Debug específico para capturar errores de search.php
 */

// Habilitar todos los errores
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');

header('Content-Type: text/html; charset=utf-8');

echo "<h2>Debug Search Error - TJSMEDICAL</h2>";

// Simular la petición exacta que hace informes-manager.js
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['page'] = '1';
$_GET['limit'] = '20';

// Obtener token de cookie
if (isset($_COOKIE['session_token'])) {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $_COOKIE['session_token'];
    echo "<p style='color: green;'>Token encontrado en cookie</p>";
} else {
    echo "<p style='color: red;'>No se encontró token en cookie</p>";
    // Intentar obtener token de la base de datos
    try {
        require_once 'config/database.php';
        $database = new Database();
        $pdo = $database->getConnection();
        
        $query = "SELECT token FROM sesiones WHERE expira > NOW() ORDER BY creado DESC LIMIT 1";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $session = $stmt->fetch();
        
        if ($session) {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $session['token'];
            echo "<p style='color: orange;'>Usando token de base de datos</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>Error obteniendo token: " . $e->getMessage() . "</p>";
    }
}

echo "<h3>Ejecutando search.php con captura de errores:</h3>";
echo "<hr>";

// Función para capturar errores
function errorHandler($errno, $errstr, $errfile, $errline) {
    echo "<div style='background: #ffebee; padding: 10px; margin: 5px 0; border-left: 4px solid #f44336;'>";
    echo "<strong>Error {$errno}:</strong> {$errstr}<br>";
    echo "<strong>Archivo:</strong> {$errfile}<br>";
    echo "<strong>Línea:</strong> {$errline}";
    echo "</div>";
    return true;
}

// Función para capturar excepciones
function exceptionHandler($exception) {
    echo "<div style='background: #ffebee; padding: 10px; margin: 5px 0; border-left: 4px solid #f44336;'>";
    echo "<strong>Excepción:</strong> " . $exception->getMessage() . "<br>";
    echo "<strong>Archivo:</strong> " . $exception->getFile() . "<br>";
    echo "<strong>Línea:</strong> " . $exception->getLine() . "<br>";
    echo "<strong>Trace:</strong><pre>" . $exception->getTraceAsString() . "</pre>";
    echo "</div>";
}

// Establecer manejadores de errores
set_error_handler('errorHandler');
set_exception_handler('exceptionHandler');

echo "<div style='background: #e8f5e8; padding: 10px; margin: 10px 0;'>";
echo "<h4>Información de la petición:</h4>";
echo "<p><strong>REQUEST_METHOD:</strong> " . $_SERVER['REQUEST_METHOD'] . "</p>";
echo "<p><strong>GET params:</strong> " . json_encode($_GET) . "</p>";
echo "<p><strong>Authorization header:</strong> " . (isset($_SERVER['HTTP_AUTHORIZATION']) ? substr($_SERVER['HTTP_AUTHORIZATION'], 0, 30) . '...' : 'No establecido') . "</p>";
echo "</div>";

// Capturar toda la salida
ob_start();

try {
    echo "<h4>Iniciando ejecución de search.php...</h4>";
    
    // Incluir search.php
    include 'api/informes/search.php';
    
    echo "<h4>search.php ejecutado sin errores fatales</h4>";
    
} catch (ParseError $e) {
    echo "<div style='background: #ffebee; padding: 10px; margin: 5px 0; border-left: 4px solid #f44336;'>";
    echo "<strong>Error de sintaxis:</strong> " . $e->getMessage() . "<br>";
    echo "<strong>Archivo:</strong> " . $e->getFile() . "<br>";
    echo "<strong>Línea:</strong> " . $e->getLine();
    echo "</div>";
} catch (Error $e) {
    echo "<div style='background: #ffebee; padding: 10px; margin: 5px 0; border-left: 4px solid #f44336;'>";
    echo "<strong>Error fatal:</strong> " . $e->getMessage() . "<br>";
    echo "<strong>Archivo:</strong> " . $e->getFile() . "<br>";
    echo "<strong>Línea:</strong> " . $e->getLine();
    echo "</div>";
} catch (Exception $e) {
    echo "<div style='background: #ffebee; padding: 10px; margin: 5px 0; border-left: 4px solid #f44336;'>";
    echo "<strong>Excepción:</strong> " . $e->getMessage() . "<br>";
    echo "<strong>Archivo:</strong> " . $e->getFile() . "<br>";
    echo "<strong>Línea:</strong> " . $e->getLine();
    echo "</div>";
}

$output = ob_get_clean();

echo "<h3>Resultado de la ejecución:</h3>";
echo "<div style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>";
echo $output;
echo "</div>";

echo "<h3>Log de errores PHP:</h3>";
if (file_exists('php_errors.log')) {
    $errors = file_get_contents('php_errors.log');
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd; max-height: 300px; overflow-y: auto;'>" . htmlspecialchars($errors) . "</pre>";
} else {
    echo "<p>No se encontró archivo de log de errores</p>";
}

// Restaurar manejadores por defecto
restore_error_handler();
restore_exception_handler();
?>