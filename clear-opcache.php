<?php
/**
 * Script para limpiar OpCache
 * Ejecutar desde el navegador: http://localhost/portal_estudios/clear-opcache.php
 */

echo "<h1>Limpieza de OpCache</h1>";

if (function_exists('opcache_reset')) {
    if (opcache_reset()) {
        echo "<p style='color: green; font-size: 20px;'>✅ OpCache limpiado exitosamente</p>";
        echo "<p>Ahora puedes probar el botón 'Enviar a PACS' nuevamente.</p>";
    } else {
        echo "<p style='color: red;'>❌ No se pudo limpiar OpCache</p>";
        echo "<p>Debes reiniciar WAMP manualmente.</p>";
    }
} else {
    echo "<p style='color: orange;'>⚠️ OpCache no disponible o no se puede resetear desde script</p>";
    echo "<p>Debes reiniciar WAMP manualmente:</p>";
    echo "<ol>";
    echo "<li>Clic derecho en el icono verde de WAMP (bandeja del sistema)</li>";
    echo "<li>Seleccionar 'Reiniciar servicios'</li>";
    echo "<li>Esperar a que el icono se ponga verde nuevamente</li>";
    echo "</ol>";
}

echo "<hr>";
echo "<p><strong>Después de limpiar el caché:</strong></p>";
echo "<ol>";
echo "<li>Recargar la página de informes-manager (Ctrl+F5)</li>";
echo "<li>Probar el botón 'Enviar a PACS'</li>";
echo "</ol>";

echo "<hr>";
echo "<p><a href='components/informes-manager.html'>→ Ir a Gestión de Informes</a></p>";
?>

