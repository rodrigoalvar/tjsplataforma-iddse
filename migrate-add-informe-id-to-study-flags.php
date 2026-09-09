<?php
/**
 * Script de migración para agregar campo informe_id a study_flags
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Este script agrega el campo informe_id a la tabla study_flags
 * para permitir flags individuales por informe
 */

require_once __DIR__ . '/config/database.php';

$db = getDBConnection();

if (!$db) {
    die("❌ Error: No se pudo conectar a la base de datos.\n");
}

echo "🚀 Migrando tabla study_flags para soportar flags por informe individual...\n\n";

try {
    // Verificar si la columna ya existe
    $checkColumn = $db->query("SHOW COLUMNS FROM study_flags LIKE 'informe_id'");
    if ($checkColumn->rowCount() > 0) {
        echo "✅ La columna informe_id ya existe en study_flags.\n";
        exit(0);
    }
    
    // Agregar columna informe_id
    echo "📋 Agregando columna informe_id a study_flags...\n";
    
    $alterTableSQL = "
    ALTER TABLE study_flags 
    ADD COLUMN informe_id INT NULL COMMENT 'ID del informe específico (NULL si aplica a todo el estudio)',
    ADD INDEX idx_informe_id (informe_id),
    ADD UNIQUE KEY unique_study_user_informe (study_id, user_id, informe_id)
    ";
    
    $db->exec($alterTableSQL);
    
    echo "✅ Columna informe_id agregada exitosamente.\n";
    echo "✅ Índice idx_informe_id creado.\n";
    echo "✅ Constraint único unique_study_user_informe creado.\n\n";
    
    echo "✅ Migración completada exitosamente.\n";
    
} catch (PDOException $e) {
    echo "❌ Error durante la migración: " . $e->getMessage() . "\n";
    exit(1);
}
?>
