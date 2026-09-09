<?php
echo "Verificando modal con debug...\n";

// Verificar que el archivo existe
if (file_exists('user-management-v2.js')) {
    echo "✓ user-management-v2.js existe\n";
} else {
    echo "✗ user-management-v2.js no existe\n";
}

echo "\nPara probar el modal con debug:\n";
echo "1. Abre: http://localhost/portal_estudios/user-management.html\n";
echo "2. Abre la consola del navegador (F12)\n";
echo "3. Haz clic en el icono de jerarquía de Rodrigo Alvar (debería tener 2 dependientes)\n";
echo "4. En el modal verás información de debug:\n";
echo "   - Debug - dependientes_count: [valor] (tipo)\n";
echo "   - Debug - Valor: \"[valor]\"\n";
echo "   - Debug - Comparación: MAYOR QUE 0 o NO MAYOR QUE 0\n";
echo "5. En la consola verás logs del usuario recibido\n";

echo "\nUsuarios que deberían tener dependientes:\n";
echo "- Rodrigo Alvar (admin): 2 dependientes\n";
echo "- Eugenio Castiglione (user): 1 dependiente\n";
echo "- Usuario Prueba (ID 5): 1 dependiente\n";

echo "\nSi el modal sigue mostrando 0 dependientes, revisa:\n";
echo "1. Los logs en la consola del navegador\n";
echo "2. La información de debug en el modal\n";
echo "3. Si el campo dependientes_count está llegando correctamente\n";
?>


