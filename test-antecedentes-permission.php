<?php
/**
 * Script de prueba para verificar que el permiso Antecedentes se muestra correctamente
 */

echo "=== VERIFICACIÓN DE PERMISO ANTECEDENTES ===\n\n";

// Simular llamada a la API
$_SERVER['REQUEST_METHOD'] = 'GET';

// Capturar la salida de la API
ob_start();
try {
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

if ($data && $data['success']) {
    echo "✅ La API respondió correctamente\n\n";
    
    if (isset($data['data']['estudios'])) {
        echo "📋 PERMISOS EN CATEGORÍA 'ESTUDIOS':\n";
        $encontrado = false;
        
        foreach ($data['data']['estudios'] as $perm) {
            echo "   • {$perm['permission_name']} ({$perm['permission_key']})\n";
            
            if ($perm['permission_key'] === 'antecedentes') {
                echo "   ✅ ANTECEDENTES ENCONTRADO!\n";
                $encontrado = true;
            }
        }
        
        if (!$encontrado) {
            echo "\n❌ ERROR: Antecedentes NO se encontró en la respuesta\n";
        } else {
            echo "\n✅ VERIFICACIÓN COMPLETADA: El permiso Antecedentes está disponible\n";
        }
    } else {
        echo "❌ No se encontró la categoría 'estudios'\n";
    }
    
    // Mostrar todas las categorías
    echo "\n📋 CATEGORÍAS DISPONIBLES:\n";
    foreach (array_keys($data['data']) as $category) {
        $count = count($data['data'][$category]);
        echo "   • {$category}: {$count} permisos\n";
    }
    
} else {
    echo "❌ Error en la respuesta de la API\n";
    if (isset($data['error'])) {
        echo "   Error: {$data['error']}\n";
    }
}

echo "\n";
?>

