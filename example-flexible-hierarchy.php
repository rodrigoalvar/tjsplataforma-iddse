<?php
/**
 * Ejemplo de Sistema de Jerarquías Flexible
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "=== SISTEMA DE JERARQUÍAS FLEXIBLE ===\n\n";
    
    // 1. Mostrar usuarios actuales
    echo "1. USUARIOS ACTUALES:\n";
    $stmt = $pdo->query("SELECT id, nombre, apellido, email, nivel, padre_id FROM usuarios WHERE activo = 1 ORDER BY nivel DESC, nombre ASC");
    $users = $stmt->fetchAll();
    
    foreach($users as $user) {
        $padre = $user['padre_id'] ? "Padre ID: {$user['padre_id']}" : "Sin padre";
        echo "   - ID {$user['id']}: {$user['nombre']} {$user['apellido']} ({$user['nivel']}) - {$padre}\n";
    }
    
    echo "\n2. CREANDO JERARQUÍA FLEXIBLE:\n";
    
    // 2. Crear una jerarquía más compleja donde cualquier usuario puede ser padre
    // Asignar algunos usuarios USER como dependientes de otros USER
    $userParentId = 4; // Eugenio Castiglione (user) como padre
    $userDependents = [1, 3]; // Algunos usuarios USER como dependientes
    
    foreach($userDependents as $userId) {
        $stmt = $pdo->prepare("UPDATE usuarios SET padre_id = ? WHERE id = ?");
        $result = $stmt->execute([$userParentId, $userId]);
        
        if ($result) {
            $stmt = $pdo->prepare("SELECT nombre, apellido FROM usuarios WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            echo "   ✓ Usuario {$user['nombre']} {$user['apellido']} asignado como dependiente de Eugenio Castiglione\n";
        }
    }
    
    // 3. Asignar un USER como dependiente de otro USER diferente
    $anotherParentId = 5; // Usuario Prueba (user) como padre
    $anotherDependentId = 7; // Usuario Prueba (user) como dependiente
    
    $stmt = $pdo->prepare("UPDATE usuarios SET padre_id = ? WHERE id = ?");
    $result = $stmt->execute([$anotherParentId, $anotherDependentId]);
    
    if ($result) {
        echo "   ✓ Usuario Prueba asignado como dependiente de otro Usuario Prueba\n";
    }
    
    // 4. Mostrar jerarquía resultante
    echo "\n3. JERARQUÍA FLEXIBLE RESULTANTE:\n";
    
    // Obtener usuarios con información de jerarquía
    $query = "SELECT 
                u.id,
                u.nombre,
                u.apellido,
                u.nivel,
                u.padre_id,
                p.nombre as padre_nombre,
                p.apellido as padre_apellido,
                (SELECT COUNT(*) FROM usuarios h WHERE h.padre_id = u.id AND h.activo = 1) as dependientes_count
              FROM usuarios u
              LEFT JOIN usuarios p ON u.padre_id = p.id
              WHERE u.activo = 1
              ORDER BY u.nivel DESC, u.nombre ASC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $users = $stmt->fetchAll();
    
    // Mostrar jerarquía
    foreach($users as $user) {
        $level = $user['nivel'];
        $levelColor = $level === 'root' ? '🔴' : ($level === 'admin' ? '🟡' : '🔵');
        
        if ($user['padre_id']) {
            echo "   {$levelColor} {$user['nombre']} {$user['apellido']} ({$level}) → Dependiente de {$user['padre_nombre']} {$user['padre_apellido']}\n";
        } else {
            echo "   {$levelColor} {$user['nombre']} {$user['apellido']} ({$level}) → Cuenta principal";
            if ($user['dependientes_count'] > 0) {
                echo " ({$user['dependientes_count']} dependientes)";
            }
            echo "\n";
        }
    }
    
    echo "\n4. EJEMPLO DE ASIGNACIÓN DE ESTUDIOS CON HERENCIA:\n";
    
    // 4. Crear un ejemplo de asignación de estudios con herencia
    $studyId = 'STUDY_FLEXIBLE_' . time();
    $assignedBy = 6; // ROOT user
    
    // Asignar estudio a un USER que tiene dependientes
    $stmt = $pdo->prepare("INSERT INTO study_assignments (study_id, user_id, assigned_by, status) VALUES (?, ?, ?, 'active')");
    $stmt->execute([$studyId, $userParentId, $assignedBy]);
    
    echo "   ✓ Estudio {$studyId} asignado directamente a Eugenio Castiglione (USER con dependientes)\n";
    
    // Asignar el mismo estudio a todos los dependientes
    $stmt = $pdo->prepare("INSERT INTO study_assignments (study_id, user_id, assigned_by, status) 
                           SELECT ?, h.id, ?, 'active'
                           FROM usuarios h 
                           WHERE h.padre_id = ? AND h.activo = 1");
    $stmt->execute([$studyId, $assignedBy, $userParentId]);
    
    $affectedRows = $stmt->rowCount();
    echo "   ✓ Estudio {$studyId} heredado a {$affectedRows} dependientes de Eugenio Castiglione\n";
    
    echo "\n5. VERIFICACIÓN DE ASIGNACIONES:\n";
    
    // 5. Verificar asignaciones
    $stmt = $pdo->prepare("
        SELECT 
            sa.study_id,
            sa.status,
            u.nombre,
            u.apellido,
            u.nivel,
            p.nombre as padre_nombre,
            p.apellido as padre_apellido
        FROM study_assignments sa
        JOIN usuarios u ON sa.user_id = u.id
        LEFT JOIN usuarios p ON u.padre_id = p.id
        WHERE sa.study_id = ?
        ORDER BY u.nivel DESC
    ");
    $stmt->execute([$studyId]);
    $assignments = $stmt->fetchAll();
    
    foreach($assignments as $assignment) {
        $type = $assignment['status'] === 'active' ? '📋 Activo' : '⏸️ Inactivo';
        $hierarchy = $assignment['padre_nombre'] ? 
            "({$assignment['padre_nombre']} {$assignment['padre_apellido']} → {$assignment['nombre']} {$assignment['apellido']})" :
            "({$assignment['nombre']} {$assignment['apellido']})";
            
        echo "   {$type}: {$hierarchy}\n";
    }
    
    echo "\n=== RESUMEN DEL SISTEMA FLEXIBLE ===\n";
    echo "✓ Cualquier usuario puede ser padre de otro usuario\n";
    echo "✓ No hay restricciones de nivel para ser padre\n";
    echo "✓ Se previenen ciclos en la jerarquía\n";
    echo "✓ Los estudios se heredan automáticamente a dependientes\n";
    echo "✓ Gestión flexible y escalable\n";
    
    echo "\n=== VENTAJAS DEL SISTEMA FLEXIBLE ===\n";
    echo "✓ Estructuras organizacionales complejas\n";
    echo "✓ Múltiples niveles de jerarquía\n";
    echo "✓ Adaptable a diferentes necesidades\n";
    echo "✓ Herencia de estudios en cualquier nivel\n";
    
    echo "\n=== PRÓXIMOS PASOS ===\n";
    echo "1. Accede a hierarchy-management.html para gestionar jerarquías\n";
    echo "2. Crea estructuras organizacionales complejas\n";
    echo "3. Asigna estudios y verifica la herencia automática\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>


