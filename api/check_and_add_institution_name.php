<?php
/**
 * Script para verificar y agregar el campo institution_name a study_assignments
 */

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "=== VERIFICANDO CAMPO institution_name EN study_assignments ===\n\n";
    
    // Verificar si la tabla existe
    $checkTable = $pdo->query("SHOW TABLES LIKE 'study_assignments'");
    if ($checkTable->rowCount() == 0) {
        echo "❌ La tabla study_assignments no existe.\n";
        exit(1);
    }
    
    echo "✅ La tabla study_assignments existe.\n\n";
    
    // Verificar si el campo existe
    $checkColumn = $pdo->query("SHOW COLUMNS FROM study_assignments LIKE 'institution_name'");
    if ($checkColumn->rowCount() == 0) {
        echo "⚠️ El campo institution_name NO existe. Agregándolo...\n";
        
        // Agregar el campo
        $pdo->exec("ALTER TABLE study_assignments ADD COLUMN institution_name VARCHAR(255) DEFAULT NULL AFTER patient_sex");
        
        echo "✅ Campo institution_name agregado exitosamente.\n\n";
    } else {
        echo "✅ El campo institution_name ya existe.\n\n";
    }
    
    // Mostrar estructura actual
    echo "=== ESTRUCTURA ACTUAL DE study_assignments ===\n";
    $stmt = $pdo->query("DESCRIBE study_assignments");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($columns as $col) {
        $nullable = $col['Null'] == 'YES' ? 'NULL' : 'NOT NULL';
        $default = $col['Default'] !== null ? " DEFAULT '{$col['Default']}'" : '';
        echo "- {$col['Field']} ({$col['Type']}) - {$nullable}{$default}\n";
    }
    
    // Verificar si hay datos con institution_name
    echo "\n=== VERIFICANDO DATOS ===\n";
    $countStmt = $pdo->query("SELECT COUNT(*) as total, COUNT(institution_name) as con_institucion FROM study_assignments");
    $count = $countStmt->fetch(PDO::FETCH_ASSOC);
    
    echo "Total de registros: {$count['total']}\n";
    echo "Registros con institution_name: {$count['con_institucion']}\n";
    echo "Registros sin institution_name: " . ($count['total'] - $count['con_institucion']) . "\n";
    
    // Mostrar algunos ejemplos
    if ($count['con_institucion'] > 0) {
        echo "\n=== EJEMPLOS DE REGISTROS CON institution_name ===\n";
        $examples = $pdo->query("SELECT study_id, patient_name, institution_name FROM study_assignments WHERE institution_name IS NOT NULL LIMIT 5");
        $rows = $examples->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            echo "- Study ID: {$row['study_id']}, Paciente: {$row['patient_name']}, Institución: {$row['institution_name']}\n";
        }
    }
    
    echo "\n✅ Verificación completada.\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>

