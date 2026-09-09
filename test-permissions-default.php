<?php
echo "=== VERIFICACIÓN DE PERMISOS POR DEFECTO ===\n\n";

echo "🔍 Verificando permisos por defecto en el código...\n\n";

// Simular los permisos por defecto del código
$permissions = [
    ['permission_key' => 'dashboard', 'permission_name' => 'Acceso al Dashboard', 'description' => 'Permite acceder al panel principal del sistema', 'category' => 'general'],
    ['permission_key' => 'estudios', 'permission_name' => 'Gestión de Estudios', 'description' => 'Permite gestionar y asignar estudios médicos', 'category' => 'estudios'],
    ['permission_key' => 'pacs_query', 'permission_name' => 'PACS Query', 'description' => 'Permite consultar el PACS directamente', 'category' => 'estudios'],
    ['permission_key' => 'informes', 'permission_name' => 'Creación de Informes', 'description' => 'Permite crear informes médicos', 'category' => 'informes'],
    ['permission_key' => 'gestionInformes', 'permission_name' => 'Gestión de Informes', 'description' => 'Permite gestionar todos los informes del sistema', 'category' => 'informes'],
    ['permission_key' => 'grabacion', 'permission_name' => 'Grabación de Audio', 'description' => 'Permite grabar audios para informes', 'category' => 'audio'],
    ['permission_key' => 'plantillas', 'permission_name' => 'Gestión de Plantillas', 'description' => 'Permite gestionar plantillas de informes', 'category' => 'plantillas'],
    ['permission_key' => 'visor', 'permission_name' => 'Visor DICOM', 'description' => 'Permite acceder al visor de imágenes DICOM', 'category' => 'visor'],
    ['permission_key' => 'configuracion', 'permission_name' => 'Configuración del Sistema', 'description' => 'Permite acceder a la configuración del sistema', 'category' => 'admin'],
    ['permission_key' => 'usuarios', 'permission_name' => 'Gestión de Usuarios', 'description' => 'Permite gestionar usuarios del sistema', 'category' => 'admin'],
    ['permission_key' => 'all', 'permission_name' => 'Acceso Completo', 'description' => 'Acceso completo a todas las funcionalidades', 'category' => 'admin']
];

// Agrupar permisos por categoría
$permissionsByCategory = [];
foreach ($permissions as $permission) {
    $category = $permission['category'];
    if (!isset($permissionsByCategory[$category])) {
        $permissionsByCategory[$category] = [];
    }
    $permissionsByCategory[$category][] = $permission;
}

echo "📋 PERMISOS POR CATEGORÍA:\n\n";

foreach ($permissionsByCategory as $category => $perms) {
    echo "🔹 CATEGORÍA: " . strtoupper($category) . "\n";
    foreach ($perms as $perm) {
        echo "   • {$perm['permission_name']} ({$perm['permission_key']})\n";
    }
    echo "\n";
}

echo "🔍 VERIFICACIÓN ESPECÍFICA:\n\n";

// Verificar si PACS Query está en estudios
if (isset($permissionsByCategory['estudios'])) {
    echo "✅ Categoría 'estudios' encontrada\n";
    echo "📊 Permisos en estudios:\n";
    foreach ($permissionsByCategory['estudios'] as $perm) {
        echo "   • {$perm['permission_name']} ({$perm['permission_key']})\n";
        if ($perm['permission_key'] === 'pacs_query') {
            echo "   ✅ PACS Query encontrado!\n";
        }
    }
} else {
    echo "❌ Categoría 'estudios' NO encontrada\n";
}

echo "\n🔍 JSON OUTPUT:\n";
echo json_encode($permissionsByCategory, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

echo "\n\n✅ Verificación completada.\n";
?>
