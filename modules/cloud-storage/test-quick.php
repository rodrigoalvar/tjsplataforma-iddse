<?php
/**
 * Script de prueba rápida del módulo Cloud Storage
 * Verifica que todo esté funcionando correctamente
 */

header('Content-Type: text/html; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Prueba Rápida - Cloud Storage</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: green; }
        .error { color: red; }
        .info { color: blue; }
        .warning { color: orange; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 5px; overflow-x: auto; }
        .test-section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
    </style>
</head>
<body>
    <h1>🧪 Prueba Rápida - Cloud Storage R2</h1>
    
<?php

$results = [];

// Test 1: Configuración
echo "<div class='test-section'>";
echo "<h2>1. Verificar Configuración</h2>";
try {
    require_once __DIR__ . '/config/cloud_storage_config.php';
    $config = CloudStorageConfig::load();
    
    if ($config['r2_enabled'] ?? false) {
        echo "<div class='success'>✓ R2 habilitado</div>";
        $results['config'] = true;
    } else {
        echo "<div class='error'>✗ R2 no está habilitado</div>";
        $results['config'] = false;
    }
    
    $required = ['r2_account_id', 'r2_access_key', 'r2_secret_key', 'r2_bucket_name'];
    $allConfigured = true;
    foreach ($required as $key) {
        if (empty($config[$key])) {
            echo "<div class='error'>✗ $key no configurado</div>";
            $allConfigured = false;
        } else {
            echo "<div class='success'>✓ $key configurado</div>";
        }
    }
    $results['credentials'] = $allConfigured;
    
} catch (Exception $e) {
    echo "<div class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    $results['config'] = false;
}
echo "</div>";

// Test 2: Conexión R2
echo "<div class='test-section'>";
echo "<h2>2. Verificar Conexión R2</h2>";
try {
    require_once __DIR__ . '/drivers/R2StorageDriver.php';
    $driver = new R2StorageDriver($config);
    
    if ($driver->testConnection()) {
        echo "<div class='success'>✓ Conexión a R2 exitosa</div>";
        $results['r2_connection'] = true;
    } else {
        echo "<div class='error'>✗ No se pudo conectar a R2</div>";
        $results['r2_connection'] = false;
    }
} catch (Exception $e) {
    echo "<div class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    $results['r2_connection'] = false;
}
echo "</div>";

// Test 3: Base de datos
echo "<div class='test-section'>";
echo "<h2>3. Verificar Base de Datos</h2>";
try {
    require_once __DIR__ . '/../../config/database.php';
    $database = new Database();
    $db = $database->getConnection();
    
    if ($db) {
        echo "<div class='success'>✓ Conexión a BD exitosa</div>";
        
        // Verificar tablas
        $tables = ['r2_queue', 'r2_studies', 'cloud_storage_config'];
        foreach ($tables as $table) {
            $stmt = $db->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() > 0) {
                echo "<div class='success'>✓ Tabla $table existe</div>";
            } else {
                echo "<div class='error'>✗ Tabla $table no existe</div>";
            }
        }
        
        // Contar registros
        $stmt = $db->query("SELECT COUNT(*) as total FROM r2_queue");
        $queueCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        echo "<div class='info'>📊 Estudios en cola: $queueCount</div>";
        
        $stmt = $db->query("SELECT COUNT(*) as total FROM r2_studies WHERE r2_status = 'online'");
        $onlineCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        echo "<div class='info'>📊 Estudios en R2: $onlineCount</div>";
        
        $results['database'] = true;
    } else {
        echo "<div class='error'>✗ No se pudo conectar a BD</div>";
        $results['database'] = false;
    }
} catch (Exception $e) {
    echo "<div class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    $results['database'] = false;
}
echo "</div>";

// Test 4: Endpoints API
echo "<div class='test-section'>";
echo "<h2>4. Verificar Endpoints API</h2>";
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
$apiBase = dirname(dirname($baseUrl . $_SERVER['REQUEST_URI'])) . '/api/cloud-storage';

echo "<div class='info'>Base URL: <code>$apiBase</code></div>";
echo "<ul>";
echo "<li><code>POST $apiBase/enqueue</code> - Encolar estudio</li>";
echo "<li><code>GET $apiBase/manifest/{id}</code> - Obtener manifest</li>";
echo "<li><code>GET $apiBase/status/{id}</code> - Estado del estudio</li>";
echo "</ul>";
echo "</div>";

// Resumen
echo "<div class='test-section'>";
echo "<h2>📊 Resumen</h2>";

$allOk = true;
foreach ($results as $test => $result) {
    if (!$result) {
        $allOk = false;
        break;
    }
}

if ($allOk) {
    echo "<div class='success'><strong>✅ Todos los tests pasaron. El módulo está listo para usar.</strong></div>";
} else {
    echo "<div class='warning'><strong>⚠️ Algunos tests fallaron. Revisa los errores arriba.</strong></div>";
}

echo "</div>";

// Próximos pasos
echo "<div class='test-section'>";
echo "<h2>🚀 Próximos Pasos</h2>";
echo "<ol>";
echo "<li><strong>Encolar un estudio:</strong><br>";
echo "<pre>curl -X POST $apiBase/enqueue -H 'Content-Type: application/json' -d '{\"orthanc_study_id\": \"TU_STUDY_ID\"}'</pre></li>";
echo "<li><strong>Ejecutar worker:</strong><br>";
echo "<pre>php " . __DIR__ . "/workers/r2-upload-worker.php</pre></li>";
echo "<li><strong>Obtener manifest:</strong><br>";
echo "<pre>curl $apiBase/manifest/TU_STUDY_ID</pre></li>";
echo "</ol>";
echo "</div>";

?>

</body>
</html>
