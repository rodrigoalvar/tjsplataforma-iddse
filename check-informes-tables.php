<?php
/**
 * Script para verificar las tablas de informes en la base de datos
 */

require_once 'config/database.php';

try {
    $db = getDBConnection();
    
    echo "=== VERIFICACIÓN DE TABLAS DE INFORMES ===\n\n";
    
    // Mostrar todas las tablas que contengan 'informe' en el nombre
    $query = "SHOW TABLES LIKE '%informe%'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "🔍 TABLAS ENCONTRADAS CON 'informe':\n";
    foreach ($tables as $table) {
        echo "   • $table\n";
    }
    echo "\n";
    
    // Verificar cada tabla encontrada
    foreach ($tables as $table) {
        echo "📋 TABLA: $table\n";
        
        // Contar registros
        $countQuery = "SELECT COUNT(*) as total FROM `$table`";
        $countStmt = $db->prepare($countQuery);
        $countStmt->execute();
        $count = $countStmt->fetch()['total'];
        
        echo "   Registros: $count\n";
        
        // Mostrar estructura si tiene registros
        if ($count > 0) {
            $structureQuery = "DESCRIBE `$table`";
            $structureStmt = $db->prepare($structureQuery);
            $structureStmt->execute();
            $columns = $structureStmt->fetchAll();
            
            echo "   Columnas:\n";
            foreach ($columns as $column) {
                echo "     • {$column['Field']} ({$column['Type']})\n";
            }
            
            // Mostrar algunos registros de ejemplo
            $sampleQuery = "SELECT * FROM `$table` LIMIT 3";
            $sampleStmt = $db->prepare($sampleQuery);
            $sampleStmt->execute();
            $samples = $sampleStmt->fetchAll();
            
            echo "   Muestra de registros:\n";
            foreach ($samples as $i => $sample) {
                echo "     Registro " . ($i + 1) . ":\n";
                foreach ($sample as $key => $value) {
                    if (!is_numeric($key)) {
                        $displayValue = is_string($value) && strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value;
                        echo "       $key: $displayValue\n";
                    }
                }
            }
        }
        echo "\n";
    }
    
    // Verificar también tablas con 'audios'
    echo "🔍 TABLAS CON 'audios':\n";
    $query = "SHOW TABLES LIKE '%audio%'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $audioTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($audioTables as $table) {
        echo "   • $table\n";
        
        $countQuery = "SELECT COUNT(*) as total FROM `$table`";
        $countStmt = $db->prepare($countQuery);
        $countStmt->execute();
        $count = $countStmt->fetch()['total'];
        echo "     Registros: $count\n";
    }
    echo "\n";
    
    // Verificar también tablas con 'historial'
    echo "🔍 TABLAS CON 'historial':\n";
    $query = "SHOW TABLES LIKE '%historial%'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $historialTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($historialTables as $table) {
        echo "   • $table\n";
        
        $countQuery = "SELECT COUNT(*) as total FROM `$table`";
        $countStmt = $db->prepare($countQuery);
        $countStmt->execute();
        $count = $countStmt->fetch()['total'];
        echo "     Registros: $count\n";
    }
    echo "\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}
?>
