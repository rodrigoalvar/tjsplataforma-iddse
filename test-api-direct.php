<?php
echo "=== VERIFICACIÓN DIRECTA DE LA API DE PERMISOS ===\n\n";

echo "🔍 Probando la API directamente...\n\n";

// Simular una llamada GET a la API
$_SERVER['REQUEST_METHOD'] = 'GET';

// Capturar la salida de la API
ob_start();

try {
    // Incluir la API directamente
    include 'api/users/permissions-simple.php';
    $output = ob_get_contents();
} catch (Exception $e) {
    $output = "Error: " . $e->getMessage();
}

ob_end_clean();

echo "📤 RESPUESTA DE LA API:\n";
echo $output . "\n\n";

// Intentar decodificar el JSON
$data = json_decode($output, true);

if ($data) {
    echo "📊 DATOS DECODIFICADOS:\n";
    echo "Success: " . ($data['success'] ? 'true' : 'false') . "\n";
    
    if (isset($data['data'])) {
        echo "Categorías disponibles: " . implode(', ', array_keys($data['data'])) . "\n\n";
        
        if (isset($data['data']['estudios'])) {
            echo "🔹 PERMISOS EN CATEGORÍA 'ESTUDIOS':\n";
            foreach ($data['data']['estudios'] as $perm) {
                echo "   • {$perm['permission_name']} ({$perm['permission_key']})\n";
                if ($perm['permission_key'] === 'pacs_query') {
                    echo "   ✅ PACS Query encontrado!\n";
                }
            }
        } else {
            echo "❌ No se encontró la categoría 'estudios'\n";
        }
    } else {
        echo "❌ No se encontraron datos en la respuesta\n";
    }
} else {
    echo "❌ No se pudo decodificar el JSON\n";
}

echo "\n✅ Verificación completada.\n";
?>
