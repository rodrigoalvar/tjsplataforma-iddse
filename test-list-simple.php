<?php
error_reporting(E_ALL);
ini_set("display_errors", 1);
echo "Iniciando test...\n";

try {
    require_once "api/informes/list-simple.php";
    echo "✅ list-simple.php ejecutado sin errores\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
} catch (Error $e) {
    echo "❌ Fatal Error: " . $e->getMessage() . "\n";
}
?>