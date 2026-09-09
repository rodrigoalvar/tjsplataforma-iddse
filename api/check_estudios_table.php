<?php
require_once '../config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "=== ESTRUCTURA DE LA TABLA ESTUDIOS ===\n\n";
    
    $stmt = $pdo->query('DESCRIBE estudios');
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Columnas de la tabla estudios:\n";
    foreach($columns as $col) {
        echo "- {$col['Field']} ({$col['Type']})\n";
    }
    
    echo "\n=== BÚSQUEDA DEL ESTUDIO ASIGNADO ===\n";
    
    $studyId = 'a7c86587-0521ee82-d84cf4e2-b7dd30c9-8071af26';
    echo "Buscando Study ID: $studyId\n\n";
    
    // Intentar buscar por diferentes columnas posibles
    $possibleColumns = ['id', 'orthanc_study_id', 'study_instance_uid'];
    
    foreach ($possibleColumns as $column) {
        // Verificar si la columna existe
        $columnExists = false;
        foreach ($columns as $col) {
            if ($col['Field'] === $column) {
                $columnExists = true;
                break;
            }
        }
        
        if ($columnExists) {
            echo "Buscando en columna '$column':\n";
            try {
                $stmt = $pdo->prepare("SELECT * FROM estudios WHERE $column = ? LIMIT 1");
                $stmt->execute([$studyId]);
                $study = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($study) {
                    echo "  ✓ Estudio encontrado:\n";
                    foreach ($study as $key => $value) {
                        $displayValue = strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value;
                        echo "    $key: $displayValue\n";
                    }
                    echo "\n";
                    break;
                } else {
                    echo "  ✗ No encontrado en columna '$column'\n";
                }
            } catch (Exception $e) {
                echo "  ⚠ Error: " . $e->getMessage() . "\n";
            }
        } else {
            echo "Columna '$column' no existe\n";
        }
    }
    
    // Mostrar algunos estudios de ejemplo
    echo "\n=== ESTUDIOS DE EJEMPLO EN LA TABLA ===\n";
    $stmt = $pdo->query('SELECT * FROM estudios LIMIT 3');
    $examples = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($examples)) {
        echo "No hay estudios en la tabla.\n";
    } else {
        foreach ($examples as $index => $study) {
            echo "Estudio " . ($index + 1) . ":\n";
            foreach ($study as $key => $value) {
                $displayValue = strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value;
                echo "  $key: $displayValue\n";
            }
            echo "\n";
        }
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>