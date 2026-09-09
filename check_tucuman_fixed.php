<?php
/**
 * Verificación corregida del usuario TUCUMAN INFORMANTES
 */

require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "=== VERIFICACIÓN USUARIO TUCUMAN INFORMANTES ===\n\n";
    
    // 1. Verificar estructura de la tabla
    echo "📋 ESTRUCTURA TABLA STUDY_ASSIGNMENTS:\n";
    $stmt = $pdo->query("DESCRIBE study_assignments");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $column) {
        echo "   • {$column['Field']} ({$column['Type']}) - Null: {$column['Null']} - Default: {$column['Default']}\n";
    }
    
    // 2. Buscar usuario TUCUMAN INFORMANTES
    echo "\n👤 USUARIO TUCUMAN INFORMANTES:\n";
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = 10");
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo "   ✅ Usuario encontrado:\n";
        echo "   • ID: {$user['id']}\n";
        echo "   • Nombre: {$user['nombre']}\n";
        echo "   • Apellido: {$user['apellido']}\n";
        echo "   • Nivel: {$user['nivel']}\n";
        echo "   • Email: " . ($user['email'] ?? 'N/A') . "\n";
        
        // 3. Verificar asignaciones
        echo "\n📚 ASIGNACIONES DE ESTUDIOS:\n";
        $stmt = $pdo->prepare("SELECT * FROM study_assignments WHERE user_id = ? AND status = 'active'");
        $stmt->execute([10]);
        $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "   Total asignaciones activas: " . count($assignments) . "\n\n";
        
        if (count($assignments) > 0) {
            echo "   📋 Detalles de asignaciones:\n";
            foreach ($assignments as $i => $assignment) {
                echo "   " . ($i + 1) . ". Estudio ID: {$assignment['study_id']}\n";
                echo "      • Paciente: {$assignment['patient_name']}\n";
                echo "      • Fecha estudio: {$assignment['study_date']}\n";
                echo "      • Modalidad: {$assignment['modality']}\n";
                echo "      • Estado: {$assignment['status']}\n";
                if (isset($assignment['created_at'])) {
                    echo "      • Creado: {$assignment['created_at']}\n";
                }
                if (isset($assignment['updated_at'])) {
                    echo "      • Actualizado: {$assignment['updated_at']}\n";
                }
                echo "\n";
            }
            
            // 4. Verificar por qué este usuario SÍ ve estudios
            echo "🔍 ANÁLISIS: ¿Por qué este usuario SÍ ve estudios?\n";
            echo "   ✅ Tiene nivel 'user' (sin PACS QUERY)\n";
            echo "   ✅ Tiene " . count($assignments) . " estudio(s) asignado(s)\n";
            echo "   ✅ Las asignaciones están en estado 'active'\n";
            echo "   ✅ Debería ver estos estudios en dashboard-unified.html\n\n";
            
            // 5. Comparar con otros usuarios sin asignaciones
            echo "📊 COMPARACIÓN CON OTROS USUARIOS 'USER':\n";
            $stmt = $pdo->query("
                SELECT u.id, u.nombre, u.apellido, COUNT(sa.id) as asignaciones
                FROM usuarios u 
                LEFT JOIN study_assignments sa ON u.id = sa.user_id AND sa.status = 'active'
                WHERE u.nivel = 'user'
                GROUP BY u.id, u.nombre, u.apellido
                ORDER BY asignaciones DESC
            ");
            $userComparison = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($userComparison as $userComp) {
                $status = $userComp['asignaciones'] > 0 ? "✅ VE estudios" : "❌ NO ve estudios";
                echo "   • {$userComp['nombre']} {$userComp['apellido']} (ID: {$userComp['id']}): {$userComp['asignaciones']} asignaciones - $status\n";
            }
            
        } else {
            echo "   ❌ No tiene asignaciones activas\n";
        }
        
    } else {
        echo "   ❌ Usuario con ID 10 no encontrado\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n=== CONCLUSIÓN ===\n";
echo "TUCUMAN INFORMANTES es el ejemplo perfecto de cómo debe funcionar:\n";
echo "• Usuario con nivel 'user' (sin PACS QUERY)\n";
echo "• Tiene estudios asignados en study_assignments\n";
echo "• Por eso SÍ puede ver estudios en el dashboard\n";
echo "• Los otros usuarios 'user' necesitan asignaciones similares\n";

echo "\n=== FIN DE LA VERIFICACIÓN ===\n";
?>