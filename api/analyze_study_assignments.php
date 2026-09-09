<?php
require_once '../config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "=== ANÁLISIS DE LA TABLA STUDY_ASSIGNMENTS ===\n\n";
    
    // Estructura actual
    $stmt = $pdo->query('DESCRIBE study_assignments');
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Estructura actual de study_assignments:\n";
    foreach($columns as $col) {
        $nullable = $col['Null'] == 'YES' ? 'NULL' : 'NOT NULL';
        $default = $col['Default'] ? " DEFAULT '{$col['Default']}'" : '';
        echo "- {$col['Field']} ({$col['Type']}) - {$nullable}{$default}\n";
    }
    
    echo "\n=== CAMPOS NECESARIOS PARA EL DASHBOARD ===\n";
    echo "Basado en dashboard-unified.html, se necesitan estos campos:\n";
    
    $requiredFields = [
        'patient_name' => 'VARCHAR(200) - Nombre del paciente',
        'patient_id' => 'VARCHAR(100) - ID del paciente en PACS',
        'patient_birth_date' => 'DATE - Fecha de nacimiento',
        'patient_sex' => 'CHAR(1) - Sexo del paciente (M/F)',
        'study_date' => 'DATE - Fecha del estudio',
        'study_time' => 'TIME - Hora del estudio',
        'modality' => 'VARCHAR(10) - Modalidad (CT, MR, etc.)',
        'study_description' => 'TEXT - Descripción del estudio',
        'accession_number' => 'VARCHAR(100) - Número de acceso',
        'referring_physician' => 'VARCHAR(200) - Médico referente',
        'study_instance_uid' => 'VARCHAR(255) - UID único del estudio',
        'series_count' => 'INT - Número de series',
        'instances_count' => 'INT - Número de instancias',
        'viewer_url' => 'TEXT - URL del visor',
        'orthanc_study_id' => 'VARCHAR(100) - ID en Orthanc'
    ];
    
    // Verificar qué campos faltan
    $currentFields = array_column($columns, 'Field');
    $missingFields = [];
    
    foreach ($requiredFields as $field => $description) {
        if (!in_array($field, $currentFields)) {
            $missingFields[] = $field;
            echo "✗ FALTA: $field - $description\n";
        } else {
            echo "✓ EXISTE: $field\n";
        }
    }
    
    echo "\n=== RESUMEN ===\n";
    echo "Campos existentes: " . count($currentFields) . "\n";
    echo "Campos requeridos: " . count($requiredFields) . "\n";
    echo "Campos faltantes: " . count($missingFields) . "\n";
    
    if (!empty($missingFields)) {
        echo "\nCampos que se deben agregar:\n";
        foreach ($missingFields as $field) {
            echo "- $field: {$requiredFields[$field]}\n";
        }
        
        echo "\n=== SCRIPT SQL PARA AGREGAR CAMPOS ===\n";
        echo "ALTER TABLE study_assignments\n";
        foreach ($missingFields as $i => $field) {
            $definition = explode(' - ', $requiredFields[$field])[0];
            $comma = $i < count($missingFields) - 1 ? ',' : ';';
            echo "ADD COLUMN $field $definition$comma\n";
        }
    } else {
        echo "\n✓ Todos los campos necesarios ya existen en la tabla.\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>