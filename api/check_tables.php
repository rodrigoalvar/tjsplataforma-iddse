<?php
require_once '../config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "=== VERIFICACIÓN DE TABLAS EN LA BASE DE DATOS ===\n\n";
    
    // Mostrar todas las tablas
    $stmt = $pdo->query('SHOW TABLES');
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Tablas en la base de datos:\n";
    foreach($tables as $table) {
        echo "- $table\n";
        if (strpos($table, 'estud') !== false || strpos($table, 'study') !== false) {
            echo "  (Posible tabla de estudios)\n";
        }
    }
    
    echo "\n=== VERIFICACIÓN DEL ESTUDIO ASIGNADO ===\n";
    
    $studyId = 'a7c86587-0521ee82-d84cf4e2-b7dd30c9-8071af26';
    echo "Study ID asignado: $studyId\n\n";
    
    // Verificar si existe en alguna tabla de estudios
    $possibleTables = ['estudios', 'studies', 'dicom_studies', 'orthanc_studies'];
    
    foreach ($possibleTables as $tableName) {
        if (in_array($tableName, $tables)) {
            echo "Verificando tabla '$tableName':\n";
            try {
                $stmt = $pdo->prepare("SELECT * FROM $tableName WHERE id = ? OR study_id = ? OR orthanc_study_id = ? LIMIT 1");
                $stmt->execute([$studyId, $studyId, $studyId]);
                $study = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($study) {
                    echo "  ✓ Estudio encontrado en tabla '$tableName':\n";
                    foreach ($study as $key => $value) {
                        echo "    $key: $value\n";
                    }
                } else {
                    echo "  ✗ Estudio NO encontrado en tabla '$tableName'\n";
                }
            } catch (Exception $e) {
                echo "  ⚠ Error consultando tabla '$tableName': " . $e->getMessage() . "\n";
            }
            echo "\n";
        }
    }
    
    // Si no hay tabla de estudios, mostrar información disponible
    echo "=== INFORMACIÓN DISPONIBLE PARA EL ESTUDIO ===\n";
    echo "Study ID: $studyId\n";
    echo "Tipo: Parece ser un ID de Orthanc (formato UUID)\n";
    echo "Recomendación: Consultar directamente a Orthanc para obtener información real\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>