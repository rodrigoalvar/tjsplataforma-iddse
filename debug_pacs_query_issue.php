<?php
/**
 * Script de diagnóstico completo para el problema de usuarios sin PACS QUERY
 */

require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "=== DIAGNÓSTICO COMPLETO: USUARIOS SIN PACS QUERY ===\n\n";
    
    // 1. Verificar usuarios tipo 'user' (sin PACS QUERY)
    echo "1. 👥 USUARIOS TIPO 'USER' (SIN PACS QUERY):\n";
    $stmt = $pdo->query("SELECT id, nombre, apellido, nivel FROM usuarios WHERE nivel = 'user'");
    $userUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($userUsers)) {
        echo "   ❌ No hay usuarios con nivel 'user' en el sistema\n";
        echo "   💡 Crear un usuario de prueba con nivel 'user'\n\n";
    } else {
        foreach ($userUsers as $user) {
            echo "   • {$user['nombre']} {$user['apellido']} (ID: {$user['id']})\n";
        }
        echo "\n";
    }
    
    // 2. Verificar tabla study_assignments
    echo "2. 📋 TABLA STUDY_ASSIGNMENTS:\n";
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM study_assignments WHERE status = 'active'");
    $totalAssignments = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    echo "   Total asignaciones activas: $totalAssignments\n";
    
    if ($totalAssignments == 0) {
        echo "   ❌ PROBLEMA: No hay asignaciones activas\n";
        echo "   💡 Los usuarios sin PACS QUERY necesitan estudios asignados\n\n";
    } else {
        echo "   ✅ Hay asignaciones en la tabla\n\n";
    }
    
    // 3. Verificar asignaciones por usuario
    echo "3. 🔍 ASIGNACIONES POR USUARIO:\n";
    foreach ($userUsers as $user) {
        $userId = $user['id'];
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM study_assignments WHERE user_id = ? AND status = 'active'");
        $stmt->execute([$userId]);
        $assignedCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        echo "   • {$user['nombre']} {$user['apellido']} (ID: $userId): $assignedCount estudios asignados\n";
        
        if ($assignedCount == 0) {
            echo "     ❌ Este usuario NO verá estudios en dashboard-unified\n";
        } else {
            echo "     ✅ Este usuario debería ver $assignedCount estudios\n";
        }
    }
    echo "\n";
    
    // 4. Probar API directamente
    echo "4. 🧪 PRUEBA DE API get_user_assigned_studies_fixed.php:\n";
    
    // Simular llamada sin sesión (modo demo)
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://localhost:8080/api/get_user_assigned_studies_fixed.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "   HTTP Status: $httpCode\n";
    
    if ($response) {
        $data = json_decode($response, true);
        if ($data && isset($data['success']) && $data['success']) {
            $studies = $data['data']['studies'] ?? [];
            echo "   ✅ API responde correctamente\n";
            echo "   📚 Estudios devueltos: " . count($studies) . "\n";
            
            if (count($studies) > 0) {
                echo "   📋 Ejemplos:\n";
                foreach (array_slice($studies, 0, 3) as $study) {
                    echo "     - {$study['patient_name']} ({$study['modality']}) - {$study['study_date']}\n";
                }
            }
        } else {
            echo "   ❌ API devuelve error: " . ($data['error'] ?? 'Error desconocido') . "\n";
        }
    } else {
        echo "   ❌ No se pudo conectar con la API\n";
    }
    echo "\n";
    
    // 5. Verificar permisos según validate-session-simple.php
    echo "5. 🔐 VERIFICACIÓN DE PERMISOS:\n";
    echo "   Según validate-session-simple.php:\n";
    echo "   • Usuarios 'root' → permisos: ['all']\n";
    echo "   • Usuarios 'admin' → permisos: ['dashboard', 'estudios', 'pacs_query', 'informes', ...]\n";
    echo "   • Usuarios 'user' → permisos: ['dashboard', 'informes', 'grabacion'] (SIN pacs_query)\n";
    echo "   • Otros → permisos: ['dashboard']\n\n";
    
    // 6. Diagnóstico final
    echo "6. 🎯 DIAGNÓSTICO FINAL:\n";
    
    if (empty($userUsers)) {
        echo "   ❌ PROBLEMA 1: No hay usuarios tipo 'user' para probar\n";
        echo "   💡 SOLUCIÓN: Crear usuario de prueba con nivel 'user'\n\n";
    }
    
    if ($totalAssignments == 0) {
        echo "   ❌ PROBLEMA 2: No hay estudios asignados en study_assignments\n";
        echo "   💡 SOLUCIÓN: Asignar estudios a usuarios tipo 'user'\n\n";
    }
    
    $usersWithoutAssignments = 0;
    foreach ($userUsers as $user) {
        $userId = $user['id'];
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM study_assignments WHERE user_id = ? AND status = 'active'");
        $stmt->execute([$userId]);
        $assignedCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        if ($assignedCount == 0) {
            $usersWithoutAssignments++;
        }
    }
    
    if ($usersWithoutAssignments > 0) {
        echo "   ❌ PROBLEMA 3: $usersWithoutAssignments usuarios sin asignaciones\n";
        echo "   💡 SOLUCIÓN: Usar estudios-manager.html para asignar estudios\n\n";
    }
    
    // 7. Pasos para solucionar
    echo "7. 🚀 PASOS PARA SOLUCIONAR:\n";
    echo "   1. Abrir estudios-manager.html\n";
    echo "   2. Buscar estudios disponibles\n";
    echo "   3. Seleccionar uno o más estudios\n";
    echo "   4. Hacer clic en 'Asignar Seleccionados'\n";
    echo "   5. Seleccionar usuarios tipo 'user'\n";
    echo "   6. Confirmar asignación\n";
    echo "   7. Probar dashboard-unified.html con usuario 'user'\n\n";
    
    echo "8. 🧪 PARA PROBAR LA SOLUCIÓN:\n";
    echo "   1. Crear/usar usuario con nivel 'user'\n";
    echo "   2. Asignar estudios a ese usuario\n";
    echo "   3. Iniciar sesión como ese usuario\n";
    echo "   4. Abrir dashboard-unified.html\n";
    echo "   5. Verificar que aparecen los estudios asignados\n\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "=== FIN DEL DIAGNÓSTICO ===\n";
?>