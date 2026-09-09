<?php
/**
 * Test que simula exactamente lo que hace el modal JavaScript
 */

require_once 'config/database.php';

try {
    $db = getDBConnection();
    
    echo "<h2>🎯 Test Exacto del Modal JavaScript</h2>";
    
    // 1. Obtener datos como lo hace el modal
    echo "<h3>1. Obteniendo datos como el modal:</h3>";
    
    $informes = $db->query("SELECT * FROM informes ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($informes)) {
        echo "<p style='color: red;'>❌ No hay informes disponibles</p>";
        exit;
    }
    
    $reportData = $informes[0]; // Simular el primer informe
    echo "<p><strong>Informe seleccionado:</strong></p>";
    echo "<ul>";
    echo "<li>ID: " . $reportData['id'] . "</li>";
    echo "<li>Título: " . htmlspecialchars($reportData['titulo']) . "</li>";
    echo "<li>Paciente: " . htmlspecialchars($reportData['patient_name']) . "</li>";
    echo "<li>Estudio ID: " . htmlspecialchars($reportData['estudio_id']) . "</li>";
    echo "<li>Patient ID: " . htmlspecialchars($reportData['patient_id']) . "</li>";
    echo "</ul>";
    
    // 2. Simular la función loadAudiosForModal exactamente
    echo "<h3>2. Simulando loadAudiosForModal:</h3>";
    
    $reportId = $reportData['id'];
    $estudioId = $reportData['estudio_id'];
    $patientId = $reportData['patient_id'];
    
    echo "<p><strong>Parámetros que envía el modal:</strong></p>";
    echo "<ul>";
    echo "<li>reportId: $reportId</li>";
    echo "<li>estudioId: $estudioId</li>";
    echo "<li>patientId: $patientId</li>";
    echo "</ul>";
    
    $url = "../api/audios/get.php?informe_id=$reportId";
    echo "<p><strong>URL de la API:</strong> <code>$url</code></p>";
    
    // 3. Simular la llamada fetch exacta
    echo "<h3>3. Simulando llamada fetch:</h3>";
    
    // Simular los parámetros GET que recibiría get.php
    $_GET['informe_id'] = $reportId;
    $_GET['informeId'] = $reportId; // Por si acaso usa este nombre
    
    // Capturar la salida de get.php
    ob_start();
    
    // Simular el contexto de get.php
    $originalGet = $_GET;
    $_GET = [
        'informe_id' => $reportId,
        'informeId' => $reportId,
        'estudio_id' => $estudioId,
        'estudioId' => $estudioId,
        'patient_id' => $patientId,
        'patientId' => $patientId
    ];
    
    try {
        include 'api/audios/get.php';
        $apiResponse = ob_get_clean();
        
        echo "<p><strong>Respuesta de la API:</strong></p>";
        echo "<pre style='background: #f8f9fa; padding: 10px; border-radius: 5px; font-size: 11px; max-height: 300px; overflow-y: auto;'>";
        echo htmlspecialchars($apiResponse);
        echo "</pre>";
        
        // Intentar decodificar JSON
        $jsonData = json_decode($apiResponse, true);
        if ($jsonData) {
            echo "<p><strong>JSON decodificado:</strong></p>";
            if ($jsonData['success']) {
                echo "<p style='color: green;'>✅ API devuelve success: true</p>";
                echo "<p>Total de audios: " . ($jsonData['total'] ?? 0) . "</p>";
                
                if (!empty($jsonData['data'])) {
                    echo "<p style='color: green;'>✅ Hay datos de audio</p>";
                    echo "<table border='1' style='border-collapse: collapse; width: 100%; font-size: 12px;'>";
                    echo "<tr><th>ID</th><th>Archivo</th><th>Duración</th><th>URL</th></tr>";
                    foreach ($jsonData['data'] as $audio) {
                        echo "<tr>";
                        echo "<td>" . $audio['id'] . "</td>";
                        echo "<td>" . $audio['nombre_archivo'] . "</td>";
                        echo "<td>" . $audio['duracion_segundos'] . "s</td>";
                        echo "<td>" . $audio['url_completa'] . "</td>";
                        echo "</tr>";
                    }
                    echo "</table>";
                } else {
                    echo "<p style='color: red;'>❌ No hay datos en la respuesta</p>";
                }
            } else {
                echo "<p style='color: red;'>❌ API devuelve success: false</p>";
                echo "<p>Mensaje: " . ($jsonData['message'] ?? 'Sin mensaje') . "</p>";
            }
        } else {
            echo "<p style='color: red;'>❌ La respuesta no es JSON válido</p>";
        }
        
    } catch (Exception $e) {
        ob_end_clean();
        echo "<p style='color: red;'>❌ Error ejecutando get.php: " . $e->getMessage() . "</p>";
    }
    
    $_GET = $originalGet; // Restaurar GET original
    
    // 4. Simular renderAudioList
    echo "<h3>4. Simulando renderAudioList:</h3>";
    
    if (isset($jsonData) && $jsonData && $jsonData['success'] && !empty($jsonData['data'])) {
        echo "<p style='color: green;'>✅ El modal mostraría los audios</p>";
        echo "<p>Contenido que se renderizaría en audioListContainer:</p>";
        
        echo "<div style='border: 1px solid #ddd; padding: 10px; background: #f9f9f9;'>";
        foreach ($jsonData['data'] as $index => $audio) {
            echo "<div style='border-bottom: 1px solid #eee; padding: 5px;'>";
            echo "<strong>" . htmlspecialchars($audio['nombre_archivo']) . "</strong><br>";
            echo "Duración: " . $audio['duracion_segundos'] . "s<br>";
            echo "URL: " . htmlspecialchars($audio['url_completa']) . "<br>";
            echo "<button onclick='playAudio($index)'>▶ Reproducir</button>";
            echo "</div>";
        }
        echo "</div>";
        
    } else {
        echo "<p style='color: red;'>❌ El modal mostraría: 'No hay audios disponibles'</p>";
        echo "<div style='border: 1px solid #ddd; padding: 20px; background: #f9f9f9; text-align: center;'>";
        echo "<i class='fas fa-volume-mute fa-3x text-muted mb-3'></i><br>";
        echo "<h6 class='text-muted'>No hay audios disponibles</h6>";
        echo "<p class='text-muted mb-0'>Este estudio no tiene audios asociados.</p>";
        echo "</div>";
    }
    
    // 5. Diagnóstico final
    echo "<h3>5. 🎯 Diagnóstico Final:</h3>";
    
    if (isset($jsonData) && $jsonData && $jsonData['success'] && !empty($jsonData['data'])) {
        echo "<p style='color: green; font-size: 16px; font-weight: bold;'>✅ TODO FUNCIONA CORRECTAMENTE</p>";
        echo "<p>El modal debería mostrar los audios. Si no los muestra, el problema está en:</p>";
        echo "<ul>";
        echo "<li>JavaScript del navegador (revisar consola)</li>";
        echo "<li>Problemas de autenticación (token)</li>";
        echo "<li>Problemas de CORS o rutas</li>";
        echo "</ul>";
    } else {
        echo "<p style='color: red; font-size: 16px; font-weight: bold;'>❌ PROBLEMA IDENTIFICADO</p>";
        echo "<p>La API no devuelve datos. Posibles causas:</p>";
        echo "<ul>";
        echo "<li>No hay audios vinculados al informe</li>";
        echo "<li>Error en la consulta SQL</li>";
        echo "<li>Problema en get.php</li>";
        echo "</ul>";
    }
    
    echo "<hr>";
    echo "<h3>🔧 Acciones recomendadas:</h3>";
    echo "<ol>";
    echo "<li><a href='fix-audio-linking.php' target='_blank'>Ejecutar corrección de vinculación</a></li>";
    echo "<li><a href='components/informes-manager.html' target='_blank'>Probar modal después de corrección</a></li>";
    echo "<li>Revisar consola del navegador (F12)</li>";
    echo "<li>Verificar que el servidor web esté funcionando</li>";
    echo "</ol>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>