<?php
/**
 * Test directo de la API de audios
 */

require_once 'config/database.php';

try {
    $db = getDBConnection();
    
    echo "<h2>Test directo de la API de audios</h2>";
    
    // 1. Verificar datos en la base de datos
    echo "<h3>1. Datos en la base de datos:</h3>";
    
    $audios = $db->query("
        SELECT ai.*, i.titulo, i.patient_name, i.estudio_id as informe_estudio_id, i.patient_id as informe_patient_id
        FROM audios_informe ai 
        LEFT JOIN informes i ON ai.informe_id = i.id 
        WHERE ai.activo = 1
        ORDER BY ai.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($audios)) {
        echo "<p style='color: red;'>❌ No hay audios en la base de datos</p>";
    } else {
        echo "<p style='color: green;'>✅ Encontrados " . count($audios) . " audios:</p>";
        echo "<table border='1' style='border-collapse: collapse; width: 100%; font-size: 12px;'>";
        echo "<tr><th>ID</th><th>Informe ID</th><th>Estudio ID</th><th>Patient ID</th><th>Archivo</th><th>Título</th></tr>";
        foreach ($audios as $audio) {
            echo "<tr>";
            echo "<td>" . $audio['id'] . "</td>";
            echo "<td>" . $audio['informe_id'] . "</td>";
            echo "<td>" . htmlspecialchars($audio['informe_estudio_id']) . "</td>";
            echo "<td>" . htmlspecialchars($audio['informe_patient_id']) . "</td>";
            echo "<td>" . $audio['nombre_archivo'] . "</td>";
            echo "<td>" . htmlspecialchars($audio['titulo']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // 2. Simular llamada a la API
    echo "<h3>2. Simulando llamada a la API:</h3>";
    
    if (!empty($audios)) {
        $firstAudio = $audios[0];
        $informeId = $firstAudio['informe_id'];
        $estudioId = $firstAudio['informe_estudio_id'];
        $patientId = $firstAudio['informe_patient_id'];
        
        echo "<p>Probando con: informeId=$informeId, estudioId=$estudioId, patientId=$patientId</p>";
        
        // Simular la lógica de la API
        $stmt = $db->prepare("
            SELECT ai.*, i.titulo as informe_titulo, i.patient_name
            FROM audios_informe ai
            LEFT JOIN informes i ON ai.informe_id = i.id
            WHERE ai.activo = 1 
            AND (ai.informe_id = ? OR i.estudio_id = ? OR i.patient_id = ?)
            ORDER BY ai.fecha_creacion DESC
        ");
        
        $stmt->execute([$informeId, $estudioId, $patientId]);
        $apiResult = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($apiResult)) {
            echo "<p style='color: red;'>❌ La API no devolvería resultados</p>";
        } else {
            echo "<p style='color: green;'>✅ La API devolvería " . count($apiResult) . " resultados:</p>";
            
            $formattedResults = [];
            foreach ($apiResult as $audio) {
                $formattedResults[] = [
                    'id' => $audio['id'],
                    'informe_id' => $audio['informe_id'],
                    'nombre_archivo' => $audio['nombre_archivo'],
                    'ruta_archivo' => $audio['ruta_archivo'],
                    'duracion_segundos' => $audio['duracion_segundos'],
                    'transcripcion' => $audio['transcripcion_texto'],
                    'url_completa' => 'uploads/audios/' . $audio['nombre_archivo']
                ];
            }
            
            echo "<pre>" . json_encode([
                'success' => true,
                'message' => 'Audios encontrados',
                'data' => $formattedResults,
                'total' => count($formattedResults)
            ], JSON_PRETTY_PRINT) . "</pre>";
        }
    }
    
    // 3. Test de archivos físicos
    echo "<h3>3. Verificación de archivos físicos:</h3>";
    
    $audioDir = 'uploads/audios/';
    if (is_dir($audioDir)) {
        $files = scandir($audioDir);
        $audioFiles = array_filter($files, function($file) use ($audioDir) {
            return !in_array($file, ['.', '..']) && is_file($audioDir . $file);
        });
        
        if (empty($audioFiles)) {
            echo "<p style='color: red;'>❌ No hay archivos físicos en $audioDir</p>";
        } else {
            echo "<p style='color: green;'>✅ Archivos físicos encontrados:</p>";
            echo "<ul>";
            foreach ($audioFiles as $file) {
                $fullPath = $audioDir . $file;
                $size = filesize($fullPath);
                echo "<li>$file (" . number_format($size) . " bytes)</li>";
            }
            echo "</ul>";
        }
    } else {
        echo "<p style='color: red;'>❌ Directorio $audioDir no existe</p>";
    }
    
    // 4. Test de URL completa
    echo "<h3>4. Test de URLs de audio:</h3>";
    
    if (!empty($audios)) {
        foreach ($audios as $audio) {
            $url = 'uploads/audios/' . $audio['nombre_archivo'];
            $fullPath = $audio['nombre_archivo'] ? 'uploads/audios/' . $audio['nombre_archivo'] : '';
            
            if ($fullPath && file_exists($fullPath)) {
                echo "<p style='color: green;'>✅ $url - Archivo existe</p>";
            } else {
                echo "<p style='color: red;'>❌ $url - Archivo NO existe</p>";
            }
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>