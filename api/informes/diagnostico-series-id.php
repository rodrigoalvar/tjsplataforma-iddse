<?php
/**
 * Diagnóstico del guardado de pacs_series_id
 * Verifica por qué no se está guardando el series_id
 */

header('Content-Type: text/html; charset=utf-8');

require_once '../../vendor/autoload.php';
require_once '../../classes/User.php';

try {
    $db = getDBConnection();
    
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Diagnóstico pacs_series_id</title>
        <style>
            body { font-family: Arial; padding: 20px; background: #f5f5f5; }
            .container { background: white; padding: 30px; border-radius: 10px; max-width: 1200px; margin: 0 auto; }
            h1 { color: #333; border-bottom: 3px solid #667eea; padding-bottom: 10px; }
            .status { padding: 15px; margin: 10px 0; border-radius: 5px; border-left: 5px solid; }
            .success { background: #d4edda; border-color: #28a745; color: #155724; }
            .error { background: #f8d7da; border-color: #dc3545; color: #721c24; }
            .warning { background: #fff3cd; border-color: #ffc107; color: #856404; }
            table { width: 100%; border-collapse: collapse; margin: 20px 0; }
            th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
            th { background: #f8f9fa; font-weight: 600; }
            code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>🔍 Diagnóstico: pacs_series_id No Se Guarda</h1>
            
            <?php
            // 1. Verificar si la columna existe
            echo '<h2>1️⃣ Verificación de Columna</h2>';
            
            $columnExists = false;
            try {
                $checkQuery = "SHOW COLUMNS FROM informes LIKE 'pacs_series_id'";
                $checkStmt = $db->query($checkQuery);
                $columnExists = $checkStmt->rowCount() > 0;
                
                if ($columnExists) {
                    echo '<div class="status success">';
                    echo '<strong>✅ Columna pacs_series_id EXISTE</strong><br>';
                    echo 'La columna está disponible en la tabla informes.';
                    echo '</div>';
                    
                    // Obtener detalles de la columna
                    $detailQuery = "SHOW COLUMNS FROM informes WHERE Field = 'pacs_series_id'";
                    $detailStmt = $db->query($detailQuery);
                    $columnDetail = $detailStmt->fetch(PDO::FETCH_ASSOC);
                    
                    echo '<table>';
                    echo '<tr><th>Campo</th><th>Valor</th></tr>';
                    echo '<tr><td>Field</td><td>' . htmlspecialchars($columnDetail['Field']) . '</td></tr>';
                    echo '<tr><td>Type</td><td>' . htmlspecialchars($columnDetail['Type']) . '</td></tr>';
                    echo '<tr><td>Null</td><td>' . htmlspecialchars($columnDetail['Null']) . '</td></tr>';
                    echo '<tr><td>Default</td><td>' . htmlspecialchars($columnDetail['Default'] ?? 'NULL') . '</td></tr>';
                    echo '</table>';
                } else {
                    echo '<div class="status error">';
                    echo '<strong>❌ Columna pacs_series_id NO EXISTE</strong><br><br>';
                    echo '<strong>Solución:</strong><br>';
                    echo 'Ejecutar este SQL:<br>';
                    echo '<code>ALTER TABLE informes ADD COLUMN pacs_series_id VARCHAR(255) DEFAULT NULL AFTER pacs_study_id;</code>';
                    echo '</div>';
                }
            } catch (Exception $e) {
                echo '<div class="status error">Error verificando columna: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
            
            // 2. Verificar informes enviados a PACS
            echo '<h2>2️⃣ Informes Enviados a PACS</h2>';
            
            if ($columnExists) {
                try {
                    $query = "SELECT id, titulo, 
                                     pacs_instance_id, 
                                     pacs_study_id, 
                                     pacs_series_id,
                                     fecha_enviado_pacs
                              FROM informes 
                              WHERE pacs_instance_id IS NOT NULL 
                              ORDER BY fecha_enviado_pacs DESC 
                              LIMIT 20";
                    $stmt = $db->prepare($query);
                    $stmt->execute();
                    $informes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (count($informes) > 0) {
                        echo '<p><strong>Total encontrados:</strong> ' . count($informes) . ' informes con Instance ID</p>';
                        
                        $conSeriesId = 0;
                        $sinSeriesId = 0;
                        
                        echo '<table>';
                        echo '<tr>';
                        echo '<th>ID</th>';
                        echo '<th>Título</th>';
                        echo '<th>Instance ID</th>';
                        echo '<th>Study ID</th>';
                        echo '<th>Series ID</th>';
                        echo '<th>Fecha Enviado</th>';
                        echo '</tr>';
                        
                        foreach ($informes as $informe) {
                            $hasSeriesId = !empty($informe['pacs_series_id']);
                            if ($hasSeriesId) {
                                $conSeriesId++;
                            } else {
                                $sinSeriesId++;
                            }
                            
                            $rowClass = $hasSeriesId ? '' : 'style="background: #fff3cd;"';
                            echo '<tr ' . $rowClass . '>';
                            echo '<td>' . htmlspecialchars($informe['id']) . '</td>';
                            echo '<td>' . htmlspecialchars($informe['titulo'] ?? 'Sin título') . '</td>';
                            echo '<td><code>' . htmlspecialchars(substr($informe['pacs_instance_id'] ?? 'NULL', 0, 30)) . '...</code></td>';
                            echo '<td><code>' . htmlspecialchars(substr($informe['pacs_study_id'] ?? 'NULL', 0, 30)) . '...</code></td>';
                            if ($hasSeriesId) {
                                echo '<td><code style="color: green; font-weight: bold;">' . htmlspecialchars(substr($informe['pacs_series_id'], 0, 40)) . '...</code></td>';
                            } else {
                                echo '<td><code style="color: red; font-weight: bold;">NULL</code></td>';
                            }
                            echo '<td>' . htmlspecialchars($informe['fecha_enviado_pacs'] ?? 'N/A') . '</td>';
                            echo '</tr>';
                        }
                        
                        echo '</table>';
                        
                        echo '<div class="status ' . ($sinSeriesId > 0 ? 'warning' : 'success') . '">';
                        echo '<strong>Resumen:</strong><br>';
                        echo '✅ Con Series ID: ' . $conSeriesId . '<br>';
                        echo '❌ Sin Series ID: ' . $sinSeriesId;
                        echo '</div>';
                        
                        if ($sinSeriesId > 0) {
                            echo '<div class="status warning">';
                            echo '<strong>⚠️ Hay ' . $sinSeriesId . ' informes sin Series ID guardado</strong><br><br>';
                            echo '<strong>Posibles causas:</strong><br>';
                            echo '1. Los informes se enviaron antes de que la columna existiera<br>';
                            echo '2. El Series ID no se está extrayendo correctamente de la respuesta de Orthanc<br>';
                            echo '3. Hay un error en el guardado que no se está mostrando<br><br>';
                            echo '<strong>Verificar logs:</strong><br>';
                            echo 'Buscar en logs: <code>GUARDADO_BD</code> y <code>SeriesID extraído</code>';
                            echo '</div>';
                        }
                    } else {
                        echo '<div class="status warning">No hay informes enviados a PACS aún.</div>';
                    }
                    
                } catch (PDOException $e) {
                    echo '<div class="status error">Error consultando informes: ' . htmlspecialchars($e->getMessage()) . '</div>';
                }
            } else {
                echo '<div class="status warning">No se puede verificar informes porque la columna no existe.</div>';
            }
            
            // 3. Verificar logs recientes
            echo '<h2>3️⃣ Verificar Logs</h2>';
            echo '<div class="status warning">';
            echo '<strong>Buscar en logs de PHP:</strong><br><br>';
            echo '<code>[SEND_TO_PACS][GUARDADO_BD] Columna pacs_series_id existe</code><br>';
            echo '<code>[SEND_TO_PACS] SeriesID extraído</code><br>';
            echo '<code>[SEND_TO_PACS][GUARDADO_BD] Incluyendo pacs_series_id en UPDATE</code><br>';
            echo '<code>[SEND_TO_PACS][GUARDADO_BD] VERIFICACIÓN: Series ID guardado</code><br><br>';
            echo '<strong>Ubicación del log:</strong><br>';
            echo '<code>C:\\wamp64\\www\\PORTAL_ESTUDIOS\\logs\\php_errors.log</code><br><br>';
            echo '<strong>Comando para buscar:</strong><br>';
            echo '<code>findstr /C:"GUARDADO_BD" logs\\php_errors.log</code>';
            echo '</div>';
            
            // 4. SQL para actualizar informes existentes (si tienen Instance ID pero no Series ID)
            if ($columnExists) {
                echo '<h2>4️⃣ Información Adicional</h2>';
                
                try {
                    $statsQuery = "SELECT 
                                    COUNT(*) as total,
                                    COUNT(pacs_instance_id) as con_instance,
                                    COUNT(pacs_study_id) as con_study,
                                    COUNT(pacs_series_id) as con_series
                                   FROM informes
                                   WHERE pacs_instance_id IS NOT NULL";
                    $statsStmt = $db->query($statsQuery);
                    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
                    
                    echo '<table>';
                    echo '<tr><th>Métrica</th><th>Valor</th></tr>';
                    echo '<tr><td>Total informes con Instance ID</td><td>' . $stats['total'] . '</td></tr>';
                    echo '<tr><td>Con Study ID</td><td>' . $stats['con_study'] . '</td></tr>';
                    echo '<tr><td><strong>Con Series ID</strong></td><td><strong>' . $stats['con_series'] . '</strong></td></tr>';
                    echo '<tr><td><strong>Sin Series ID (problema)</strong></td><td><strong style="color: red;">' . ($stats['total'] - $stats['con_series']) . '</strong></td></tr>';
                    echo '</table>';
                } catch (PDOException $e) {
                    echo '<div class="status error">Error en estadísticas: ' . htmlspecialchars($e->getMessage()) . '</div>';
                }
            }
            ?>
            
            <hr style="margin: 30px 0;">
            
            <h2>📋 Soluciones</h2>
            <div class="status warning">
                <strong>Si la columna NO existe:</strong><br>
                <code>ALTER TABLE informes ADD COLUMN pacs_series_id VARCHAR(255) DEFAULT NULL AFTER pacs_study_id;</code><br><br>
                
                <strong>Si la columna existe pero está vacía:</strong><br>
                1. Verificar logs para ver si se está extrayendo el Series ID<br>
                2. Verificar que el guardado se ejecuta correctamente<br>
                3. Enviar un nuevo informe y verificar logs<br><br>
                
                <strong>Para rellenar Series ID de informes antiguos:</strong><br>
                Necesitarías obtener el Series ID desde Orthanc usando el Instance ID,<br>
                pero esto requiere hacer consultas a la API de Orthanc para cada informe.
            </div>
        </div>
    </body>
    </html>
    <?php
    
} catch (Exception $e) {
    echo '<div class="status error">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>

