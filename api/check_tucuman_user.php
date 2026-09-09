<?php
require_once '../config/database.php';

try {
    $pdo = getDBConnection();

    // Buscar usuario TUCUMAN INFORMANTES
    $stmt = $pdo->prepare('SELECT id, nombre, apellido, usuario FROM usuarios WHERE usuario LIKE ? OR nombre LIKE ? OR apellido LIKE ?');
    $stmt->execute(['%TUCUMAN%', '%TUCUMAN%', '%TUCUMAN%']);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Usuarios encontrados con TUCUMAN:\n";
    foreach ($users as $user) {
        echo "ID: {$user['id']}, Usuario: {$user['usuario']}, Nombre: {$user['nombre']} {$user['apellido']}\n";
        
        // Verificar asignaciones para este usuario
        $stmtAssign = $pdo->prepare('SELECT COUNT(*) as count FROM study_assignments WHERE user_id = ? AND status = "active"');
        $stmtAssign->execute([$user['id']]);
        $assignCount = $stmtAssign->fetch(PDO::FETCH_ASSOC);
        echo "  - Estudios asignados: {$assignCount['count']}\n";
        
        // Mostrar detalles de asignaciones si existen
        if ($assignCount['count'] > 0) {
            $stmtDetails = $pdo->prepare('SELECT study_id, assigned_date FROM study_assignments WHERE user_id = ? AND status = "active"');
            $stmtDetails->execute([$user['id']]);
            $assignments = $stmtDetails->fetchAll(PDO::FETCH_ASSOC);
            
            echo "  - Detalles de asignaciones:\n";
            foreach ($assignments as $assignment) {
                echo "    * Study ID: {$assignment['study_id']}, Fecha: {$assignment['assigned_date']}\n";
            }
        }
    }
    
    // Verificar si existe la tabla study_assignments
    $stmt = $pdo->query("SHOW TABLES LIKE 'study_assignments'");
    $tableExists = $stmt->fetch();
    
    if ($tableExists) {
        echo "\nTabla study_assignments existe.\n";
        
        // Contar total de asignaciones activas
        $stmt = $pdo->query('SELECT COUNT(*) as total FROM study_assignments WHERE status = "active"');
        $total = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "Total de asignaciones activas: {$total['total']}\n";
    } else {
        echo "\nTabla study_assignments NO existe.\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>