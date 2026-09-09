<?php
require_once '../config/database.php';

try {
    $pdo = getDBConnection();

    // Verificar estructura de la tabla usuarios
    echo "Estructura de la tabla usuarios:\n";
    $stmt = $pdo->query('DESCRIBE usuarios');
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach($columns as $col) {
        echo "- {$col['Field']} ({$col['Type']})\n";
    }
    
    echo "\n";
    
    // Buscar usuarios que contengan TUCUMAN en cualquier campo de texto
    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE nombre LIKE ? OR apellido LIKE ? OR email LIKE ?');
    $stmt->execute(['%TUCUMAN%', '%TUCUMAN%', '%TUCUMAN%']);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Usuarios encontrados con TUCUMAN:\n";
    if (empty($users)) {
        echo "No se encontraron usuarios con TUCUMAN.\n";
        
        // Mostrar algunos usuarios de ejemplo
        echo "\nPrimeros 5 usuarios en la tabla:\n";
        $stmt = $pdo->query('SELECT * FROM usuarios LIMIT 5');
        $sampleUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($sampleUsers as $user) {
            echo "ID: {$user['id']}, Nombre: {$user['nombre']} {$user['apellido']}, Email: {$user['email']}\n";
        }
    } else {
        foreach ($users as $user) {
            echo "ID: {$user['id']}, Nombre: {$user['nombre']} {$user['apellido']}, Email: {$user['email']}\n";
            
            // Verificar asignaciones para este usuario
            $stmtAssign = $pdo->prepare('SELECT COUNT(*) as count FROM study_assignments WHERE user_id = ? AND status = "active"');
            $stmtAssign->execute([$user['id']]);
            $assignCount = $stmtAssign->fetch(PDO::FETCH_ASSOC);
            echo "  - Estudios asignados: {$assignCount['count']}\n";
        }
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>