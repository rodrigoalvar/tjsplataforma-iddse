<?php
/**
 * Debug del modal de audio
 */

require_once 'config/database.php';

try {
    $db = getDBConnection();
    
    echo "<h2>Debug del Modal de Audio</h2>";
    
    // 1. Verificar tabla audios_informe
    echo "<h3>1. Contenido de la tabla audios_informe:</h3>";
    $stmt = $db->query("SELECT * FROM audios_informe ORDER BY fecha_creacion DESC LIMIT 10");
    $audios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($audios)) {
        echo "<p style='color: red;'>❌ No hay audios en la tabla audios_informe</p>";
    } else {
        echo "<p style='color: green;'>✅ Encontrados " . count($audios) . " audios</p>";
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>Informe ID</th><th>Estudio ID</th><th>Nombre</th><th>Activo</th><th>Fecha</th></tr>";
        foreach ($audios as $audio) {
            echo "<tr>";
            echo "<td>" . $audio['id'] . "</td>";
            echo "<td>" . $audio['informe_id'] . "</td>";
            echo "<td>" . $audio['estudio_id'] . "</td>";
            echo "<td>" . $audio['nombre_archivo'] . "</td>";
            echo "<td>" . ($audio['activo'] ? 'Sí' : 'No') . "</td>";
            echo "<td>" . $audio['fecha_creacion'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // 2. Verificar tabla informes
    echo "<h3>2. Contenido de la tabla informes:</h3>";
    $stmt = $db->query("SELECT id, estudio_id, patient_id, titulo, version FROM informes ORDER BY id DESC LIMIT 10");
    $informes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($informes)) {
        echo "<p style='color: red;'>❌ No hay informes en la tabla informes</p>";
    } else {
        echo "<p style='color: green;'>✅ Encontrados " . count($informes) . " informes</p>";
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>Estudio ID</th><th>Patient ID</th><th>Título</th><th>Versión</th></tr>";
        foreach ($informes as $informe) {
            echo "<tr>";
            echo "<td>" . $informe['id'] . "</td>";
            echo "<td>" . $informe['estudio_id'] . "</td>";
            echo "<td>" . $informe['patient_id'] . "</td>";
            echo "<td>" . htmlspecialchars($informe['titulo']) . "</td>";
            echo "<td>" . $informe['version'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // 3. Probar consulta específica de la API
    echo "<h3>3. Simulando consulta de la API para informe ID 33:</h3>";
    
    // Primero obtener datos del informe
    $informeQuery = "SELECT estudio_id, patient_id FROM informes WHERE id = ?";
    $informeStmt = $db->prepare($informeQuery);
    $informeStmt->execute([33]);
    $informeData = $informeStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$informeData) {
        echo "<p style='color: red;'>❌ Informe ID 33 no encontrado</p>";
        
        // Buscar el informe correcto basado en el archivo de audio
        echo "<h4>Buscando informe correcto basado en el archivo de audio:</h4>";
        $audioQuery = "SELECT * FROM audios_informe WHERE nombre_archivo LIKE '%33%' OR nombre_archivo LIKE '%audio_33%'";
        $audioStmt = $db->query($audioQuery);
        $audioData = $audioStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($audioData)) {
            echo "<p style='color: blue;'>📁 Archivos de audio encontrados:</p>";
            foreach ($audioData as $audio) {
                echo "<p>- ID: {$audio['id']}, Informe: {$audio['informe_id']}, Estudio: {$audio['estudio_id']}, Archivo: {$audio['nombre_archivo']}</p>";
            }
        }
    } else {
        echo "<p style='color: green;'>✅ Informe 33 encontrado - Estudio: {$informeData['estudio_id']}, Patient: {$informeData['patient_id']}</p>";
        
        // Ahora buscar audios
        $query = "SELECT 
                    ai.id, ai.nombre_archivo, ai.ruta_archivo, ai.nombre_original, 
                    ai.duracion_segundos, ai.tamano_bytes, ai.tipo_grabacion, 
                    ai.fecha_creacion, ai.transcripcion_texto, ai.informe_id,
                    i.version, i.titulo as informe_titulo
                  FROM audios_informe ai
                  LEFT JOIN informes i ON ai.informe_id = i.id
                  WHERE ai.estudio_id = ? AND ai.activo = 1
                  ORDER BY ai.fecha_creacion ASC";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$informeData['estudio_id']]);
        $audios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($audios)) {
            echo "<p style='color: red;'>❌ No se encontraron audios para el estudio {$informeData['estudio_id']}</p>";
        } else {
            echo "<p style='color: green;'>✅ Encontrados " . count($audios) . " audios para el estudio</p>";
            foreach ($audios as $audio) {
                echo "<p>- {$audio['nombre_archivo']} (ID: {$audio['id']})</p>";
            }
        }
    }
    
    // 4. Verificar archivos físicos
    echo "<h3>4. Archivos físicos en uploads/audios:</h3>";
    $audioDir = 'uploads/audios/';
    if (is_dir($audioDir)) {
        $files = scandir($audioDir);
        $audioFiles = array_filter($files, function($file) {
            return !in_array($file, ['.', '..']) && is_file('uploads/audios/' . $file);
        });
        
        if (empty($audioFiles)) {
            echo "<p style='color: red;'>❌ No hay archivos de audio físicos</p>";
        } else {
            echo "<p style='color: green;'>✅ Encontrados " . count($audioFiles) . " archivos físicos:</p>";
            foreach ($audioFiles as $file) {
                echo "<p>- $file</p>";
            }
        }
    } else {
        echo "<p style='color: red;'>❌ Directorio uploads/audios no existe</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>