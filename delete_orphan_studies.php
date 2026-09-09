<?php
/**
 * Script para eliminar estudios derivados huérfanos
 * Elimina derivaciones que no tienen asignación original válida
 */

require_once __DIR__ . '/config/database.php';

header('Content-Type: text/html; charset=utf-8');

$email = 'luisfajre@iddse.com.ar';
$studyIds = ['2500c434-8f742b4a-1520daf9-5be46fe7-d2adca90', '18648f15-e4add986-447f4172-7c982697-a4090580'];
$dryRun = false; // Cambiar a false para ejecutar la eliminación real

echo "<h2>🔍 Eliminación de Estudios Derivados Huérfanos</h2>\n";
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
    .dry-run { background-color: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin: 20px 0; }
</style>\n";

if ($dryRun) {
    echo "<div class='dry-run'><strong>⚠️ MODO DRY-RUN:</strong> No se eliminarán datos. Cambiar \$dryRun = false para ejecutar la eliminación real.</div>\n";
}

try {
    $pdo = getDBConnection();
    
    if (!$pdo) {
        echo "<p class='error'>❌ Error: No se pudo conectar a la base de datos</p>";
        exit(1);
    }
    
    // Obtener ID del usuario
    $stmt = $pdo->prepare("SELECT id, nombre, apellido, email FROM usuarios WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo "<p class='error'>❌ Usuario no encontrado: $email</p>";
        exit(1);
    }
    
    $userId = $user['id'];
    echo "<p class='success'>✅ Usuario encontrado: {$user['nombre']} {$user['apellido']} (ID: $userId)</p>\n";
    
    // Buscar derivaciones huérfanas (que no tienen asignación original)
    echo "<h3>Buscando Derivaciones Huérfanas</h3>\n";
    
    $placeholders = str_repeat('?,', count($studyIds) - 1) . '?';
    $query = "
        SELECT 
            ss.id,
            ss.study_id,
            ss.main_user_id,
            ss.subassigned_at,
            ss.status,
            u_main.email as main_user_email,
            CASE 
                WHEN sa.id IS NULL THEN 1 
                ELSE 0 
            END as is_orphan
        FROM study_subassignments ss
        LEFT JOIN usuarios u_main ON ss.main_user_id = u_main.id
        LEFT JOIN study_assignments sa ON ss.study_id = sa.study_id AND ss.main_user_id = sa.user_id AND sa.status = 'active'
        WHERE ss.subassigned_to_user_id = ?
        AND ss.study_id IN ($placeholders)
        ORDER BY ss.subassigned_at DESC
    ";
    
    $params = array_merge([$userId], $studyIds);
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $subassignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($subassignments)) {
        echo "<p class='warning'>⚠️ No se encontraron derivaciones con esos IDs</p>\n";
        exit(0);
    }
    
    echo "<table>\n";
    echo "<tr>
        <th>ID Subasignación</th>
        <th>Study ID</th>
        <th>Usuario Principal</th>
        <th>Fecha Derivación</th>
        <th>Estado</th>
        <th>¿Huérfano?</th>
    </tr>\n";
    
    $orphanIds = [];
    foreach ($subassignments as $sub) {
        $isOrphan = (bool)$sub['is_orphan'];
        if ($isOrphan) {
            $orphanIds[] = $sub['id'];
        }
        
        echo "<tr>\n";
        echo "<td>{$sub['id']}</td>\n";
        echo "<td><span class='code'>{$sub['study_id']}</span></td>\n";
        echo "<td>{$sub['main_user_email']}</td>\n";
        echo "<td>{$sub['subassigned_at']}</td>\n";
        echo "<td>{$sub['status']}</td>\n";
        echo "<td>" . ($isOrphan ? "<span class='error'>❌ Sí</span>" : "<span class='success'>✅ No</span>") . "</td>\n";
        echo "</tr>\n";
    }
    
    echo "</table>\n";
    
    if (empty($orphanIds)) {
        echo "<p class='success'>✅ No se encontraron estudios huérfanos. Todos tienen asignaciones originales válidas.</p>\n";
        exit(0);
    }
    
    echo "<h3>Resumen</h3>\n";
    echo "<p>Se encontraron <strong>" . count($orphanIds) . "</strong> derivación(es) huérfana(s) que serán eliminadas:</p>\n";
    echo "<ul>\n";
    foreach ($subassignments as $sub) {
        if ((bool)$sub['is_orphan']) {
            echo "<li>ID {$sub['id']}: <span class='code'>{$sub['study_id']}</span> (derivado el {$sub['subassigned_at']})</li>\n";
        }
    }
    echo "</ul>\n";
    
    // Ejecutar eliminación
    if (!$dryRun) {
        echo "<h3>Ejecutando Eliminación</h3>\n";
        
        $placeholdersDelete = str_repeat('?,', count($orphanIds) - 1) . '?';
        $deleteQuery = "
            DELETE FROM study_subassignments
            WHERE id IN ($placeholdersDelete)
            AND subassigned_to_user_id = ?
        ";
        
        $pdo->beginTransaction();
        
        try {
            $deleteParams = array_merge($orphanIds, [$userId]);
            $deleteStmt = $pdo->prepare($deleteQuery);
            $deleteStmt->execute($deleteParams);
            $deletedCount = $deleteStmt->rowCount();
            
            $pdo->commit();
            
            echo "<p class='success'>✅ Se eliminaron $deletedCount derivación(es) huérfana(s) exitosamente.</p>\n";
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "<p class='error'>❌ Error al eliminar: " . htmlspecialchars($e->getMessage()) . "</p>\n";
        }
    } else {
        echo "<h3>SQL que se ejecutaría:</h3>\n";
        echo "<pre style='background-color: #f4f4f4; padding: 10px; border-radius: 5px;'>\n";
        echo "DELETE FROM study_subassignments\n";
        echo "WHERE id IN (" . implode(', ', $orphanIds) . ")\n";
        echo "AND subassigned_to_user_id = $userId;\n";
        echo "</pre>\n";
    }
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>\n";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>\n";
}

echo "<hr>\n";
echo "<p><small>Script ejecutado el " . date('Y-m-d H:i:s') . "</small></p>\n";
?>
