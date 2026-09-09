<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "=== ESTUDIOS ASIGNADOS A prueba_curl@test.com ===\n\n";
    
    // Buscar estudios asignados
    $stmt = $pdo->prepare("
        SELECT 
            sa.study_id,
            sa.assigned_date,
            sa.status,
            u.nombre,
            u.apellido
        FROM study_assignments sa
        JOIN usuarios u ON sa.user_id = u.id
        WHERE u.email = 'prueba_curl@test.com'
        ORDER BY sa.assigned_date DESC
    ");
    $stmt->execute();
    $assignments = $stmt->fetchAll();
    
    if ($assignments) {
        echo "Estudios asignados:\n";
        foreach($assignments as $assignment) {
            echo "  - Estudio: {$assignment['study_id']}\n";
            echo "    Fecha: {$assignment['assigned_date']}\n";
            echo "    Estado: {$assignment['status']}\n";
            echo "    Usuario: {$assignment['nombre']} {$assignment['apellido']}\n";
            echo "    ---\n";
        }
        
        echo "\n=== OPCIONES PARA PERMITIR ELIMINACIÓN ===\n";
        echo "1. Eliminar asignaciones de estudios primero\n";
        echo "2. Reasignar estudios a otro usuario\n";
        echo "3. Modificar la lógica para permitir eliminación con estudios\n";
        
        echo "\n¿Qué prefieres hacer?\n";
        echo "a) Eliminar las asignaciones de estudios\n";
        echo "b) Reasignar estudios a otro usuario\n";
        echo "c) Ver todos los usuarios disponibles para reasignación\n";
        
    } else {
        echo "No se encontraron estudios asignados\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>


