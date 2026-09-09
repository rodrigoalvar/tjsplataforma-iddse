<?php
/**
 * Diagnóstico final del modal de audio
 */

require_once 'config/database.php';

try {
    $db = getDBConnection();
    
    echo "<h2>🔍 Diagnóstico Final del Modal de Audio</h2>";
    
    // 1. Verificar estado de la base de datos después de la corrección
    echo "<h3>1. Estado actual de la base de datos:</h3>";
    
    $informes = $db->query("SELECT id, titulo, patient_name FROM informes ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $audios = $db->query("SELECT * FROM audios_informe WHERE activo = 1")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p><strong>Informes:</strong> " . count($informes) . "</p>";
    if (!empty($informes)) {
        echo "<ul>";
        foreach ($informes as $informe) {
            echo "<li>ID: {$informe['id']} - {$informe['titulo']} (Paciente: {$informe['patient_name']})</li>";
        }
        echo "</ul>";
    }
    
    echo "<p><strong>Audios:</strong> " . count($audios) . "</p>";
    if (!empty($audios)) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%; font-size: 12px;'>";
        echo "<tr><th>ID</th><th>Informe ID</th><th>Archivo</th><th>Duración</th><th>Fecha</th></tr>";
        foreach ($audios as $audio) {
            echo "<tr>";
            echo "<td>" . $audio['id'] . "</td>";
            echo "<td>" . $audio['informe_id'] . "</td>";
            echo "<td>" . $audio['nombre_archivo'] . "</td>";
            echo "<td>" . $audio['duracion_segundos'] . "s</td>";
            echo "<td>" . $audio['fecha_creacion'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // 2. Simular exactamente lo que hace el modal JavaScript
    echo "<h3>2. Simulando llamada del modal JavaScript:</h3>";
    
    if (!empty($informes)) {
        $informeId = $informes[0]['id'];
        echo "<p>Probando con informe ID: <strong>$informeId</strong></p>";
        
        // Simular la llamada AJAX exacta del modal
        $stmt = $db->prepare("
            SELECT ai.*, 
                   CONCAT('uploads/audios/', ai.nombre_archivo) as url_completa
            FROM audios_informe ai 
            WHERE ai.informe_id = ? AND ai.activo = 1 
            ORDER BY ai.fecha_creacion DESC
        ");
        
        $stmt->execute([$informeId]);
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<p><strong>Resultados de la consulta:</strong> " . count($resultados) . "</p>";
        
        if (empty($resultados)) {
            echo "<p style='color: red;'>❌ No se encontraron audios para el informe ID $informeId</p>";
            
            // Verificar si hay audios para otros informes
            $todosLosAudios = $db->query("SELECT DISTINCT informe_id FROM audios_informe WHERE activo = 1")->fetchAll(PDO::FETCH_ASSOC);
            echo "<p>Audios disponibles para informes: ";
            foreach ($todosLosAudios as $audio) {
                echo $audio['informe_id'] . " ";
            }
            echo "</p>";
            
        } else {
            echo "<p style='color: green;'>✅ Se encontraron audios</p>";
            
            // Formatear como lo hace la API
            $audioData = [];
            foreach ($resultados as $audio) {
                $audioData[] = [
                    'id' => $audio['id'],
                    'informe_id' => $audio['informe_id'],
                    'nombre_archivo' => $audio['nombre_archivo'],
                    'ruta_archivo' => $audio['ruta_archivo'],
                    'duracion_segundos' => $audio['duracion_segundos'],
                    'transcripcion' => $audio['transcripcion_texto'],
                    'url_completa' => $audio['url_completa']
                ];
            }
            
            $response = [
                'success' => true,
                'message' => 'Audios encontrados',
                'data' => $audioData,
                'total' => count($audioData)
            ];
            
            echo "<pre style='background: #f8f9fa; padding: 10px; border-radius: 5px; font-size: 11px;'>";
            echo json_encode($response, JSON_PRETTY_PRINT);
            echo "</pre>";
        }
    }
    
    // 3. Probar la API directamente
    echo "<h3>3. Probando API directamente:</h3>";
    
    if (!empty($informes)) {
        $informeId = $informes[0]['id'];
        
        // Simular parámetros que envía el modal
        $_GET['informeId'] = $informeId;
        $_GET['estudioId'] = 'test_estudio';
        $_GET['patientId'] = 'test_patient';
        
        // Capturar la salida de la API
        ob_start();
        include 'api/audios/get.php';
        $apiOutput = ob_get_clean();
        
        echo "<p><strong>Respuesta de la API:</strong></p>";
        echo "<pre style='background: #f8f9fa; padding: 10px; border-radius: 5px; font-size: 11px;'>";
        echo htmlspecialchars($apiOutput);
        echo "</pre>";
        
        // Intentar decodificar como JSON
        $jsonResponse = json_decode($apiOutput, true);
        if ($jsonResponse) {
            echo "<p><strong>JSON decodificado:</strong></p>";
            if ($jsonResponse['success'] && !empty($jsonResponse['data'])) {
                echo "<p style='color: green;'>✅ La API devuelve datos correctamente</p>";
                echo "<p>Total de audios: " . $jsonResponse['total'] . "</p>";
            } else {
                echo "<p style='color: red;'>❌ La API no devuelve datos</p>";
                echo "<p>Mensaje: " . $jsonResponse['message'] . "</p>";
            }
        } else {
            echo "<p style='color: red;'>❌ La respuesta de la API no es JSON válido</p>";
        }
    }
    
    // 4. Verificar archivos físicos
    echo "<h3>4. Verificando archivos físicos:</h3>";
    
    $audioDir = 'uploads/audios/';
    if (is_dir($audioDir)) {
        $files = scandir($audioDir);
        $audioFiles = array_filter($files, function($file) {
            return !in_array($file, ['.', '..']) && pathinfo($file, PATHINFO_EXTENSION) === 'wav';
        });
        
        echo "<p><strong>Archivos de audio encontrados:</strong> " . count($audioFiles) . "</p>";
        if (!empty($audioFiles)) {
            echo "<ul>";
            foreach ($audioFiles as $file) {
                $fullPath = $audioDir . $file;
                $size = filesize($fullPath);
                echo "<li>$file (Tamaño: " . number_format($size / 1024, 2) . " KB)</li>";
            }
            echo "</ul>";
        }
    } else {
        echo "<p style='color: red;'>❌ Directorio de audios no existe</p>";
    }
    
    // 5. Diagnóstico final
    echo "<h3>5. 🎯 Diagnóstico Final:</h3>";
    
    if (empty($informes)) {
        echo "<p style='color: red;'>❌ <strong>PROBLEMA:</strong> No hay informes en la base de datos</p>";
        echo "<p><strong>SOLUCIÓN:</strong> Crear informes de prueba</p>";
    } elseif (empty($audios)) {
        echo "<p style='color: red;'>❌ <strong>PROBLEMA:</strong> No hay audios en la base de datos</p>";
        echo "<p><strong>SOLUCIÓN:</strong> Insertar registros de audio</p>";
    } else {
        // Verificar vinculación específica
        $vinculacionCorrecta = $db->prepare("
            SELECT COUNT(*) as count 
            FROM audios_informe ai 
            JOIN informes i ON ai.informe_id = i.id 
            WHERE ai.activo = 1
        ");
        $vinculacionCorrecta->execute();
        $countVinculacion = $vinculacionCorrecta->fetch(PDO::FETCH_ASSOC)['count'];
        
        if ($countVinculacion == 0) {
            echo "<p style='color: red;'>❌ <strong>PROBLEMA:</strong> Los audios no están vinculados a informes válidos</p>";
            echo "<p><strong>SOLUCIÓN:</strong> Corregir las vinculaciones</p>";
        } else {
            echo "<p style='color: green;'>✅ <strong>TODO PARECE CORRECTO</strong></p>";
            echo "<p>El problema puede estar en el JavaScript del modal</p>";
        }
    }
    
    echo "<hr>";
    echo "<h3>🔧 Acciones recomendadas:</h3>";
    echo "<ol>";
    echo "<li><a href='fix-audio-linking.php'>Ejecutar corrección de vinculación</a></li>";
    echo "<li><a href='components/informes-manager.html'>Probar modal después de corrección</a></li>";
    echo "<li>Revisar consola del navegador para errores JavaScript</li>";
    echo "</ol>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>