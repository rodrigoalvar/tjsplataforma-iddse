<?php
echo "Test 2: Verificar archivos\n";

$files = [
    "../../config/database.php",
    "../../classes/User.php", 
    "../../middleware/auth.php"
];

foreach ($files as $file) {
    $fullPath = __DIR__ . "/" . $file;
    echo "Archivo: " . $file . "\n";
    echo "Ruta completa: " . $fullPath . "\n";
    echo "Existe: " . (file_exists($fullPath) ? "SÍ" : "NO") . "\n";
    echo "Legible: " . (is_readable($fullPath) ? "SÍ" : "NO") . "\n\n";
}
?>