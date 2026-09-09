<?php
/**
 * Verificar datos reales en la base de datos
 */

require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "=== USUARIOS REALES EN LA BASE DE DATOS ===\n";
    
    // Verificar estructura de la tabla
    $stmt = $pdo->query("DESCRIBE usuarios");
    $columns = $stmt->fetchAll();
    echo "Columnas en tabla usuarios:\n";
    foreach ($columns as $column) {
        echo "- " . $column['Field'] . " (" . $column['Type'] . ")\n";
    }
    echo "\n";
    
    // Obtener usuarios reales
    $stmt = $pdo->query("SELECT id, nombre, apellido, email, nivel, activo FROM usuarios ORDER BY id");
    $users = $stmt->fetchAll();
    
    echo "Total usuarios encontrados: " . count($users) . "\n\n";
    
    foreach ($users as $user) {
        echo "ID: " . $user['id'] . "\n";
        echo "Nombre: " . $user['nombre'] . " " . $user['apellido'] . "\n";
        echo "Email: " . $user['email'] . "\n";
        echo "Nivel: " . ($user['nivel'] ?? 'NULL') . "\n";
        echo "Activo: " . ($user['activo'] ?? 'NULL') . "\n";
        echo "---\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>


