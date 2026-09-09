<?php
/**
 * Test directo de la API de audios
 */

// Simular una llamada a la API
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['informe_id'] = '1';

// Capturar la salida
ob_start();
include 'api/audios/get.php';
$output = ob_get_clean();

echo "<h2>Resultado de la API de audios:</h2>";
echo "<pre>" . htmlspecialchars($output) . "</pre>";

// También probar con diferentes IDs
echo "<hr><h2>Probando con diferentes IDs:</h2>";

// Test con informe_id = 33 (basado en el archivo de audio encontrado)
$_GET['informe_id'] = '33';
ob_start();
include 'api/audios/get.php';
$output33 = ob_get_clean();

echo "<h3>Informe ID 33:</h3>";
echo "<pre>" . htmlspecialchars($output33) . "</pre>";

// Test sin parámetros
unset($_GET['informe_id']);
ob_start();
include 'api/audios/get.php';
$outputEmpty = ob_get_clean();

echo "<h3>Sin parámetros:</h3>";
echo "<pre>" . htmlspecialchars($outputEmpty) . "</pre>";
?>