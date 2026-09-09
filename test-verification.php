<?php
// Test de verificación de rutas corregidas
error_reporting(E_ALL);
ini_set("display_errors", 1);

echo "=== TEST DE VERIFICACIÓN DE RUTAS ===\n\n";

// Simular cookie
$_COOKIE["session_token"] = "ba22f765b6b44fe4a76d65cf1c957dd8b1e59f98bcff611e39c8669adcd94e32";

echo "Cookie simulada: " . substr($_COOKIE["session_token"], 0, 20) . "...\n\n";

try {
    echo "PASO 1: Verificar rutas corregidas\n";
    
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
    
    echo "\nPASO 2: Verificar funciones disponibles\n";
    
    if (function_exists("validateSessionToken")) {
        echo "✅ Función validateSessionToken existe\n";
    } else {
        echo "❌ Función validateSessionToken NO existe\n";
    }
    
    if (function_exists("getUserFromToken")) {
        echo "✅ Función getUserFromToken existe\n";
    } else {
        echo "❌ Función getUserFromToken NO existe\n";
    }
    
    if (class_exists("User")) {
        echo "✅ Clase User existe\n";
    } else {
        echo "❌ Clase User NO existe\n";
    }
    
    if (class_exists("Database")) {
        echo "✅ Clase Database existe\n";
    } else {
        echo "❌ Clase Database NO existe\n";
    }
    
    echo "\nPASO 3: Probar validación de sesión\n";
    
    $token = $_COOKIE["session_token"];
    
    if (function_exists("validateSessionToken")) {
        $isValid = validateSessionToken($token);
        echo "validateSessionToken: " . ($isValid ? "VÁLIDO" : "INVÁLIDO") . "\n";
        
        if ($isValid && function_exists("getUserFromToken")) {
            $userData = getUserFromToken($token);
            if ($userData) {
                echo "✅ Usuario obtenido: " . $userData["nombre"] . "\n";
            } else {
                echo "❌ No se pudo obtener usuario\n";
            }
        }
    }
    
    echo "\n🎯 RESULTADO:\n\n";
    
    if (function_exists("validateSessionToken") && function_exists("getUserFromToken")) {
        echo "✅ CORRECCIÓN EXITOSA\n";
        echo "✅ Todas las funciones están disponibles\n";
        echo "✅ list.php debería funcionar ahora\n\n";
        
        echo "PRÓXIMO PASO:\n";
        echo "Probar informes-manager.html\n";
        echo "URL: http://localhost/portal_estudios/components/informes-manager.html\n";
        
    } else {
        echo "❌ CORRECCIÓN INCOMPLETA\n";
        echo "❌ Algunas funciones siguen faltando\n";
        echo "❌ Necesita más ajustes\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Archivo: " . $e->getFile() . "\n";
    echo "Línea: " . $e->getLine() . "\n";
}
?>