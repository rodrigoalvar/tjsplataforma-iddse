<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    $stmt = $pdo->query('SHOW TABLES');
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Tablas disponibles:\n";
    foreach ($tables as $table) {
        echo "- $table\n";
    }
    
    // Verificar si existe tabla de usuarios
    if (in_array('usuarios', $tables)) {
        echo "\n=== USUARIOS ===\n";
        $stmt = $pdo->query('SELECT * FROM usuarios LIMIT 5');
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($users as $user) {
            echo "ID: {$user['id']}, Nombre: {$user['nombre']} {$user['apellido']}\n";
        }
    }
    
    // Verificar tabla de antecedentes
    if (in_array('study_antecedents', $tables)) {
        echo "\n=== ANTECEDENTES ===\n";
        $stmt = $pdo->query('SELECT study_id, notes, created_date FROM study_antecedents LIMIT 10');
        $antecedents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($antecedents as $ant) {
            echo "Study ID: {$ant['study_id']}, Notas: " . (empty($ant['notes']) ? 'NO' : 'SÍ') . ", Fecha: {$ant['created_date']}\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}