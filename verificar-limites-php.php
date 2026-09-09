<?php
/**
 * Script para verificar los límites de PHP configurados
 * Útil para verificar que los cambios en php.ini se aplicaron correctamente
 */

echo "=== Verificación de Límites de PHP ===\n\n";

echo "Versión de PHP: " . PHP_VERSION . "\n";
echo "SAPI: " . php_sapi_name() . "\n\n";

echo "Límites de subida de archivos:\n";
echo "  upload_max_filesize: " . ini_get('upload_max_filesize') . "\n";
echo "  post_max_size: " . ini_get('post_max_size') . "\n";
echo "  memory_limit: " . ini_get('memory_limit') . "\n";
echo "  max_execution_time: " . ini_get('max_execution_time') . " segundos\n";
echo "  max_input_time: " . ini_get('max_input_time') . " segundos\n\n";

// Convertir a bytes para comparación
function parseSize($size) {
    $unit = preg_replace('/[^bkmgtpezy]/i', '', $size);
    $size = preg_replace('/[^0-9\.]/', '', $size);
    if ($unit) {
        return round($size * pow(1024, stripos('bkmgtpezy', $unit[0])));
    } else {
        return round($size);
    }
}

$uploadMax = parseSize(ini_get('upload_max_filesize'));
$postMax = parseSize(ini_get('post_max_size'));

echo "Verificación:\n";
if ($uploadMax >= 500 * 1024 * 1024) {
    echo "  ✓ upload_max_filesize está configurado correctamente (>= 500MB)\n";
} else {
    echo "  ✗ upload_max_filesize es demasiado pequeño: " . ini_get('upload_max_filesize') . "\n";
}

if ($postMax >= 500 * 1024 * 1024) {
    echo "  ✓ post_max_size está configurado correctamente (>= 500MB)\n";
} else {
    echo "  ✗ post_max_size es demasiado pequeño: " . ini_get('post_max_size') . "\n";
}

echo "\n=== Fin de la verificación ===\n";
