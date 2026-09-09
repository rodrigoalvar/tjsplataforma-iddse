<?php
// Test desde la ubicación correcta de list.php
error_reporting(E_ALL);
ini_set("display_errors", 1);

echo "=== TEST DESDE UBICACIÓN CORRECTA ===\n\n";

// Simular cookie
$_COOKIE["session_token"] = "ba22f765b6b44fe4a76d65cf1c957dd8b1e59f98bcff611e39c8669adcd94e32";

echo "Cookie simulada: " . substr($_COOKIE["session_token"], 0, 20) . "...\n\n";

try {
    echo "PASO 1: Incluir archivos desde ubicación correcta\n";
    
    // Rutas como las usa list.php
    require_once __DIR__ . "/../../config/database.php";
    require_once __DIR__ . "/../../classes/User.php";
    require_once __DIR__ . "/../../middleware/auth.php";
    
    echo "✅ Todos los archivos incluidos correctamente\n\n";
    
    echo "PASO 2: Verificar funciones disponibles\n";
    
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
                echo "✅ Email: " . $userData["email"] . "\n";
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
        
    } else {
        echo "❌ CORRECCIÓN INCOMPLETA\n";
        echo "❌ Algunas funciones siguen faltando\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Archivo: " . $e->getFile() . "\n";
    echo "Línea: " . $e->getLine() . "\n";
}
?>