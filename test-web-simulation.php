<?php
// Test web simulation para list-simple.php
echo "=== TEST WEB SIMULATION ===\n\n";

// Simular entorno web
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['page'] = '1';

echo "✅ Variables web simuladas:\n";
echo "   REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD'] . "\n";
echo "   GET[page]: " . $_GET['page'] . "\n\n";

echo "🔍 Ejecutando list-simple.php...\n\n";

// Capturar output
ob_start();
include 'api/informes/list-simple.php';
$output = ob_get_clean();

echo "📋 RESULTADO:\n\n";
echo $output . "\n\n";

echo "🔍 Análisis del resultado:\n\n";

$data = json_decode($output, true);

if ($data) {
    if ($data['success']) {
        echo "✅ API funcionando correctamente\n";
        echo "   Total informes: " . count($data['data']) . "\n";
        echo "   Total en BD: " . $data['pagination']['total'] . "\n";
        
        if (count($data['data']) > 0) {
            echo "   Primer informe ID: " . $data['data'][0]['id'] . "\n";
            echo "   Usuario: " . $data['data'][0]['usuario_nombre'] . "\n";
        }
    } else {
        echo "❌ API devolvió error: " . $data['error'] . "\n";
    }
} else {
    echo "❌ Respuesta no es JSON válido\n";
}

echo "\n🎯 CONCLUSIÓN:\n\n";

if ($data && $data['success']) {
    echo "✅ list-simple.php funciona correctamente\n";
    echo "✅ Puede ser usado en informes-manager\n";
    echo "✅ Devuelve datos de informes\n\n";
    
    echo "📋 PRÓXIMO PASO:\n\n";
    echo "Actualizar informes-manager.js para usar list-simple.php\n";
} else {
    echo "❌ Hay problemas que necesitan corrección\n";
}
?>
