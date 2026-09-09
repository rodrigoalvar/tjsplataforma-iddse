<?php
/**
 * Script de diagnóstico para install.php
 * Usar para identificar problemas de rutas o permisos
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Diagnóstico - Cloud Storage Install</h1>";

echo "<h2>1. Información del sistema</h2>";
echo "<pre>";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Script path: " . __FILE__ . "\n";
echo "Script dir: " . __DIR__ . "\n";
echo "Working dir: " . getcwd() . "\n";
echo "</pre>";

echo "<h2>2. Verificando rutas</h2>";
echo "<pre>";

$paths = [
    'database.php' => __DIR__ . '/../../config/database.php',
    'cloud_storage_config.php' => __DIR__ . '/config/cloud_storage_config.php',
    'install.sql' => __DIR__ . '/database/install.sql',
    '.env (módulo)' => __DIR__ . '/.env',
    '.env (raíz proyecto)' => __DIR__ . '/../../.env',
    '.env.example' => __DIR__ . '/.env.example'
];

foreach ($paths as $name => $path) {
    $exists = file_exists($path);
    $readable = $exists ? is_readable($path) : false;
    echo "$name:\n";
    echo "  Path: $path\n";
    echo "  Exists: " . ($exists ? 'YES' : 'NO') . "\n";
    echo "  Readable: " . ($readable ? 'YES' : 'NO') . "\n";
    if ($exists) {
        echo "  Size: " . filesize($path) . " bytes\n";
        echo "  Permissions: " . substr(sprintf('%o', fileperms($path)), -4) . "\n";
    }
    echo "\n";
}

echo "</pre>";

echo "<h2>3. Verificando includes</h2>";
echo "<pre>";

try {
    $dbPath = __DIR__ . '/../../config/database.php';
    if (file_exists($dbPath)) {
        require_once $dbPath;
        echo "✓ database.php incluido\n";
        if (class_exists('Database')) {
            echo "✓ Clase Database disponible\n";
            try {
                $db = new Database();
                echo "✓ Database instanciado\n";
            } catch (Exception $e) {
                echo "✗ Error instanciando Database: " . $e->getMessage() . "\n";
            }
        } else {
            echo "✗ Clase Database NO disponible\n";
        }
    } else {
        echo "✗ database.php no encontrado\n";
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

try {
    $configPath = __DIR__ . '/config/cloud_storage_config.php';
    if (file_exists($configPath)) {
        require_once $configPath;
        echo "✓ cloud_storage_config.php incluido\n";
        if (class_exists('CloudStorageConfig')) {
            echo "✓ Clase CloudStorageConfig disponible\n";
        } else {
            echo "✗ Clase CloudStorageConfig NO disponible\n";
        }
    } else {
        echo "✗ cloud_storage_config.php no encontrado\n";
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "</pre>";

echo "<h2>4. Permisos de archivos</h2>";
echo "<pre>";
$files = [
    __FILE__,
    __DIR__ . '/install.php',
    __DIR__ . '/../../config/database.php'
];

foreach ($files as $file) {
    if (file_exists($file)) {
        echo basename($file) . ": " . substr(sprintf('%o', fileperms($file)), -4) . "\n";
    }
}
echo "</pre>";

echo "<h2>5. Intentar ejecutar install.php</h2>";
echo "<pre>";
ob_start();
try {
    include __DIR__ . '/install.php';
    $output = ob_get_clean();
    echo "✓ install.php ejecutado sin errores fatales\n";
    echo "Output length: " . strlen($output) . " bytes\n";
} catch (Exception $e) {
    $output = ob_get_clean();
    echo "✗ Error ejecutando install.php: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
} catch (Error $e) {
    $output = ob_get_clean();
    echo "✗ PHP Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
echo "</pre>";

?>
