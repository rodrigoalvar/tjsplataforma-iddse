<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "=== ESTADO ACTUAL DE JERARQUÍAS ===\n";
    
    // Verificar usuarios actuales
    $stmt = $pdo->query("SELECT id, nombre, apellido, email, nivel, padre_id FROM usuarios WHERE activo = 1 ORDER BY nivel DESC, nombre ASC");
    $users = $stmt->fetchAll();
    
    echo "Usuarios actuales:\n";
    foreach($users as $user) {
        $padre = $user['padre_id'] ? "Padre ID: {$user['padre_id']}" : "Sin padre";
        echo "- ID {$user['id']}: {$user['nombre']} {$user['apellido']} ({$user['nivel']}) - {$padre}\n";
    }
    
    // Verificar si existe tabla de asignaciones
    $stmt = $pdo->query("SHOW TABLES LIKE 'study_assignments'");
    $tableExists = $stmt->fetch();
    
    echo "\nTabla study_assignments: " . ($tableExists ? "✓ Existe" : "✗ No existe") . "\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>


