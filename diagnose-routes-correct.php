<?php
echo "=== DIAGNÓSTICO DE RUTAS ===\n\n";

echo "PROBLEMA IDENTIFICADO:\n\n";

echo "Las rutas siguen siendo incorrectas:\n";
echo "❌ Database path NO existe\n";
echo "❌ User path NO existe\n";
echo "❌ Auth path NO existe\n\n";

echo "ANÁLISIS DE RUTAS:\n\n";

echo "Desde test-verification.php (raíz):\n";
echo "❌ C:\\wamp64\\www\\PORTAL_ESTUDIOS/../../config/database.php\n";
echo "   Esto va a: C:\\wamp64\\www\\config/database.php (INCORRECTO)\n\n";

echo "Desde api/informes/list.php:\n";
echo "❌ C:\\wamp64\\www\\PORTAL_ESTUDIOS\\api\\informes/../../config/database.php\n";
echo "   Esto va a: C:\\wamp64\\www\\PORTAL_ESTUDIOS\\config/database.php (CORRECTO)\n\n";

echo "SOLUCIÓN:\n\n";

echo "Las rutas en list.php están correctas para su ubicación.\n";
echo "El problema debe estar en otra parte.\n\n";

echo "VERIFICANDO RUTAS REALES...\n\n";

// Verificar rutas reales desde api/informes/
$realDbPath = __DIR__ . '/api/informes/../../config/database.php';
$realUserPath = __DIR__ . '/api/informes/../../classes/User.php';
$realAuthPath = __DIR__ . '/api/informes/../../middleware/auth.php';

echo "Rutas reales desde api/informes/:\n";
echo "Database: " . $realDbPath . "\n";
echo "User: " . $realUserPath . "\n";
echo "Auth: " . $realAuthPath . "\n\n";

if (file_exists($realDbPath)) {
    echo "✅ Database path existe\n";
} else {
    echo "❌ Database path NO existe\n";
}

if (file_exists($realUserPath)) {
    echo "✅ User path existe\n";
} else {
    echo "❌ User path NO existe\n";
}

if (file_exists($realAuthPath)) {
    echo "✅ Auth path existe\n";
} else {
    echo "❌ Auth path NO existe\n";
}

echo "\nCREANDO TEST DESDE UBICACIÓN CORRECTA...\n\n";

$correctTestScript = '<?php
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
?>';

file_put_contents('api/informes/test-correct-location.php', $correctTestScript);

echo "✅ Test creado en ubicación correcta: api/informes/test-correct-location.php\n";
echo "📋 URL para probar: http://localhost/portal_estudios/api/informes/test-correct-location.php\n\n";

echo "INSTRUCCIONES:\n\n";

echo "1. Abrir: http://localhost/portal_estudios/api/informes/test-correct-location.php\n";
echo "2. Verificar que funciona desde la ubicación correcta\n";
echo "3. Si funciona, el problema está en el contexto de ejecución\n";
echo "4. Si no funciona, hay otro problema\n\n";

echo "DIAGNÓSTICO:\n\n";

echo "Si el test desde api/informes/ funciona:\n";
echo "✅ Las rutas están correctas\n";
echo "✅ Los archivos existen\n";
echo "✅ El problema está en el contexto de ejecución de list.php\n\n";

echo "Si el test desde api/informes/ no funciona:\n";
echo "❌ Hay un problema más profundo\n";
echo "❌ Necesitamos investigar más\n\n";
?>
