<?php
require_once '../config/database.php';

try {
    $pdo = getDBConnection();
    $userId = 10; // TUCUMAN INFORMANTES

    echo "=== VERIFICACIÓN DE ASIGNACIONES PARA USUARIO ID: $userId ===\n\n";

    // 1. Verificar asignaciones en study_assignments
    echo "1. Asignaciones en study_assignments:\n";
    $stmt = $pdo->prepare('SELECT * FROM study_assignments WHERE user_id = ? AND status = "active"');
    $stmt->execute([$userId]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($assignments)) {
        echo "   No hay asignaciones activas para este usuario.\n";
    } else {
        foreach($assignments as $a) {
            echo "   - Study ID: {$a['study_id']}\n";
            echo "     Fecha asignación: {$a['assigned_date']}\n";
            echo "     Asignado por: {$a['assigned_by']}\n";
            echo "     Estado: {$a['status']}\n\n";
        }
    }

    // 2. Simular la consulta exacta del API
    echo "2. Consulta exacta del API:\n";
    $query = "
        SELECT 
            sa.id as assignment_id,
            sa.study_id,
            sa.assigned_date,
            sa.assigned_by,
            sa.status as assignment_status,
            -- Información de antecedentes
            a.notes as antecedents_notes,
            a.created_date as antecedents_created_at,
            a.updated_date as antecedents_updated_at,
            -- Información del usuario que asignó
            ua.nombre as assigned_by_name,
            ua.apellido as assigned_by_surname
        FROM study_assignments sa
        LEFT JOIN study_antecedents a ON sa.study_id = a.study_id
        LEFT JOIN usuarios ua ON sa.assigned_by = ua.id
        WHERE sa.user_id = ? 
        AND sa.status = 'active'
        ORDER BY sa.assigned_date DESC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$userId]);
    $apiResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($apiResults)) {
        echo "   La consulta del API no devuelve resultados.\n";
        echo "   Esto explica por qué se muestran datos de demostración.\n\n";
        
        // Verificar si existe la tabla study_antecedents
        echo "3. Verificando tabla study_antecedents:\n";
        $stmt = $pdo->query("SHOW TABLES LIKE 'study_antecedents'");
        $tableExists = $stmt->fetch();
        
        if ($tableExists) {
            echo "   Tabla study_antecedents existe.\n";
            $stmt = $pdo->query('SELECT COUNT(*) as count FROM study_antecedents');
            $count = $stmt->fetch(PDO::FETCH_ASSOC);
            echo "   Registros en study_antecedents: {$count['count']}\n";
        } else {
            echo "   Tabla study_antecedents NO existe.\n";
        }
    } else {
        echo "   Resultados de la consulta del API:\n";
        foreach($apiResults as $result) {
            echo "   - Assignment ID: {$result['assignment_id']}\n";
            echo "     Study ID: {$result['study_id']}\n";
            echo "     Fecha: {$result['assigned_date']}\n";
            echo "     Asignado por: {$result['assigned_by_name']} {$result['assigned_by_surname']}\n\n";
        }
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>