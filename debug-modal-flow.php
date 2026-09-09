<?php
/**
 * Debug completo del flujo del modal de audio
 */

require_once 'config/database.php';

try {
    $db = getDBConnection();
    
    echo "<h2>Debug completo del flujo del modal de audio</h2>";
    
    // 1. Verificar datos base
    echo "<h3>1. Datos base en la aplicación:</h3>";
    
    // Verificar informes
    $informes = $db->query("SELECT * FROM informes ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    echo "<h4>Informes disponibles:</h4>";
    if (empty($informes)) {
        echo "<p style='color: red;'>❌ No hay informes</p>";
    } else {
        echo "<table border='1' style='border-collapse: collapse; width: 100%; font-size: 12px;'>";
        echo "<tr><th>ID</th><th>Estudio ID</th><th>Patient ID</th><th>Título</th><th>Fecha</th></tr>";
        foreach ($informes as $informe) {
            echo "<tr>";
            echo "<td>" . $informe['id'] . "</td>";
            echo "<td>" . htmlspecialchars($informe['estudio_id']) . "</td>";
            echo "<td>" . htmlspecialchars($informe['patient_id']) . "</td>";
            echo "<td>" . htmlspecialchars($informe['titulo']) . "</td>";
            echo "<td>" . $informe['fecha_creacion'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Verificar audios
    $audios = $db->query("
        SELECT ai.*, i.titulo as informe_titulo, i.estudio_id, i.patient_id as informe_patient_id
        FROM audios_informe ai 
        LEFT JOIN informes i ON ai.informe_id = i.id 
        WHERE ai.activo = 1
        ORDER BY ai.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h4>Audios disponibles:</h4>";
    if (empty($audios)) {
        echo "<p style='color: red;'>❌ No hay audios</p>";
    } else {
        echo "<table border='1' style='border-collapse: collapse; width: 100%; font-size: 12px;'>";
        echo "<tr><th>ID</th><th>Informe ID</th><th>Archivo</th><th>Ruta</th><th>Existe Físico</th></tr>";
        foreach ($audios as $audio) {
            $exists = file_exists($audio['nombre_archivo']) ? '✅' : '❌';
            echo "<tr>";
            echo "<td>" . $audio['id'] . "</td>";
            echo "<td>" . $audio['informe_id'] . "</td>";
            echo "<td>" . $audio['nombre_archivo'] . "</td>";
            echo "<td>" . $audio['ruta_archivo'] . "</td>";
            echo "<td>" . $exists . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // 2. Simular llamada a la API exactamente como lo hace el modal
    echo "<h3>2. Simulación de llamada API del modal:</h3>";
    
    if (!empty($informes)) {
        $testInforme = $informes[0];
        $informeId = $testInforme['id'];
        
        echo "<p>Probando con informe ID: <strong>$informeId</strong></p>";
        echo "<p>URL que usaría el modal: <code>../api/audios/get.php?informe_id=$informeId</code></p>";
        
        // Simular exactamente lo que hace get.php
        $stmt = $db->prepare("
            SELECT ai.*, i.titulo as informe_titulo, i.patient_name
            FROM audios_informe ai
            LEFT JOIN informes i ON ai.informe_id = i.id
            WHERE ai.activo = 1 AND ai.informe_id = ?
            ORDER BY ai.fecha_creacion DESC
        ");
        
        $stmt->execute([$informeId]);
        $apiResult = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($apiResult)) {
            echo "<p style='color: red;'>❌ La API no devolvería resultados para informe ID $informeId</p>";
            
            // Verificar si hay audios para otros informes
            $otherAudios = $db->query("
                SELECT DISTINCT informe_id, COUNT(*) as count
                FROM audios_informe 
                WHERE activo = 1 
                GROUP BY informe_id
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($otherAudios)) {
                echo "<p style='color: orange;'>⚠️ Pero hay audios para otros informes:</p>";
                echo "<ul>";
                foreach ($otherAudios as $other) {
                    echo "<li>Informe ID {$other['informe_id']}: {$other['count']} audios</li>";
                }
                echo "</ul>";
            }
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
            
            echo "<pre style='background: #f8f9fa; padding: 10px; border-radius: 5px; font-size: 11px;'>";
            echo json_encode([
                'success' => true,
                'message' => 'Audios encontrados',
                'data' => $formattedResults,
                'total' => count($formattedResults)
            ], JSON_PRETTY_PRINT);
            echo "</pre>";
        }
    }
    
    // 3. Test directo de la API
    echo "<h3>3. Test directo de la API:</h3>";
    
    if (!empty($informes)) {
        $testInforme = $informes[0];
        $informeId = $testInforme['id'];
        
        echo "<p>Haciendo petición HTTP a: <code>http://localhost:8080/api/audios/get.php?informe_id=$informeId</code></p>";
        
        $apiUrl = "http://localhost:8080/api/audios/get.php?informe_id=$informeId";
        
        // Usar cURL para hacer la petición
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            echo "<p style='color: red;'>❌ Error cURL: $error</p>";
        } else {
            echo "<p>Código HTTP: <strong>$httpCode</strong></p>";
            
            if ($httpCode == 200) {
                echo "<p style='color: green;'>✅ Respuesta exitosa:</p>";
                echo "<pre style='background: #f8f9fa; padding: 10px; border-radius: 5px; font-size: 11px;'>";
                echo htmlspecialchars($response);
                echo "</pre>";
            } else {
                echo "<p style='color: red;'>❌ Error HTTP $httpCode:</p>";
                echo "<pre style='background: #ffe6e6; padding: 10px; border-radius: 5px; font-size: 11px;'>";
                echo htmlspecialchars($response);
                echo "</pre>";
            }
        }
    }
    
    // 4. Verificar estructura del HTML
    echo "<h3>4. Verificación de elementos HTML:</h3>";
    
    $htmlFile = 'components/informes-manager.html';
    if (file_exists($htmlFile)) {
        $htmlContent = file_get_contents($htmlFile);
        
        $elements = [
            'audioPlayerModal' => 'Modal de reproductor de audio',
            'audioListContainer' => 'Contenedor de lista de audios',
            'mainAudioElement' => 'Elemento de audio principal',
            'audioModalPatientName' => 'Nombre del paciente en modal',
            'audioModalTotalCount' => 'Contador total de audios'
        ];
        
        foreach ($elements as $id => $description) {
            if (strpos($htmlContent, "id=\"$id\"") !== false) {
                echo "<p style='color: green;'>✅ $description (id=\"$id\")</p>";
            } else {
                echo "<p style='color: red;'>❌ $description (id=\"$id\") - NO ENCONTRADO</p>";
            }
        }
    } else {
        echo "<p style='color: red;'>❌ Archivo HTML no encontrado: $htmlFile</p>";
    }
    
    // 5. Recomendaciones
    echo "<h3>5. Diagnóstico y recomendaciones:</h3>";
    
    if (empty($audios)) {
        echo "<div style='background: #ffe6e6; padding: 15px; border-radius: 5px; border-left: 4px solid #dc3545;'>";
        echo "<h4 style='color: #dc3545; margin-top: 0;'>🚨 PROBLEMA PRINCIPAL: No hay audios en la base de datos</h4>";
        echo "<p><strong>Solución:</strong> Ejecutar nuevamente el script de corrección de datos.</p>";
        echo "<p><a href='fix-audio-data.php' class='btn btn-danger'>Ejecutar corrección de datos</a></p>";
        echo "</div>";
    } else if (empty($apiResult)) {
        echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; border-left: 4px solid #ffc107;'>";
        echo "<h4 style='color: #856404; margin-top: 0;'>⚠️ PROBLEMA: Audios existen pero no están vinculados al informe correcto</h4>";
        echo "<p><strong>Solución:</strong> Verificar la vinculación entre audios e informes.</p>";
        echo "</div>";
    } else {
        echo "<div style='background: #d1edff; padding: 15px; border-radius: 5px; border-left: 4px solid #0d6efd;'>";
        echo "<h4 style='color: #084298; margin-top: 0;'>ℹ️ Los datos parecen estar correctos</h4>";
        echo "<p>El problema podría estar en:</p>";
        echo "<ul>";
        echo "<li>Autenticación/sesión en el frontend</li>";
        echo "<li>Errores JavaScript en la consola</li>";
        echo "<li>Problemas de CORS o permisos</li>";
        echo "</ul>";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>

<hr>
<h3>Acciones disponibles:</h3>
<p>
    <a href="components/informes-manager.html" class="btn btn-primary">Probar Modal de Audio</a>
    <a href="fix-audio-data.php" class="btn btn-warning">Corregir Datos</a>
    <a href="test-audio-api-direct.php" class="btn btn-info">Test API Directo</a>
</p>