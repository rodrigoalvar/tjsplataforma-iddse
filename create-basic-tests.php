<?php
// Test básico para identificar el problema
echo "=== TEST BÁSICO DE DIAGNÓSTICO ===\n\n";

echo "PROBLEMA IDENTIFICADO:\n\n";

echo "Ambos tests dan error 500\n";
echo "Esto indica un problema más profundo\n";
echo "Necesitamos un test más básico\n\n";

echo "CREANDO TEST BÁSICO...\n\n";

$basicTestScript = '<?php
// Test básico sin dependencias complejas
error_reporting(E_ALL);
ini_set("display_errors", 1);

echo "=== TEST BÁSICO SIN DEPENDENCIAS ===\n\n";

echo "PASO 1: Verificar PHP básico\n";
echo "PHP Version: " . phpversion() . "\n";
echo "Working Directory: " . getcwd() . "\n";
echo "Script Location: " . __FILE__ . "\n\n";

echo "PASO 2: Verificar archivos básicos\n";

$files = [
    "../../config/database.php",
    "../../classes/User.php", 
    "../../middleware/auth.php"
];

foreach ($files as $file) {
    $fullPath = __DIR__ . "/" . $file;
    echo "Archivo: " . $file . "\n";
    echo "Ruta completa: " . $fullPath . "\n";
    echo "Existe: " . (file_exists($fullPath) ? "SÍ" : "NO") . "\n";
    echo "Legible: " . (is_readable($fullPath) ? "SÍ" : "NO") . "\n\n";
}

echo "PASO 3: Probar include básico\n";

try {
    echo "Intentando incluir database.php...\n";
    require_once __DIR__ . "/../../config/database.php";
    echo "✅ database.php incluido\n";
    
    if (class_exists("Database")) {
        echo "✅ Clase Database existe\n";
    } else {
        echo "❌ Clase Database NO existe\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error incluyendo database.php: " . $e->getMessage() . "\n";
} catch (Error $e) {
    echo "❌ Fatal Error incluyendo database.php: " . $e->getMessage() . "\n";
}

echo "\nPASO 4: Probar User.php\n";

try {
    echo "Intentando incluir User.php...\n";
    require_once __DIR__ . "/../../classes/User.php";
    echo "✅ User.php incluido\n";
    
    if (class_exists("User")) {
        echo "✅ Clase User existe\n";
    } else {
        echo "❌ Clase User NO existe\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error incluyendo User.php: " . $e->getMessage() . "\n";
} catch (Error $e) {
    echo "❌ Fatal Error incluyendo User.php: " . $e->getMessage() . "\n";
}

echo "\nPASO 5: Probar middleware/auth.php\n";

try {
    echo "Intentando incluir middleware/auth.php...\n";
    require_once __DIR__ . "/../../middleware/auth.php";
    echo "✅ middleware/auth.php incluido\n";
    
    if (function_exists("validateSessionToken")) {
        echo "✅ Función validateSessionToken existe\n";
    } else {
        echo "❌ Función validateSessionToken NO existe\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error incluyendo middleware/auth.php: " . $e->getMessage() . "\n";
} catch (Error $e) {
    echo "❌ Fatal Error incluyendo middleware/auth.php: " . $e->getMessage() . "\n";
}

echo "\n🎯 DIAGNÓSTICO:\n\n";

echo "Este test nos dirá:\n";
echo "- Si los archivos existen y son legibles\n";
echo "- Qué archivo específico causa el error\n";
echo "- Si es un problema de sintaxis o dependencias\n";
echo "- Dónde está el problema exacto\n\n";
?>';

file_put_contents('api/informes/test-basic.php', $basicTestScript);

echo "✅ Test básico creado: api/informes/test-basic.php\n";
echo "📋 URL para probar: http://localhost/portal_estudios/api/informes/test-basic.php\n\n";

echo "CREANDO TEST AÚN MÁS BÁSICO...\n\n";

$minimalTestScript = '<?php
// Test minimalista
echo "PHP funciona correctamente\n";
echo "Ubicación: " . __FILE__ . "\n";
echo "Directorio: " . __DIR__ . "\n";

// Verificar solo si los archivos existen
$files = [
    "../../config/database.php",
    "../../classes/User.php", 
    "../../middleware/auth.php"
];

echo "\nVerificación de archivos:\n";
foreach ($files as $file) {
    $exists = file_exists(__DIR__ . "/" . $file);
    echo $file . ": " . ($exists ? "EXISTE" : "NO EXISTE") . "\n";
}
?>';

file_put_contents('api/informes/test-minimal.php', $minimalTestScript);

echo "✅ Test minimalista creado: api/informes/test-minimal.php\n";
echo "📋 URL para probar: http://localhost/portal_estudios/api/informes/test-minimal.php\n\n";

echo "INSTRUCCIONES:\n\n";

echo "1. Probar primero: http://localhost/portal_estudios/api/informes/test-minimal.php\n";
echo "   - Si funciona: PHP básico está OK\n";
echo "   - Si falla: problema de configuración del servidor\n\n";

echo "2. Probar después: http://localhost/portal_estudios/api/informes/test-basic.php\n";
echo "   - Identifica qué archivo específico causa el error\n";
echo "   - Muestra el error exacto\n\n";

echo "DIAGNÓSTICO ESPERADO:\n\n";

echo "Si test-minimal.php funciona:\n";
echo "✅ PHP básico funciona\n";
echo "✅ El problema está en las dependencias\n";
echo "✅ Necesitamos identificar qué archivo falla\n\n";

echo "Si test-minimal.php falla:\n";
echo "❌ Problema de configuración del servidor\n";
echo "❌ PHP no funciona correctamente\n";
echo "❌ Necesitamos revisar configuración\n\n";

echo "Si test-basic.php funciona:\n";
echo "✅ Todas las dependencias están OK\n";
echo "✅ El problema está en la lógica de list.php\n\n";

echo "Si test-basic.php falla:\n";
echo "❌ Identifica qué archivo específico causa el error\n";
echo "❌ Muestra el error exacto\n";
echo "❌ Podemos corregir el problema específico\n\n";
?>
