<?php
/**
 * Script de prueba con un estudio real
 * Guía paso a paso para probar el módulo
 */

header('Content-Type: text/html; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Prueba con Estudio Real - Cloud Storage</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .step { margin: 20px 0; padding: 15px; border-left: 4px solid #007bff; background: #f8f9fa; }
        .step h3 { margin-top: 0; color: #007bff; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .info { color: blue; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 5px; overflow-x: auto; border: 1px solid #ddd; }
        code { background: #f5f5f5; padding: 2px 6px; border-radius: 3px; }
        .command { background: #2d2d2d; color: #f8f8f2; padding: 10px; border-radius: 5px; }
        .btn { display: inline-block; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; margin: 5px; }
        .btn:hover { background: #0056b3; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🧪 Prueba con Estudio Real - Cloud Storage R2</h1>
        
<?php

require_once __DIR__ . '/config/cloud_storage_config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../api/OrthancClient.php';

$config = CloudStorageConfig::load();
$database = new Database();
$db = $database->getConnection();
$orthancClient = new OrthancClient();

// Paso 1: Obtener estudios disponibles
echo "<div class='step'>";
echo "<h3>Paso 1: Obtener estudios disponibles de Orthanc</h3>";

try {
    $studies = $orthancClient->getAllStudiesEfficient(null, null, null, null, false);
    
    if (empty($studies)) {
        echo "<div class='error'>No hay estudios disponibles en Orthanc.</div>";
        echo "<p>Necesitas tener al menos un estudio en Orthanc para probar.</p>";
    } else {
        echo "<div class='success'>✓ Encontrados " . count($studies) . " estudios</div>";
        echo "<p>Estudios disponibles (primeros 10):</p>";
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Study ID</th><th>Paciente</th><th>Fecha</th><th>Descripción</th><th>Acción</th></tr>";
        
        $count = 0;
        foreach ($studies as $study) {
            if ($count >= 10) break;
            $studyId = $study['orthanc_id'] ?? $study['study_id'] ?? '';
            $patientName = $study['patient_name'] ?? 'N/A';
            $studyDate = $study['study_date'] ?? 'N/A';
            $description = $study['study_description'] ?? 'N/A';
            
            echo "<tr>";
            echo "<td><code>" . htmlspecialchars(substr($studyId, 0, 20)) . "...</code></td>";
            echo "<td>" . htmlspecialchars($patientName) . "</td>";
            echo "<td>" . htmlspecialchars($studyDate) . "</td>";
            echo "<td>" . htmlspecialchars(substr($description, 0, 30)) . "...</td>";
            echo "<td><a href='?action=enqueue&study_id=" . urlencode($studyId) . "' class='btn'>Encolar</a></td>";
            echo "</tr>";
            $count++;
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<div class='error'>Error obteniendo estudios: " . htmlspecialchars($e->getMessage()) . "</div>";
}
echo "</div>";

// Paso 2: Encolar estudio (si se seleccionó)
if (isset($_GET['action']) && $_GET['action'] === 'enqueue' && isset($_GET['study_id'])) {
    $studyId = $_GET['study_id'];
    
    echo "<div class='step'>";
    echo "<h3>Paso 2: Encolar estudio</h3>";
    echo "<p>Study ID: <code>" . htmlspecialchars($studyId) . "</code></p>";
    
    try {
        require_once __DIR__ . '/CloudStorageManager.php';
        $manager = new CloudStorageManager();
        $result = $manager->enqueueStudy($studyId);
        
        if ($result['success']) {
            echo "<div class='success'>✓ Estudio encolado correctamente</div>";
            echo "<p>Queue ID: <code>" . $result['queue_id'] . "</code></p>";
            echo "<p><strong>Próximo paso:</strong> Ejecutar el worker para subir a R2</p>";
            echo "<div class='command'>";
            echo "php " . __DIR__ . "/workers/r2-upload-worker.php";
            echo "</div>";
            echo "<p>O ejecuta manualmente:</p>";
            echo "<pre>cd /var/www/tjsiddse/modules/cloud-storage\nphp workers/r2-upload-worker.php</pre>";
        } else {
            echo "<div class='error'>Error: " . htmlspecialchars($result['message'] ?? 'Error desconocido') . "</div>";
        }
    } catch (Exception $e) {
        echo "<div class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    echo "</div>";
}

// Paso 3: Ver estado de la cola
echo "<div class='step'>";
echo "<h3>Paso 3: Estado de la cola</h3>";

try {
    $stmt = $db->query("
        SELECT 
            id,
            orthanc_study_id,
            status,
            retry_count,
            last_error,
            created_at,
            updated_at
        FROM r2_queue 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $queueItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($queueItems)) {
        echo "<div class='info'>No hay estudios en la cola.</div>";
    } else {
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>Study ID</th><th>Estado</th><th>Reintentos</th><th>Error</th><th>Creado</th></tr>";
        foreach ($queueItems as $item) {
            $statusColor = [
                'pending' => 'orange',
                'uploading' => 'blue',
                'done' => 'green',
                'error' => 'red'
            ];
            $color = $statusColor[$item['status']] ?? 'black';
            echo "<tr>";
            echo "<td>" . $item['id'] . "</td>";
            echo "<td><code>" . htmlspecialchars(substr($item['orthanc_study_id'], 0, 20)) . "...</code></td>";
            echo "<td style='color: $color;'><strong>" . htmlspecialchars($item['status']) . "</strong></td>";
            echo "<td>" . $item['retry_count'] . "</td>";
            echo "<td>" . htmlspecialchars(substr($item['last_error'] ?? '', 0, 50)) . "</td>";
            echo "<td>" . $item['created_at'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<div class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}
echo "</div>";

// Paso 4: Ver estudios en R2
echo "<div class='step'>";
echo "<h3>Paso 4: Estudios en R2</h3>";

try {
    $stmt = $db->query("
        SELECT 
            orthanc_study_id,
            study_instance_uid,
            r2_status,
            total_instances,
            ROUND(total_size_bytes / 1024 / 1024, 2) as size_mb,
            uploaded_at
        FROM r2_studies 
        WHERE r2_status = 'online'
        ORDER BY uploaded_at DESC
        LIMIT 10
    ");
    $r2Studies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($r2Studies)) {
        echo "<div class='info'>No hay estudios en R2 aún. Ejecuta el worker después de encolar un estudio.</div>";
    } else {
        echo "<div class='success'>✓ " . count($r2Studies) . " estudios en R2</div>";
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Study ID</th><th>StudyInstanceUID</th><th>Instancias</th><th>Tamaño (MB)</th><th>Subido</th><th>Acción</th></tr>";
        foreach ($r2Studies as $study) {
            echo "<tr>";
            echo "<td><code>" . htmlspecialchars(substr($study['orthanc_study_id'], 0, 20)) . "...</code></td>";
            echo "<td><code>" . htmlspecialchars(substr($study['study_instance_uid'], 0, 30)) . "...</code></td>";
            echo "<td>" . $study['total_instances'] . "</td>";
            echo "<td>" . $study['size_mb'] . " MB</td>";
            echo "<td>" . $study['uploaded_at'] . "</td>";
            echo "<td><a href='?action=view_manifest&study_id=" . urlencode($study['orthanc_study_id']) . "' class='btn'>Ver Manifest</a></td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<div class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}
echo "</div>";

// Ver manifest si se solicitó
if (isset($_GET['action']) && $_GET['action'] === 'view_manifest' && isset($_GET['study_id'])) {
    $studyId = $_GET['study_id'];
    
    echo "<div class='step'>";
    echo "<h3>Manifest del estudio</h3>";
    echo "<p>Study ID: <code>" . htmlspecialchars($studyId) . "</code></p>";
    
    try {
        $manifestUrl = "https://plataforma.iddse.com.ar/api/cloud-storage/manifest/" . urlencode($studyId);
        echo "<p>URL: <code>" . htmlspecialchars($manifestUrl) . "</code></p>";
        
        $ch = curl_init($manifestUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $manifest = json_decode($response, true);
            echo "<div class='success'>✓ Manifest obtenido correctamente</div>";
            echo "<pre>" . htmlspecialchars(json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "</pre>";
        } else {
            echo "<div class='error'>Error HTTP $httpCode</div>";
            echo "<pre>" . htmlspecialchars($response) . "</pre>";
        }
    } catch (Exception $e) {
        echo "<div class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    echo "</div>";
}

?>

        <div class="step">
            <h3>📝 Comandos útiles</h3>
            <p><strong>Ejecutar worker manualmente:</strong></p>
            <div class="command">
cd /var/www/tjsiddse/modules/cloud-storage<br>
php workers/r2-upload-worker.php
            </div>
            
            <p><strong>Ver logs del worker (si usas cron):</strong></p>
            <div class="command">
tail -f /var/log/r2-worker.log
            </div>
            
            <p><strong>Ver estado de un estudio:</strong></p>
            <div class="command">
curl https://plataforma.iddse.com.ar/api/cloud-storage/status/TU_STUDY_ID
            </div>
        </div>
    </div>
</body>
</html>
