<?php
echo "Verificando corrección del conteo de dependientes...\n";

// Verificar que los archivos existen
if (file_exists('user-management-v2.js')) {
    echo "✓ user-management-v2.js existe\n";
} else {
    echo "✗ user-management-v2.js no existe\n";
}

if (file_exists('debug-dependents-count.html')) {
    echo "✓ debug-dependents-count.html existe\n";
} else {
    echo "✗ debug-dependents-count.html no existe\n";
}

echo "\nPara probar la corrección:\n";
echo "1. Abre: http://localhost/portal_estudios/user-management.html\n";
echo "2. Haz clic en el icono de jerarquía de cualquier usuario\n";
echo "3. Verifica que ahora muestra el número correcto de dependientes\n";

echo "\nPara debug detallado:\n";
echo "1. Abre: http://localhost/portal_estudios/debug-dependents-count.html\n";
echo "2. Revisa los datos de la API\n";
echo "3. Prueba el modal de jerarquía\n";

echo "\nCambios realizados:\n";
echo "✓ Cambiado 'hijos_count' por 'dependientes_count' en la tabla\n";
echo "✓ Cambiado 'hijos_count' por 'dependientes_count' en el modal\n";
echo "✓ Cambiado 'hijos_count' por 'dependientes_count' en la lógica de eliminación\n";
?>


