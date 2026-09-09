<?php
/**
 * Script para verificar el estado de la tabla study_assignments
 */

require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "=== VERIFICACIÓN DE TABLA STUDY_ASSIGNMENTS ===\n\n";
    
    // Verificar si existe la tabla
    $stmt = $pdo->query('SHOW TABLES LIKE "study_assignments"');
    $tableExists = $stmt->rowCount() > 0;
    
    echo "Tabla study_assignments: " . ($tableExists ? "✓ Existe" : "✗ No existe") . "\n";
    
    if ($tableExists) {
        // Contar registros
        $stmt = $pdo->query('SELECT COUNT(*) as total FROM study_assignments');
        $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        echo "Total de registros: $total\n";
        
        // Contar registros activos
        $stmt = $pdo->query('SELECT COUNT(*) as active FROM study_assignments WHERE status = "active"');
        $active = $stmt->fetch(PDO::FETCH_ASSOC)['active'];
        echo "Registros activos: $active\n";
        
        // Mostrar estructura de la tabla
        echo "\nEstructura de la tabla:\n";
        $stmt = $pdo->query('DESCRIBE study_assignments');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "- {$row['Field']} ({$row['Type']}) " . ($row['Null'] == 'YES' ? 'NULL' : 'NOT NULL') . "\n";
        }
        
        // Mostrar algunos ejemplos
        if ($active > 0) {
            echo "\nEjemplos de asignaciones activas:\n";
            $stmt = $pdo->query('SELECT user_id, study_id, assigned_date, patient_name FROM study_assignments WHERE status = "active" LIMIT 5');
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                echo "- Usuario {$row['user_id']} -> Estudio {$row['study_id']} ({$row['assigned_date']}) - {$row['patient_name']}\n";
            }
        } else {
            echo "\n⚠️ PROBLEMA IDENTIFICADO: No hay asignaciones activas en la tabla\n";
            echo "Esto explica por qué los usuarios sin PACS QUERY no ven estudios.\n";
        }
        
        // Verificar usuarios que deberían tener asignaciones
        echo "\nVerificando usuarios en el sistema:\n";
        $stmt = $pdo->query('SELECT id, nombre, apellido, nivel FROM usuarios WHERE nivel = "user" LIMIT 3');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $userId = $row['id'];
            $stmtAssign = $pdo->prepare('SELECT COUNT(*) as count FROM study_assignments WHERE user_id = ? AND status = "active"');
            $stmtAssign->execute([$userId]);
            $assignedCount = $stmtAssign->fetch(PDO::FETCH_ASSOC)['count'];
            
            echo "- {$row['nombre']} {$row['apellido']} (ID: {$userId}, Nivel: {$row['nivel']}) -> {$assignedCount} estudios asignados\n";
        }
        
    } else {
        echo "\n❌ PROBLEMA CRÍTICO: La tabla study_assignments no existe\n";
        echo "Esto explica completamente por qué los usuarios sin PACS QUERY no ven estudios.\n";
        echo "\nSolución: Crear la tabla study_assignments con la estructura correcta.\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n=== FIN DE VERIFICACIÓN ===\n";
?>