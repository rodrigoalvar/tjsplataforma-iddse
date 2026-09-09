<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "=== INVESTIGANDO CONTEO DE DEPENDIENTES ===\n\n";
    
    // Verificar la consulta que usa la API
    $query = "SELECT 
                u.id,
                u.nombre,
                u.apellido,
                u.email,
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
    
    echo "1. DATOS DE LA API:\n";
    foreach($users as $user) {
        echo "   - ID {$user['id']}: {$user['nombre']} {$user['apellido']} ({$user['nivel']})\n";
        echo "     Padre: " . ($user['padre_nombre'] ? "{$user['padre_nombre']} {$user['padre_apellido']}" : "Ninguno") . "\n";
        echo "     Dependientes: {$user['dependientes_count']}\n";
        echo "     ---\n";
    }
    
    echo "\n2. VERIFICACIÓN MANUAL DE DEPENDIENTES:\n";
    
    // Verificar manualmente cada usuario
    foreach($users as $user) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM usuarios WHERE padre_id = ? AND activo = 1");
        $stmt->execute([$user['id']]);
        $manualCount = $stmt->fetch()['count'];
        
        echo "   - {$user['nombre']} {$user['apellido']}:\n";
        echo "     API dice: {$user['dependientes_count']}\n";
        echo "     Manual dice: {$manualCount}\n";
        
        if ($user['dependientes_count'] != $manualCount) {
            echo "     ⚠️ DIFERENCIA DETECTADA!\n";
        } else {
            echo "     ✓ Coincide\n";
        }
        echo "     ---\n";
    }
    
    echo "\n3. VERIFICAR ESTRUCTURA DE DATOS:\n";
    
    // Verificar si hay usuarios con padre_id
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM usuarios WHERE padre_id IS NOT NULL AND activo = 1");
    $withParent = $stmt->fetch()['count'];
    
    echo "   - Usuarios con padre: {$withParent}\n";
    
    // Verificar si hay usuarios que son padres
    $stmt = $pdo->query("SELECT COUNT(DISTINCT padre_id) as count FROM usuarios WHERE padre_id IS NOT NULL AND activo = 1");
    $uniqueParents = $stmt->fetch()['count'];
    
    echo "   - Usuarios únicos que son padres: {$uniqueParents}\n";
    
    // Mostrar relaciones específicas
    $stmt = $pdo->query("SELECT padre_id, COUNT(*) as count FROM usuarios WHERE padre_id IS NOT NULL AND activo = 1 GROUP BY padre_id");
    $relations = $stmt->fetchAll();
    
    echo "\n4. RELACIONES ESPECÍFICAS:\n";
    foreach($relations as $relation) {
        $stmt = $pdo->prepare("SELECT nombre, apellido FROM usuarios WHERE id = ?");
        $stmt->execute([$relation['padre_id']]);
        $parent = $stmt->fetch();
        
        echo "   - {$parent['nombre']} {$parent['apellido']} (ID: {$relation['padre_id']}) tiene {$relation['count']} dependientes\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>


