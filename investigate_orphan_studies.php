<?php
/**
 * Script para investigar estudios derivados huérfanos
 * Busca estudios derivados que no existen en el sistema
 */

require_once __DIR__ . '/config/database.php';

header('Content-Type: text/html; charset=utf-8');

$email = 'luisfajre@iddse.com.ar';
$studyIds = ['2500c434', '18648f15'];

echo "<h2>🔍 Investigación de Estudios Derivados Huérfanos</h2>\n";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .success { color: green; }
    .error { color: red; }
    .warning { color: orange; }
    .info { color: blue; }
    table { border-collapse: collapse; width: 100%; margin: 20px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #f2f2f2; }
    .code { background-color: #f4f4f4; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
</style>\n";

try {
    $pdo = getDBConnection();
    
    if (!$pdo) {
        echo "<p class='error'>❌ Error: No se pudo conectar a la base de datos</p>";
        exit(1);
    }
    
    echo "<p class='success'>✅ Conexión a BD exitosa</p>\n";
    
    // 1. Obtener información del usuario
    echo "<h3>1. Información del Usuario</h3>\n";
    $stmt = $pdo->prepare("SELECT id, nombre, apellido, email, padre_id, nivel, activo FROM usuarios WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo "<p class='error'>❌ Usuario no encontrado: $email</p>";
        exit(1);
    }
    
    $userId = $user['id'];
    echo "<table>\n";
    echo "<tr><th>Campo</th><th>Valor</th></tr>\n";
    echo "<tr><td>ID</td><td>{$user['id']}</td></tr>\n";
    echo "<tr><td>Nombre</td><td>{$user['nombre']} {$user['apellido']}</td></tr>\n";
    echo "<tr><td>Email</td><td>{$user['email']}</td></tr>\n";
    echo "<tr><td>Padre ID</td><td>" . ($user['padre_id'] ?? 'NULL') . "</td></tr>\n";
    echo "<tr><td>Nivel</td><td>{$user['nivel']}</td></tr>\n";
    echo "<tr><td>Activo</td><td>" . ($user['activo'] ? 'Sí' : 'No') . "</td></tr>\n";
    echo "</table>\n";
    
    // 2. Buscar derivaciones (subasignaciones) para este usuario
    echo "<h3>2. Derivaciones (Subasignaciones) para este Usuario</h3>\n";
    
    $placeholders = str_repeat('?,', count($studyIds) - 1) . '?';
    $query = "
        SELECT 
            ss.id,
            ss.study_id,
            ss.main_user_id,
            ss.subassigned_to_user_id,
            ss.assigned_by_user_id,
            ss.subassigned_at,
            ss.status,
            u_main.nombre as main_user_nombre,
            u_main.apellido as main_user_apellido,
            u_main.email as main_user_email,
            u_by.nombre as assigned_by_nombre,
            u_by.apellido as assigned_by_apellido,
            u_by.email as assigned_by_email
        FROM study_subassignments ss
        LEFT JOIN usuarios u_main ON ss.main_user_id = u_main.id
        LEFT JOIN usuarios u_by ON ss.assigned_by_user_id = u_by.id
        WHERE ss.subassigned_to_user_id = ?
        AND ss.study_id IN ($placeholders)
        ORDER BY ss.subassigned_at DESC
    ";
    
    $params = array_merge([$userId], $studyIds);
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $subassignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($subassignments)) {
        echo "<p class='warning'>⚠️ No se encontraron derivaciones con esos IDs exactos</p>\n";
        echo "<p>Buscando derivaciones similares...</p>\n";
        
        // Buscar derivaciones que contengan esos IDs
        $queryLike = "
            SELECT 
                ss.id,
                ss.study_id,
                ss.main_user_id,
                ss.subassigned_to_user_id,
                ss.assigned_by_user_id,
                ss.subassigned_at,
                ss.status,
                u_main.nombre as main_user_nombre,
                u_main.apellido as main_user_apellido,
                u_main.email as main_user_email
            FROM study_subassignments ss
            LEFT JOIN usuarios u_main ON ss.main_user_id = u_main.id
            WHERE ss.subassigned_to_user_id = ?
            AND (ss.study_id LIKE ? OR ss.study_id LIKE ?)
            ORDER BY ss.subassigned_at DESC
        ";
        
        $stmtLike = $pdo->prepare($queryLike);
        $stmtLike->execute([$userId, "%{$studyIds[0]}%", "%{$studyIds[1]}%"]);
        $subassignments = $stmtLike->fetchAll(PDO::FETCH_ASSOC);
    }
    
    if (!empty($subassignments)) {
        echo "<table>\n";
        echo "<tr>
            <th>ID Subasignación</th>
            <th>Study ID</th>
            <th>Usuario Principal</th>
            <th>Derivado Por</th>
            <th>Fecha Derivación</th>
            <th>Estado</th>
        </tr>\n";
        
        foreach ($subassignments as $sub) {
            echo "<tr>\n";
            echo "<td>{$sub['id']}</td>\n";
            echo "<td><span class='code'>{$sub['study_id']}</span></td>\n";
            echo "<td>{$sub['main_user_nombre']} {$sub['main_user_apellido']}<br><small>{$sub['main_user_email']}</small></td>\n";
            echo "<td>{$sub['assigned_by_nombre']} {$sub['assigned_by_apellido']}<br><small>{$sub['assigned_by_email']}</small></td>\n";
            echo "<td>{$sub['subassigned_at']}</td>\n";
            echo "<td>{$sub['status']}</td>\n";
            echo "</tr>\n";
        }
        
        echo "</table>\n";
        
        // 3. Verificar si estos estudios existen en study_assignments
        echo "<h3>3. Verificación de Asignaciones Originales</h3>\n";
        
        $studyIdsFound = array_column($subassignments, 'study_id');
        $placeholders = str_repeat('?,', count($studyIdsFound) - 1) . '?';
        
        $queryAssignments = "
            SELECT 
                sa.id,
                sa.study_id,
                sa.user_id,
                sa.assigned_by,
                sa.assigned_date,
                sa.status,
                sa.patient_name,
                sa.modality,
                u.nombre as user_nombre,
                u.apellido as user_apellido,
                u.email as user_email
            FROM study_assignments sa
            LEFT JOIN usuarios u ON sa.user_id = u.id
            WHERE sa.study_id IN ($placeholders)
            ORDER BY sa.assigned_date DESC
        ";
        
        $stmtAssignments = $pdo->prepare($queryAssignments);
        $stmtAssignments->execute($studyIdsFound);
        $assignments = $stmtAssignments->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($assignments)) {
            echo "<p class='error'>❌ <strong>PROBLEMA ENCONTRADO:</strong> Estos estudios NO existen en study_assignments</p>\n";
            echo "<p>Esto significa que son estudios huérfanos - fueron derivados pero nunca fueron asignados originalmente.</p>\n";
        } else {
            echo "<p class='success'>✅ Estudios encontrados en study_assignments:</p>\n";
            echo "<table>\n";
            echo "<tr>
                <th>ID Asignación</th>
                <th>Study ID</th>
                <th>Asignado a</th>
                <th>Asignado por</th>
                <th>Fecha</th>
                <th>Paciente</th>
                <th>Modalidad</th>
                <th>Estado</th>
            </tr>\n";
            
            foreach ($assignments as $ass) {
                echo "<tr>\n";
                echo "<td>{$ass['id']}</td>\n";
                echo "<td><span class='code'>{$ass['study_id']}</span></td>\n";
                echo "<td>{$ass['user_nombre']} {$ass['user_apellido']}<br><small>{$ass['user_email']}</small></td>\n";
                echo "<td>{$ass['assigned_by']}</td>\n";
                echo "<td>{$ass['assigned_date']}</td>\n";
                echo "<td>{$ass['patient_name']}</td>\n";
                echo "<td>{$ass['modality']}</td>\n";
                echo "<td>{$ass['status']}</td>\n";
                echo "</tr>\n";
            }
            
            echo "</table>\n";
        }
        
        // 4. Verificar si existen en Orthanc (PACS)
        echo "<h3>4. Verificación en PACS (Orthanc)</h3>\n";
        
        try {
            require_once __DIR__ . '/api/OrthancClient.php';
            $orthancClient = new OrthancClient();
            $serverStatus = $orthancClient->getServerStatus();
            
            if ($serverStatus['status'] === 'connected') {
                echo "<p class='success'>✅ Conexión a Orthanc exitosa</p>\n";
                
                foreach ($studyIdsFound as $studyId) {
                    echo "<h4>Buscando estudio: <span class='code'>$studyId</span></h4>\n";
                    
                    // Intentar buscar por diferentes identificadores
                    $found = false;
                    
                    // Buscar por study_id directo
                    try {
                        $study = $orthancClient->getStudy($studyId);
                        if ($study) {
                            echo "<p class='success'>✅ Encontrado en Orthanc (por study_id)</p>\n";
                            $found = true;
                        }
                    } catch (Exception $e) {
                        // No encontrado por study_id
                    }
                    
                    // Buscar por study_instance_uid si está disponible
                    if (!$found) {
                        // Intentar buscar en la base de datos el study_instance_uid
                        $stmtUID = $pdo->prepare("SELECT study_instance_uid FROM study_assignments WHERE study_id = ? LIMIT 1");
                        $stmtUID->execute([$studyId]);
                        $uidResult = $stmtUID->fetch(PDO::FETCH_ASSOC);
                        
                        if ($uidResult && !empty($uidResult['study_instance_uid'])) {
                            try {
                                $studies = $orthancClient->getStudiesByInstanceUID($uidResult['study_instance_uid']);
                                if (!empty($studies)) {
                                    echo "<p class='success'>✅ Encontrado en Orthanc (por study_instance_uid)</p>\n";
                                    $found = true;
                                }
                            } catch (Exception $e) {
                                // No encontrado
                            }
                        }
                    }
                    
                    if (!$found) {
                        echo "<p class='error'>❌ NO encontrado en Orthanc</p>\n";
                    }
                }
            } else {
                echo "<p class='warning'>⚠️ No se pudo conectar a Orthanc: {$serverStatus['message']}</p>\n";
            }
        } catch (Exception $e) {
            echo "<p class='warning'>⚠️ Error verificando Orthanc: " . htmlspecialchars($e->getMessage()) . "</p>\n";
        }
        
        // 5. Resumen y recomendaciones
        echo "<h3>5. Resumen y Recomendaciones</h3>\n";
        
        $orphanCount = 0;
        foreach ($studyIdsFound as $studyId) {
            $existsInAssignments = false;
            foreach ($assignments as $ass) {
                if ($ass['study_id'] === $studyId) {
                    $existsInAssignments = true;
                    break;
                }
            }
            
            if (!$existsInAssignments) {
                $orphanCount++;
            }
        }
        
        if ($orphanCount > 0) {
            echo "<div style='background-color: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin: 20px 0;'>\n";
            echo "<h4>⚠️ Estudios Huérfanos Detectados</h4>\n";
            echo "<p>Se encontraron <strong>$orphanCount</strong> estudio(s) derivado(s) que no tienen una asignación original válida.</p>\n";
            echo "<p><strong>Posibles causas:</strong></p>\n";
            echo "<ul>\n";
            echo "<li>Datos de ejemplo/prueba insertados manualmente</li>\n";
            echo "<li>Estudios eliminados del PACS pero las derivaciones no se limpiaron</li>\n";
            echo "<li>Error en el proceso de derivación</li>\n";
            echo "<li>Migración de datos incompleta</li>\n";
            echo "</ul>\n";
            echo "<p><strong>Recomendación:</strong> Eliminar estas derivaciones huérfanas de la tabla <span class='code'>study_subassignments</span></p>\n";
            echo "</div>\n";
            
            // Mostrar SQL para eliminar
            echo "<h4>SQL para Eliminar Derivaciones Huérfanas</h4>\n";
            echo "<pre style='background-color: #f4f4f4; padding: 10px; border-radius: 5px;'>\n";
            echo "-- Eliminar derivaciones huérfanas para el usuario\n";
            echo "DELETE FROM study_subassignments\n";
            echo "WHERE subassigned_to_user_id = $userId\n";
            echo "AND study_id IN (";
            $sqlStudyIds = array_map(function($id) use ($pdo) {
                return $pdo->quote($id);
            }, $studyIdsFound);
            echo implode(', ', $sqlStudyIds);
            echo ");\n";
            echo "</pre>\n";
        } else {
            echo "<p class='success'>✅ Todos los estudios tienen asignaciones originales válidas</p>\n";
        }
        
    } else {
        echo "<p class='warning'>⚠️ No se encontraron derivaciones para este usuario con esos IDs</p>\n";
        echo "<p>Buscando todas las derivaciones del usuario...</p>\n";
        
        $queryAll = "
            SELECT 
                ss.id,
                ss.study_id,
                ss.main_user_id,
                ss.subassigned_at,
                ss.status,
                u_main.email as main_user_email
            FROM study_subassignments ss
            LEFT JOIN usuarios u_main ON ss.main_user_id = u_main.id
            WHERE ss.subassigned_to_user_id = ?
            ORDER BY ss.subassigned_at DESC
            LIMIT 20
        ";
        
        $stmtAll = $pdo->prepare($queryAll);
        $stmtAll->execute([$userId]);
        $allSubassignments = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($allSubassignments)) {
            echo "<p>Últimas 20 derivaciones del usuario:</p>\n";
            echo "<table>\n";
            echo "<tr><th>Study ID</th><th>Usuario Principal</th><th>Fecha</th><th>Estado</th></tr>\n";
            foreach ($allSubassignments as $sub) {
                echo "<tr>\n";
                echo "<td><span class='code'>{$sub['study_id']}</span></td>\n";
                echo "<td>{$sub['main_user_email']}</td>\n";
                echo "<td>{$sub['subassigned_at']}</td>\n";
                echo "<td>{$sub['status']}</td>\n";
                echo "</tr>\n";
            }
            echo "</table>\n";
        }
    }
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>\n";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>\n";
}

echo "<hr>\n";
echo "<p><small>Script ejecutado el " . date('Y-m-d H:i:s') . "</small></p>\n";
?>
