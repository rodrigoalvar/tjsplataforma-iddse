<?php
/**
 * Script para actualizar la tabla study_assignments con los campos necesarios
 * para almacenar información completa de estudios
 */

require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "=== AGREGANDO CAMPOS FALTANTES A study_assignments ===\n\n";
    
    $alterQueries = [
        'ALTER TABLE study_assignments ADD COLUMN patient_name VARCHAR(255) DEFAULT NULL AFTER status',
        'ALTER TABLE study_assignments ADD COLUMN patient_id VARCHAR(100) DEFAULT NULL AFTER patient_name',
        'ALTER TABLE study_assignments ADD COLUMN study_date DATE DEFAULT NULL AFTER patient_id',
        'ALTER TABLE study_assignments ADD COLUMN study_time TIME DEFAULT NULL AFTER study_date',
        'ALTER TABLE study_assignments ADD COLUMN modality VARCHAR(50) DEFAULT NULL AFTER study_time',
        'ALTER TABLE study_assignments ADD COLUMN study_description TEXT DEFAULT NULL AFTER modality',
        'ALTER TABLE study_assignments ADD COLUMN accession_number VARCHAR(100) DEFAULT NULL AFTER study_description',
        'ALTER TABLE study_assignments ADD COLUMN referring_physician VARCHAR(255) DEFAULT NULL AFTER accession_number',
        'ALTER TABLE study_assignments ADD COLUMN study_instance_uid VARCHAR(255) DEFAULT NULL AFTER referring_physician',
        'ALTER TABLE study_assignments ADD COLUMN series_count INT DEFAULT 0 AFTER study_instance_uid',
        'ALTER TABLE study_assignments ADD COLUMN instances_count INT DEFAULT 0 AFTER series_count',
        'ALTER TABLE study_assignments ADD COLUMN viewer_url TEXT DEFAULT NULL AFTER instances_count',
        'ALTER TABLE study_assignments ADD COLUMN orthanc_study_id VARCHAR(255) DEFAULT NULL AFTER viewer_url',
        'ALTER TABLE study_assignments ADD COLUMN patient_birth_date VARCHAR(8) DEFAULT NULL AFTER orthanc_study_id',
        'ALTER TABLE study_assignments ADD COLUMN patient_sex VARCHAR(1) DEFAULT NULL AFTER patient_birth_date'
    ];
    
    foreach ($alterQueries as $query) {
        try {
            $pdo->exec($query);
            echo "✓ Ejecutado: " . substr($query, 0, 80) . "...\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
                echo "⚠ Columna ya existe: " . substr($query, 0, 80) . "...\n";
            } else {
                echo "✗ Error: " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "\n=== VERIFICANDO ESTRUCTURA ACTUALIZADA ===\n";
    $stmt = $pdo->query('DESCRIBE study_assignments');
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Columnas en study_assignments:\n";
    foreach ($columns as $column) {
        echo "  - {$column['Field']} ({$column['Type']})\n";
    }
    
    echo "\n✅ Tabla study_assignments actualizada exitosamente!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>