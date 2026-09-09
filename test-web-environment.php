<?php
// Test que simula entorno web exacto
error_reporting(E_ALL);
ini_set("display_errors", 1);

echo "=== TEST ENTORNO WEB SIMULADO ===\n\n";

// Simular entorno web completo
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["HTTP_HOST"] = "localhost";
$_SERVER["SERVER_NAME"] = "localhost";
$_SERVER["REQUEST_URI"] = "/portal_estudios/api/informes/list.php?page=1";
$_GET["page"] = "1";

// Simular cookie
$_COOKIE["session_token"] = "ba22f765b6b44fe4a76d65cf1c957dd8b1e59f98bcff611e39c8669adcd94e32";

echo "Entorno simulado:\n";
echo "REQUEST_METHOD: " . $_SERVER["REQUEST_METHOD"] . "\n";
echo "HTTP_HOST: " . $_SERVER["HTTP_HOST"] . "\n";
echo "REQUEST_URI: " . $_SERVER["REQUEST_URI"] . "\n";
echo "GET[page]: " . $_GET["page"] . "\n";
echo "COOKIE[session_token]: " . substr($_COOKIE["session_token"], 0, 20) . "...\n\n";

echo "PASO 1: Probar includes con rutas relativas\n\n";

try {
    // Probar rutas como las usa list.php
    $dbPath = __DIR__ . "/../../config/database.php";
    $userPath = __DIR__ . "/../../classes/User.php";
    $authPath = __DIR__ . "/../../middleware/auth.php";
    
    echo "Rutas a verificar:\n";
    echo "Database: " . $dbPath . "\n";
    echo "User: " . $userPath . "\n";
    echo "Auth: " . $authPath . "\n\n";
    
    if (file_exists($dbPath)) {
        echo "✅ Database path existe\n";
        require_once $dbPath;
        echo "✅ Database incluido\n";
    } else {
        echo "❌ Database path NO existe\n";
    }
    
    if (file_exists($userPath)) {
        echo "✅ User path existe\n";
        require_once $userPath;
        echo "✅ User incluido\n";
    } else {
        echo "❌ User path NO existe\n";
    }
    
    if (file_exists($authPath)) {
        echo "✅ Auth path existe\n";
        require_once $authPath;
        echo "✅ Auth incluido\n";
    } else {
        echo "❌ Auth path NO existe\n";
    }
    
    echo "\nPASO 2: Probar funciones de autenticación\n\n";
    
    $token = $_COOKIE["session_token"];
    
    if (function_exists("validateSessionToken")) {
        echo "✅ Función validateSessionToken existe\n";
        $isValid = validateSessionToken($token);
        echo "Resultado: " . ($isValid ? "VÁLIDO" : "INVÁLIDO") . "\n";
    } else {
        echo "❌ Función validateSessionToken NO existe\n";
    }
    
    if (function_exists("getUserFromToken")) {
        echo "✅ Función getUserFromToken existe\n";
        $userData = getUserFromToken($token);
        if ($userData) {
            echo "Usuario: " . $userData["nombre"] . "\n";
        } else {
            echo "❌ getUserFromToken devolvió false\n";
        }
    } else {
        echo "❌ Función getUserFromToken NO existe\n";
    }
    
    echo "\nPASO 3: Probar conexión a BD\n\n";
    
    try {
        $database = new Database();
        $pdo = $database->getConnection();
        echo "✅ Conexión a BD exitosa\n";
        
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM informes");
        $result = $stmt->fetch();
        echo "✅ Query de informes exitosa: " . $result["total"] . " informes\n";
        
    } catch (Exception $e) {
        echo "❌ Error en BD: " . $e->getMessage() . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error general: " . $e->getMessage() . "\n";
    echo "Archivo: " . $e->getFile() . "\n";
    echo "Línea: " . $e->getLine() . "\n";
}

echo "\n🎯 DIAGNÓSTICO:\n\n";

echo "Si hay errores en las rutas, el problema está en:\n";
echo "1. Rutas relativas incorrectas\n";
echo "2. Archivos faltantes\n";
echo "3. Permisos de archivos\n\n";

echo "Si hay errores en las funciones, el problema está en:\n";
echo "1. middleware/auth.php no se carga correctamente\n";
echo "2. Funciones no están definidas\n";
echo "3. Dependencias faltantes\n\n";

echo "Si hay errores en BD, el problema está en:\n";
echo "1. Configuración de BD\n";
echo "2. Permisos de BD\n";
echo "3. Tablas faltantes\n\n";
?>