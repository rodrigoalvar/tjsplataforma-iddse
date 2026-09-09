<?php
/**
 * Script para FORZAR limpieza COMPLETA de todos los cachés
 * Ejecutar desde navegador: http://localhost/portal_estudios/force-clear-all-cache.php
 */

echo "<h1>🔥 LIMPIEZA FORZADA DE CACHÉS</h1>";
echo "<pre>";

// 1. Limpiar OpCache
echo "\n1. OpCache:\n";
if (function_exists('opcache_reset')) {
    if (opcache_reset()) {
        echo "   ✅ OpCache limpiado\n";
    } else {
        echo "   ❌ No se pudo limpiar OpCache\n";
    }
} else {
    echo "   ⚠️  OpCache no disponible\n";
}

// 2. Limpiar archivos de caché de Composer
echo "\n2. Composer autoloader:\n";
if (file_exists('vendor/composer')) {
    $files = glob('vendor/composer/*.php');
    echo "   Archivos de autoloader: " . count($files) . "\n";
}

// 3. Verificar que OrthancPacsSender tenga los cambios
echo "\n3. Verificando OrthancPacsSender.php:\n";
$content = file_get_contents('api/OrthancPacsSender.php');
if (strpos($content, 'findStudyByStudyInstanceUID') !== false) {
    echo "   ✅ Método findStudyByStudyInstanceUID presente\n";
} else {
    echo "   ❌ Método findStudyByStudyInstanceUID NO ENCONTRADO\n";
}

if (strpos($content, 'PARENT_RESOLVED') !== false) {
    echo "   ✅ Logs de PARENT_RESOLVED presentes\n";
} else {
    echo "   ❌ Logs de PARENT_RESOLVED NO ENCONTRADOS\n";
}

if (strpos($content, 'unset($dicomTags[\'StudyInstanceUID\'])') !== false) {
    echo "   ✅ Eliminación de StudyInstanceUID presente\n";
} else {
    echo "   ❌ Eliminación de StudyInstanceUID NO ENCONTRADA\n";
}

// 4. Info de PHP
echo "\n4. PHP Info:\n";
echo "   Versión: " . PHP_VERSION . "\n";
echo "   OpCache habilitado: " . (ini_get('opcache.enable') ? 'SI' : 'NO') . "\n";
echo "   Realpath cache size: " . ini_get('realpath_cache_size') . "\n";

echo "\n=== ACCIONES REQUERIDAS ===\n";
echo "1. REINICIA WAMP (Servicios → Reiniciar todos los servicios)\n";
echo "2. Cierra TODAS las ventanas del navegador\n";
echo "3. Abre el navegador de nuevo\n";
echo "4. Presiona Ctrl+Shift+Delete → Limpiar caché\n";
echo "5. Ve a informes-manager y presiona Ctrl+F5 (recarga forzada)\n";
echo "6. Prueba 'Enviar a PACS' nuevamente\n";

echo "</pre>";

// Botón para verificar de nuevo
echo '<hr>';
echo '<form method="GET">';
echo '<button type="submit" style="padding: 10px 20px; font-size: 16px; background: #007bff; color: white; border: none; cursor: pointer;">🔄 Verificar de nuevo</button>';
echo '</form>';

echo '<hr>';
echo '<a href="components/informes-manager.html" style="padding: 10px 20px; background: #28a745; color: white; text-decoration: none; display: inline-block;">→ Ir a Gestión de Informes</a>';
?>

