<?php
/**
 * Verificación del usuario 'tucuman informes' y sus asignaciones
 */

require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "=== VERIFICACIÓN USUARIO TUCUMAN INFORMES ===\n\n";
    
    // Buscar usuario tucuman informes
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE nombre LIKE ? OR apellido LIKE ? OR CONCAT(nombre, ' ', apellido) LIKE ?");
    $stmt->execute(['%tucuman%', '%tucuman%', '%tucuman%']);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($users)) {
        echo "❌ No se encontró usuario con 'tucuman' en el nombre\n";
        
        // Buscar por 'informes'
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE nombre LIKE ? OR apellido LIKE ? OR CONCAT(nombre, ' ', apellido) LIKE ?");
        $stmt->execute(['%informes%', '%informes%', '%informes%']);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($users)) {
            echo "❌ No se encontró usuario con 'informes' en el nombre\n";
            echo "📋 Listando usuarios con asignaciones:\n";
            
            // Buscar usuarios que tengan asignaciones
            $stmt = $pdo->query("
                SELECT DISTINCT u.id, u.nombre, u.apellido, u.nivel, COUNT(sa.id) as asignaciones
                FROM usuarios u 
                LEFT JOIN study_assignments sa ON u.id = sa.user_id AND sa.status = 'active'
                GROUP BY u.id, u.nombre, u.apellido, u.nivel
                HAVING asignaciones > 0
                ORDER BY asignaciones DESC
            ");
            $usersWithAssignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($usersWithAssignments as $user) {
                echo "   • ID: {$user['id']} - {$user['nombre']} {$user['apellido']} (nivel: {$user['nivel']}) - {$user['asignaciones']} asignaciones\n";
            }
        } else {
            echo "✅ Usuarios encontrados con 'informes':\n";
            foreach ($users as $user) {
                echo "   • ID: {$user['id']} - {$user['nombre']} {$user['apellido']} (nivel: {$user['nivel']})\n";
            }
        }
    } else {
        echo "✅ Usuarios encontrados con 'tucuman':\n";
        foreach ($users as $user) {
            echo "   • ID: {$user['id']} - {$user['nombre']} {$user['apellido']} (nivel: {$user['nivel']})\n";
        }
    }
    
    // Si encontramos usuarios, verificar sus asignaciones
    if (!empty($users)) {
        echo "\n📋 VERIFICANDO ASIGNACIONES:\n";
        foreach ($users as $user) {
            $userId = $user['id'];
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM study_assignments WHERE user_id = ? AND status = 'active'");
            $stmt->execute([$userId]);
            $assignedCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            echo "   • {$user['nombre']} {$user['apellido']} (ID: $userId): $assignedCount estudios asignados\n";
            
            if ($assignedCount > 0) {
                echo "     📚 Detalles de asignaciones:\n";
                $stmt = $pdo->prepare("SELECT study_id, patient_name, study_date, modality, assigned_at FROM study_assignments WHERE user_id = ? AND status = 'active' LIMIT 5");
                $stmt->execute([$userId]);
                $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($assignments as $assignment) {
                    echo "       - Estudio: {$assignment['study_id']} | Paciente: {$assignment['patient_name']} | Fecha: {$assignment['study_date']} | Modalidad: {$assignment['modality']} | Asignado: {$assignment['assigned_at']}\n";
                }
                
                if ($assignedCount > 5) {
                    echo "       ... y " . ($assignedCount - 5) . " más\n";
                }
            }
        }
    }
    
    // Mostrar todos los usuarios con asignaciones si no encontramos el específico
    if (empty($users)) {
        echo "\n🔍 TODOS LOS USUARIOS CON ASIGNACIONES:\n";
        $stmt = $pdo->query("
            SELECT u.id, u.nombre, u.apellido, u.nivel, COUNT(sa.id) as asignaciones
            FROM usuarios u 
            INNER JOIN study_assignments sa ON u.id = sa.user_id AND sa.status = 'active'
            GROUP BY u.id, u.nombre, u.apellido, u.nivel
            ORDER BY asignaciones DESC
        ");
        $allUsersWithAssignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($allUsersWithAssignments as $user) {
            echo "   • ID: {$user['id']} - {$user['nombre']} {$user['apellido']} (nivel: {$user['nivel']}) - {$user['asignaciones']} asignaciones\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n=== FIN DE LA VERIFICACIÓN ===\n";
?>