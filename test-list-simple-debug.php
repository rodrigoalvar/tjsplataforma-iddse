<?php
// Test simple para list-simple.php
echo "=== TEST SIMPLE PARA LIST-SIMPLE.PHP ===\n\n";

echo "🔍 PASO 1: Verificar archivo\n\n";

$filePath = 'api/informes/list-simple.php';
if (file_exists($filePath)) {
    echo "✅ list-simple.php existe\n";
} else {
    echo "❌ list-simple.php NO existe\n";
    exit;
}

echo "\n🔍 PASO 2: Verificar sintaxis PHP\n\n";

$output = shell_exec('php -l api/informes/list-simple.php 2>&1');
echo "Resultado de php -l:\n";
echo $output . "\n";

echo "\n🔍 PASO 3: Probar ejecución con error_reporting\n\n";

// Crear un test que capture errores
$testScript = '<?php
error_reporting(E_ALL);
ini_set("display_errors", 1);
echo "Iniciando test...\n";

try {
    require_once "api/informes/list-simple.php";
    echo "✅ list-simple.php ejecutado sin errores\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
} catch (Error $e) {
    echo "❌ Fatal Error: " . $e->getMessage() . "\n";
}
?>';

file_put_contents('test-list-simple.php', $testScript);
echo "✅ Test script creado\n";

echo "\n🔍 PASO 4: Ejecutar test\n\n";

$result = shell_exec('php test-list-simple.php 2>&1');
echo "Resultado:\n";
echo $result . "\n";

echo "\n🎯 CONCLUSIÓN:\n\n";

echo "Si hay errores de sintaxis o ejecución,\n";
echo "los veremos en el output anterior.\n";
?>
