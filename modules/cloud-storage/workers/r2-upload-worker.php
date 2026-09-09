<?php
/**
 * Worker para procesar la cola de estudios pendientes de subir a R2
 * 
 * Ejecutar por cron cada minuto:
 *   *\/1 * * * * php /var/www/tjsiddse/modules/cloud-storage/workers/r2-upload-worker.php >> /var/www/tjsiddse/modules/cloud-storage/logs/r2-worker.log 2>&1
 */

// Cambiar al directorio del módulo
$moduleDir = dirname(__DIR__);
$projectRoot = dirname(dirname($moduleDir)); // /var/www/tjsiddse
chdir($moduleDir);

// Cargar dependencias (usar rutas absolutas para evitar problemas con chdir)
require_once $moduleDir . '/config/cloud_storage_config.php';
require_once $projectRoot . '/config/database.php';
require_once $moduleDir . '/drivers/R2StorageDriver.php';
require_once $moduleDir . '/ManifestBuilder.php';
require_once $moduleDir . '/R2QueueProcessor.php';
require_once $projectRoot . '/api/OrthancClient.php';

try {
    // Verificar si hay señal de detención
    $stopFile = __DIR__ . '/.worker_stop';
    if (file_exists($stopFile)) {
        echo "[" . date('Y-m-d H:i:s') . "] Señal de detención detectada. Saliendo.\n";
        // Eliminar archivo de señal
        @unlink($stopFile);
        exit(0);
    }
    
    echo "[" . date('Y-m-d H:i:s') . "] Iniciando worker R2...\n";
    
    // Cargar configuración
    $config = CloudStorageConfig::load();
    
    if (!($config['r2_enabled'] ?? false)) {
        echo "R2 no está habilitado. Saliendo.\n";
        exit(0);
    }
    
    // Conectar a BD
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception('No se pudo conectar a la base de datos');
    }
    
    // Inicializar componentes
    $r2Driver = new R2StorageDriver($config);
    $orthancClient = new OrthancClient();
    $manifestBuilder = new ManifestBuilder($orthancClient, $r2Driver);
    $processor = new R2QueueProcessor($db, $r2Driver, $manifestBuilder, $orthancClient);
    
    // Procesar cola
    echo "Procesando cola...\n";
    $result = $processor->processQueue();
    
    $total = $result['total'] ?? 0;
    echo "Procesados: " . $result['processed'] . " de " . $total . "\n";
    
    if (!empty($result['errors'])) {
        echo "Errores:\n";
        foreach ($result['errors'] as $error) {
            echo "  - Estudio {$error['study_id']}: {$error['error']}\n";
        }
    }
    
    echo "[" . date('Y-m-d H:i:s') . "] Worker finalizado.\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
    error_log('[R2_WORKER] Error: ' . $e->getMessage());
    exit(1);
}
