<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    // Ver tablas disponibles
    $stmt = $pdo->query('SHOW TABLES');
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Tablas disponibles:\n";
    foreach ($tables as $table) {
        echo "- " . $table . "\n";
    }
    
    // Ver antecedentes disponibles
    $stmt = $pdo->query('SELECT study_id, COUNT(*) as count FROM study_antecedents GROUP BY study_id LIMIT 5');
    $antecedents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "\nAntecedentes disponibles:\n";
    foreach ($antecedents as $ant) {
        echo "Study ID: " . $ant['study_id'] . ", Count: " . $ant['count'] . "\n";
    }
    
    // Ver estructura de tabla study_antecedents
    $stmt = $pdo->query('DESCRIBE study_antecedents');
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "\nEstructura de study_antecedents:\n";
    foreach ($columns as $col) {
        echo $col['Field'] . " - " . $col['Type'] . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
