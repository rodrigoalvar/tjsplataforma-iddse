<?php
/**
 * Ejemplo práctico de asignación de jerarquías
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "=== EJEMPLO PRÁCTICO DE JERARQUÍAS ===\n\n";
    
    // 1. Mostrar usuarios actuales
    echo "1. USUARIOS ACTUALES:\n";
    $stmt = $pdo->query("SELECT id, nombre, apellido, email, nivel, padre_id FROM usuarios WHERE activo = 1 ORDER BY nivel DESC, nombre ASC");
    $users = $stmt->fetchAll();
    
    foreach($users as $user) {
        $padre = $user['padre_id'] ? "Padre ID: {$user['padre_id']}" : "Sin padre";
        echo "   - ID {$user['id']}: {$user['nombre']} {$user['apellido']} ({$user['nivel']}) - {$padre}\n";
    }
    
    echo "\n2. CREANDO JERARQUÍA DE EJEMPLO:\n";
    
    // 2. Asignar algunos usuarios USER como dependientes del ADMIN
    $adminId = 2; // Rodrigo Alvar (admin)
    $userIds = [1, 3, 5]; // Algunos usuarios USER
    
    foreach($userIds as $userId) {
        $stmt = $pdo->prepare("UPDATE usuarios SET padre_id = ? WHERE id = ?");
        $result = $stmt->execute([$adminId, $userId]);
        
        if ($result) {
            $stmt = $pdo->prepare("SELECT nombre, apellido FROM usuarios WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            echo "   ✓ Usuario {$user['nombre']} {$user['apellido']} asignado como dependiente del ADMIN\n";
        }
    }
    
    // 3. Mostrar jerarquía resultante
    echo "\n3. JERARQUÍA RESULTANTE:\n";
    
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
    
    echo "\n4. EJEMPLO DE ASIGNACIÓN DE ESTUDIOS:\n";
    
    // 4. Crear un ejemplo de asignación de estudios
    $studyId = 'STUDY_' . time();
    $assignedBy = 6; // ROOT user
    
    // Asignar estudio al ADMIN
    $stmt = $pdo->prepare("INSERT INTO study_assignments (study_id, user_id, assigned_by, status) VALUES (?, ?, ?, 'active')");
    $stmt->execute([$studyId, $adminId, $assignedBy]);
    
    echo "   ✓ Estudio {$studyId} asignado directamente al ADMIN (ID: {$adminId})\n";
    
    // Asignar el mismo estudio a todos los dependientes del ADMIN
    $stmt = $pdo->prepare("INSERT INTO study_assignments (study_id, user_id, assigned_by, status) 
                           SELECT ?, h.id, ?, 'active'
                           FROM usuarios h 
                           WHERE h.padre_id = ? AND h.activo = 1");
    $stmt->execute([$studyId, $assignedBy, $adminId]);
    
    $affectedRows = $stmt->rowCount();
    echo "   ✓ Estudio {$studyId} heredado a {$affectedRows} dependientes del ADMIN\n";
    
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
    
    echo "\n=== RESUMEN DEL SISTEMA ===\n";
    echo "✓ Cuentas principales: ROOT y ADMIN pueden tener dependientes\n";
    echo "✓ Cuentas dependientes: USER asignados a cuentas principales\n";
    echo "✓ Herencia de estudios: Los estudios asignados a una cuenta principal se heredan a sus dependientes\n";
    echo "✓ Gestión: Solo ROOT y ADMIN pueden asignar jerarquías\n";
    echo "✓ Registro: Los usuarios se registran como USER por defecto\n";
    
    echo "\n=== PRÓXIMOS PASOS ===\n";
    echo "1. Accede a hierarchy-management.html para gestionar jerarquías\n";
    echo "2. Usa la interfaz para asignar dependientes\n";
    echo "3. Los estudios asignados se heredarán automáticamente\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
