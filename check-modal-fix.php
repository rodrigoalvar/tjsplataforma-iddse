<?php
echo "Verificando modal de jerarquías...\n";

// Verificar que el archivo existe
if (file_exists('hierarchy-management.html')) {
    echo "✓ hierarchy-management.html existe\n";
} else {
    echo "✗ hierarchy-management.html no existe\n";
}

// Verificar que el archivo de prueba existe
if (file_exists('test-hierarchy-modal.html')) {
    echo "✓ test-hierarchy-modal.html existe\n";
} else {
    echo "✗ test-hierarchy-modal.html no existe\n";
}

// Verificar que la API existe
if (file_exists('api/users/hierarchy.php')) {
    echo "✓ api/users/hierarchy.php existe\n";
} else {
    echo "✗ api/users/hierarchy.php no existe\n";
}

echo "\nPara probar el modal:\n";
echo "1. Abre: http://localhost/portal_estudios/hierarchy-management.html\n";
echo "2. Haz clic en 'Asignar Jerarquía' o en el botón de editar de cualquier usuario\n";
echo "3. Verifica que el dropdown de usuarios tiene datos\n";
echo "4. Verifica que al editar un usuario, se muestra seleccionado por defecto\n";

echo "\nPara pruebas detalladas:\n";
echo "1. Abre: http://localhost/portal_estudios/test-hierarchy-modal.html\n";
echo "2. Usa los botones de prueba para verificar el funcionamiento\n";
?>


