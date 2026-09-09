<?php
/**
 * Script para verificar que el series_id se está guardando correctamente en BD
 * 
 * Este script muestra todos los informes que tienen series_id guardado
 * y permite verificar que el flujo está funcionando correctamente.
 */

header('Content-Type: text/html; charset=utf-8');

require_once '../../vendor/autoload.php';
require_once '../../classes/User.php';

try {
    // Validar sesión
    $sessionToken = null;
    
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        $sessionToken = $headers['Authorization'] ?? null;
    }
    
    if (!$sessionToken) {
        $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    }
    
    if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
        $sessionToken = substr($sessionToken, 7);
    }
    
    if (!$sessionToken) {
        // Para verificación, permitir acceso sin token o mostrar mensaje
        $showMessage = true;
    } else {
        $user = new User();
        $userData = $user->validateSession($sessionToken);
        if (!$userData) {
            $showMessage = true;
        }
    }
    
    $db = getDBConnection();
    
    // Verificar si la columna existe
    $columnExists = false;
    try {
        $checkQuery = "SHOW COLUMNS FROM informes LIKE 'pacs_series_id'";
        $checkStmt = $db->query($checkQuery);
        $columnExists = $checkStmt->rowCount() > 0;
    } catch (PDOException $e) {
        $columnExists = false;
    }
    
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Verificación Series ID en BD</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                max-width: 1200px;
                margin: 40px auto;
                padding: 20px;
                background: #f5f5f5;
            }
            .container {
                background: white;
                padding: 30px;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            h1 {
                color: #333;
                border-bottom: 3px solid #667eea;
                padding-bottom: 10px;
            }
            .status {
                padding: 15px;
                margin: 10px 0;
                border-radius: 5px;
                border-left: 5px solid;
            }
            .status.success {
                background: #d4edda;
                border-color: #28a745;
                color: #155724;
            }
            .status.error {
                background: #f8d7da;
                border-color: #dc3545;
                color: #721c24;
            }
            .status.warning {
                background: #fff3cd;
                border-color: #ffc107;
                color: #856404;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 20px;
            }
            th, td {
                padding: 12px;
                text-align: left;
                border-bottom: 1px solid #ddd;
            }
            th {
                background: #f8f9fa;
                font-weight: 600;
            }
            tr:hover {
                background: #f8f9fa;
            }
            .code {
                background: #f4f4f4;
                padding: 2px 6px;
                border-radius: 3px;
                font-family: monospace;
                font-size: 12px;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>🔍 Verificación de Series ID en Base de Datos</h1>
            
            <?php
            // Verificar columna
            echo '<h2>1️⃣ Verificación de Columna</h2>';
            
            if ($columnExists) {
                echo '<div class="status success">';
                echo '<strong>✅ Columna pacs_series_id existe</strong><br>';
                echo 'La columna está disponible para almacenar el Series ID de Orthanc.';
                echo '</div>';
            } else {
                echo '<div class="status error">';
                echo '<strong>❌ Columna pacs_series_id NO existe</strong><br>';
                echo 'La columna no existe en la tabla informes.<br><br>';
                echo '<strong>Para crearla, ejecutar:</strong><br>';
                echo '<code>ALTER TABLE informes ADD COLUMN pacs_series_id VARCHAR(255) DEFAULT NULL AFTER pacs_study_id;</code>';
                echo '</div>';
            }
            
            // Mostrar informes con series_id
            echo '<h2>2️⃣ Informes con Series ID Guardado</h2>';
            
            if ($columnExists) {
                try {
                    $query = "SELECT id, titulo, paciente_id, patient_name, 
                                     pacs_instance_id, pacs_study_id, pacs_series_id, 
                                     fecha_enviado_pacs
                              FROM informes 
                              WHERE pacs_series_id IS NOT NULL 
                              ORDER BY fecha_enviado_pacs DESC 
                              LIMIT 50";
                    $stmt = $db->prepare($query);
                    $stmt->execute();
                    $informes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (count($informes) > 0) {
                        echo '<p><strong>Total:</strong> ' . count($informes) . ' informes con Series ID guardado</p>';
                        echo '<table>';
                        echo '<tr>';
                        echo '<th>ID</th>';
                        echo '<th>Título</th>';
                        echo '<th>Paciente</th>';
                        echo '<th>Instance ID</th>';
                        echo '<th>Study ID</th>';
                        echo '<th>Series ID</th>';
                        echo '<th>Fecha Enviado</th>';
                        echo '</tr>';
                        
                        foreach ($informes as $informe) {
                            echo '<tr>';
                            echo '<td>' . htmlspecialchars($informe['id']) . '</td>';
                            echo '<td>' . htmlspecialchars($informe['titulo'] ?? 'Sin título') . '</td>';
                            echo '<td>' . htmlspecialchars($informe['patient_name'] ?? $informe['paciente_id'] ?? 'N/A') . '</td>';
                            echo '<td><code>' . htmlspecialchars(substr($informe['pacs_instance_id'] ?? 'N/A', 0, 20)) . '...</code></td>';
                            echo '<td><code>' . htmlspecialchars(substr($informe['pacs_study_id'] ?? 'N/A', 0, 20)) . '...</code></td>';
                            echo '<td><code style="color: green; font-weight: bold;">' . htmlspecialchars(substr($informe['pacs_series_id'] ?? 'N/A', 0, 30)) . '...</code></td>';
                            echo '<td>' . htmlspecialchars($informe['fecha_enviado_pacs'] ?? 'N/A') . '</td>';
                            echo '</tr>';
                        }
                        
                        echo '</table>';
                        
                        // Estadísticas
                        $statsQuery = "SELECT 
                                        COUNT(*) as total,
                                        COUNT(pacs_series_id) as con_series_id,
                                        COUNT(pacs_instance_id) as con_instance_id,
                                        COUNT(pacs_study_id) as con_study_id
                                       FROM informes
                                       WHERE fecha_enviado_pacs IS NOT NULL";
                        $statsStmt = $db->query($statsQuery);
                        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
                        
                        echo '<h3>📊 Estadísticas</h3>';
                        echo '<table>';
                        echo '<tr><th>Métrica</th><th>Valor</th></tr>';
                        echo '<tr><td>Total informes enviados a PACS</td><td>' . $stats['total'] . '</td></tr>';
                        echo '<tr><td>Con Instance ID</td><td>' . $stats['con_instance_id'] . '</td></tr>';
                        echo '<tr><td>Con Study ID</td><td>' . $stats['con_study_id'] . '</td></tr>';
                        echo '<tr><td><strong>Con Series ID</strong></td><td><strong style="color: green;">' . $stats['con_series_id'] . '</strong></td></tr>';
                        echo '</table>';
                        
                    } else {
                        echo '<div class="status warning">';
                        echo '<strong>⚠️ No hay informes con Series ID guardado</strong><br>';
                        echo 'Esto puede significar que:<br>';
                        echo '1. No se ha enviado ningún informe a PACS aún<br>';
                        echo '2. La columna no existe y necesita crearse<br>';
                        echo '3. Orthanc no está devolviendo ParentSeries en la respuesta';
                        echo '</div>';
                    }
                    
                } catch (PDOException $e) {
                    echo '<div class="status error">';
                    echo '<strong>❌ Error consultando BD:</strong><br>';
                    echo htmlspecialchars($e->getMessage());
                    echo '</div>';
                }
            } else {
                echo '<div class="status warning">';
                echo 'No se puede mostrar informes porque la columna no existe.<br>';
                echo 'Crea la columna primero ejecutando el SQL del punto 1.';
                echo '</div>';
            }
            
            // Verificar estructura de la tabla
            echo '<h2>3️⃣ Estructura de Tabla informes (Columnas PACS)</h2>';
            
            try {
                $structureQuery = "SHOW COLUMNS FROM informes WHERE Field LIKE 'pacs%' OR Field LIKE '%series%'";
                $structureStmt = $db->query($structureQuery);
                $columns = $structureStmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (count($columns) > 0) {
                    echo '<table>';
                    echo '<tr><th>Columna</th><th>Tipo</th><th>Null</th><th>Default</th></tr>';
                    foreach ($columns as $col) {
                        $highlight = ($col['Field'] === 'pacs_series_id') ? 'style="background: #d4edda;"' : '';
                        echo '<tr ' . $highlight . '>';
                        echo '<td><strong>' . htmlspecialchars($col['Field']) . '</strong></td>';
                        echo '<td>' . htmlspecialchars($col['Type']) . '</td>';
                        echo '<td>' . htmlspecialchars($col['Null']) . '</td>';
                        echo '<td>' . htmlspecialchars($col['Default'] ?? 'NULL') . '</td>';
                        echo '</tr>';
                    }
                    echo '</table>';
                } else {
                    echo '<div class="status warning">No se encontraron columnas PACS en la tabla.</div>';
                }
            } catch (PDOException $e) {
                echo '<div class="status error">Error obteniendo estructura: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
            
            ?>
            
            <hr style="margin: 30px 0;">
            
            <h2>📋 Resumen</h2>
            <div class="info" style="background: #e7f3ff; padding: 15px; border-radius: 5px; border-left: 5px solid #2196F3;">
                <strong>Flujo esperado:</strong><br>
                1. ✅ Enviar informe a Orthanc<br>
                2. ✅ Orthanc responde con JSON (incluye ParentSeries)<br>
                3. ✅ Extraer ParentSeries de la respuesta<br>
                4. ✅ Guardar en BD: <code>pacs_series_id = ParentSeries</code><br>
                5. ✅ Al reenviar, leer series_id anterior de BD<br>
                6. ✅ Eliminar serie anterior de Orthanc<br>
                7. ✅ Guardar nuevo series_id después del reenvío<br>
            </div>
        </div>
    </body>
    </html>
    <?php
    
} catch (Exception $e) {
    echo '<div class="status error">';
    echo '<strong>Error:</strong> ' . htmlspecialchars($e->getMessage());
    echo '</div>';
}
?>

