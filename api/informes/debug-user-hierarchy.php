<?php
/**
 * Script de diagnóstico para verificar la jerarquía de un usuario específico
 * Uso: php debug-user-hierarchy.php kirylukfranco@gmail.com
 */

require_once __DIR__ . '/../../config/database.php';

$email = $argv[1] ?? 'kirylukfranco@gmail.com';

echo "=== DIAGNÓSTICO DE JERARQUÍA DE USUARIO ===\n\n";
echo "Email: $email\n\n";

try {
    $db = getDBConnection();
    
    if (!$db) {
        echo "❌ Error: No se pudo conectar a la base de datos\n";
        exit(1);
    }
    
    // Obtener información del usuario
    $stmt = $db->prepare("SELECT id, nombre, apellido, email, padre_id, activo, permisos FROM usuarios WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo "❌ Usuario no encontrado\n";
        exit(1);
    }
    
    echo "✅ Usuario encontrado:\n";
    echo "   ID: {$user['id']}\n";
    echo "   Nombre: {$user['nombre']} {$user['apellido']}\n";
    echo "   Email: {$user['email']}\n";
    echo "   Padre ID: " . ($user['padre_id'] ?? 'NULL') . "\n";
    echo "   Activo: " . ($user['activo'] ? 'Sí' : 'No') . "\n";
    echo "   Permisos: {$user['permisos']}\n\n";
    
    // Verificar jerarquía hacia arriba (padres)
    echo "=== JERARQUÍA HACIA ARRIBA (PADRES) ===\n";
    $currentId = $user['padre_id'];
    $level = 1;
    $visited = [$user['id']];
    
    while ($currentId && $level < 20) {
        if (in_array($currentId, $visited)) {
            echo "⚠️  CICLO DETECTADO: El usuario ID $currentId ya fue visitado\n";
            break;
        }
        $visited[] = $currentId;
        
        $stmt = $db->prepare("SELECT id, nombre, apellido, email, padre_id FROM usuarios WHERE id = ? AND activo = 1");
        $stmt->execute([$currentId]);
        $parent = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$parent) {
            echo "   Nivel $level: Usuario ID $currentId no encontrado o inactivo\n";
            break;
        }
        
        echo "   Nivel $level: ID {$parent['id']} - {$parent['nombre']} {$parent['apellido']} ({$parent['email']})\n";
        $currentId = $parent['padre_id'];
        $level++;
    }
    
    if ($level >= 20) {
        echo "⚠️  ADVERTENCIA: Jerarquía muy profunda (>20 niveles)\n";
    }
    
    echo "\n";
    
    // Verificar jerarquía hacia abajo (hijos)
    echo "=== JERARQUÍA HACIA ABAJO (HIJOS) ===\n";
    
    function getDescendants($db, $userId, $visited = [], $maxDepth = 10, $currentDepth = 0) {
        if (in_array($userId, $visited) || $currentDepth >= $maxDepth) {
            return [];
        }
        
        $visited[] = $userId;
        $descendants = [];
        
        try {
            $stmt = $db->prepare("SELECT id, nombre, apellido, email, padre_id FROM usuarios WHERE padre_id = ? AND activo = 1");
            $stmt->execute([$userId]);
            $children = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($children as $child) {
                $descendants[] = $child;
                $grandchildren = getDescendants($db, $child['id'], $visited, $maxDepth, $currentDepth + 1);
                $descendants = array_merge($descendants, $grandchildren);
            }
        } catch (Exception $e) {
            echo "   ⚠️  Error obteniendo hijos de ID $userId: " . $e->getMessage() . "\n";
        }
        
        return $descendants;
    }
    
    $descendants = getDescendants($db, $user['id']);
    
    echo "   Total de descendientes encontrados: " . count($descendants) . "\n";
    
    if (count($descendants) > 0) {
        echo "\n   Lista de descendientes:\n";
        foreach ($descendants as $desc) {
            echo "      - ID {$desc['id']}: {$desc['nombre']} {$desc['apellido']} ({$desc['email']})\n";
        }
    }
    
    echo "\n";
    
    // Verificar estudios asignados
    echo "=== ESTUDIOS ASIGNADOS ===\n";
    
    try {
        $checkTable = $db->query("SHOW TABLES LIKE 'study_assignments'");
        if ($checkTable->rowCount() > 0) {
            $stmt = $db->prepare("SELECT COUNT(*) as total FROM study_assignments WHERE user_id = ? AND status = 'active'");
            $stmt->execute([$user['id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            echo "   Estudios asignados directamente: {$result['total']}\n";
        } else {
            echo "   Tabla study_assignments no existe\n";
        }
    } catch (Exception $e) {
        echo "   ⚠️  Error verificando estudios asignados: " . $e->getMessage() . "\n";
    }
    
    try {
        $checkTable = $db->query("SHOW TABLES LIKE 'study_subassignments'");
        if ($checkTable->rowCount() > 0) {
            $stmt = $db->prepare("SELECT COUNT(*) as total FROM study_subassignments WHERE subassigned_to_user_id = ? AND status = 'active'");
            $stmt->execute([$user['id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            echo "   Estudios derivados: {$result['total']}\n";
        } else {
            echo "   Tabla study_subassignments no existe\n";
        }
    } catch (Exception $e) {
        echo "   ⚠️  Error verificando estudios derivados: " . $e->getMessage() . "\n";
    }
    
    echo "\n=== FIN DEL DIAGNÓSTICO ===\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
?>



