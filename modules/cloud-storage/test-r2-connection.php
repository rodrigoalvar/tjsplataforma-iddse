<?php
require_once 'config/cloud_storage_config.php';
require_once 'drivers/R2StorageDriver.php';

echo "=== Test de Conexión R2 ===\n\n";

$config = CloudStorageConfig::load();

echo "Configuración:\n";
echo "  R2_ENABLED: " . ($config['r2_enabled'] ? 'YES' : 'NO') . "\n";
echo "  Bucket: " . $config['r2_bucket_name'] . "\n";
echo "  Custom Domain: " . (empty($config['r2_custom_domain']) ? 'NO (usará endpoint interno)' : $config['r2_custom_domain']) . "\n\n";

try {
    $driver = new R2StorageDriver($config);
    echo "Probando conexión a R2...\n";
    
    if ($driver->testConnection()) {
        echo "✓ Conexión exitosa!\n";
        echo "✓ El módulo está listo para usar.\n";
    } else {
        echo "✗ Error de conexión\n";
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "\nPosibles causas:\n";
    echo "  - Credenciales incorrectas\n";
    echo "  - Bucket no existe\n";
    echo "  - Permisos del API Token insuficientes\n";
}
