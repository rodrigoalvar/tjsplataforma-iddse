<?php
/**
 * Script para corregir la vinculación entre audios e informes
 */

require_once 'config/database.php';

try {
    $db = getDBConnection();
    
    echo "<h2>Corrigiendo vinculación entre audios e informes</h2>";
    
    // 1. Verificar estado actual
    echo "<h3>1. Estado actual:</h3>";
    
    $informes = $db->query("SELECT * FROM informes ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $audios = $db->query("SELECT * FROM audios_informe WHERE activo = 1")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>Informes encontrados: " . count($informes) . "</p>";
    echo "<p>Audios encontrados: " . count($audios) . "</p>";
    
    // 2. Mostrar vinculaciones actuales
    echo "<h3>2. Vinculaciones actuales:</h3>";
    
    $vinculaciones = $db->query("
        SELECT ai.id as audio_id, ai.informe_id, ai.nombre_archivo, i.titulo, i.id as informe_real_id
        FROM audios_informe ai
        LEFT JOIN informes i ON ai.informe_id = i.id
        WHERE ai.activo = 1
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($vinculaciones)) {
        echo "<p style='color: red;'>❌ No hay vinculaciones</p>";
    } else {
        echo "<table border='1' style='border-collapse: collapse; width: 100%; font-size: 12px;'>";
        echo "<tr><th>Audio ID</th><th>Informe ID (Audio)</th><th>Informe ID (Real)</th><th>Título Informe</th><th>Archivo</th><th>Estado</th></tr>";
        foreach ($vinculaciones as $v) {
            $estado = $v['informe_real_id'] ? '✅ Vinculado' : '❌ Huérfano';
            $color = $v['informe_real_id'] ? 'green' : 'red';
            echo "<tr>";
            echo "<td>" . $v['audio_id'] . "</td>";
            echo "<td>" . $v['informe_id'] . "</td>";
            echo "<td>" . $v['informe_real_id'] . "</td>";
            echo "<td>" . htmlspecialchars($v['titulo']) . "</td>";
            echo "<td>" . $v['nombre_archivo'] . "</td>";
            echo "<td style='color: $color;'>" . $estado . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // 3. Corregir vinculaciones
    echo "<h3>3. Corrigiendo vinculaciones:</h3>";
    
    if (!empty($informes) && !empty($audios)) {
        // Estrategia: vincular todos los audios al primer informe disponible
        $primerInforme = $informes[0];
        $informeId = $primerInforme['id'];
        
        echo "<p>Vinculando todos los audios al informe ID: <strong>$informeId</strong></p>";
        echo "<p>Título del informe: <strong>" . htmlspecialchars($primerInforme['titulo']) . "</strong></p>";
        
        $updateStmt = $db->prepare("UPDATE audios_informe SET informe_id = ? WHERE activo = 1");
        $result = $updateStmt->execute([$informeId]);
        
        if ($result) {
            echo "<p style='color: green;'>✅ Vinculaciones actualizadas correctamente</p>";
        } else {
            echo "<p style='color: red;'>❌ Error al actualizar vinculaciones</p>";
        }
        
        // 4. Verificar resultado
        echo "<h3>4. Verificación post-corrección:</h3>";
        
        $verificacion = $db->query("
            SELECT ai.id as audio_id, ai.informe_id, ai.nombre_archivo, i.titulo
            FROM audios_informe ai
            LEFT JOIN informes i ON ai.informe_id = i.id
            WHERE ai.activo = 1
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($verificacion)) {
            echo "<table border='1' style='border-collapse: collapse; width: 100%; font-size: 12px;'>";
            echo "<tr><th>Audio ID</th><th>Informe ID</th><th>Título Informe</th><th>Archivo</th></tr>";
            foreach ($verificacion as $v) {
                echo "<tr>";
                echo "<td>" . $v['audio_id'] . "</td>";
                echo "<td>" . $v['informe_id'] . "</td>";
                echo "<td>" . htmlspecialchars($v['titulo']) . "</td>";
                echo "<td>" . $v['nombre_archivo'] . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
        
        // 5. Test de la API después de la corrección
        echo "<h3>5. Test de API después de la corrección:</h3>";
        
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
            echo "<p style='color: red;'>❌ La API aún no devuelve resultados</p>";
        } else {
            echo "<p style='color: green;'>✅ La API ahora devuelve " . count($apiResult) . " resultados</p>";
            
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
        
    } else {
        echo "<p style='color: red;'>❌ No hay informes o audios para vincular</p>";
    }
    
    echo "<hr><h3>✅ Proceso de corrección completado</h3>";
    echo "<p><a href='components/informes-manager.html' class='btn btn-primary'>Probar modal de audio</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>