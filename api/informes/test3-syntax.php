<?php
echo "Test 3: Verificar sintaxis PHP\n";

$files = [
    "../../config/database.php",
    "../../classes/User.php", 
    "../../middleware/auth.php"
];

foreach ($files as $file) {
    $fullPath = __DIR__ . "/" . $file;
    echo "Verificando sintaxis: " . $file . "\n";
    
    $output = shell_exec("php -l " . escapeshellarg($fullPath) . " 2>&1");
    echo "Resultado: " . trim($output) . "\n\n";
}
?>