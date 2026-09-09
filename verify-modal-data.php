<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "=== VERIFICANDO DATOS DEL MODAL ===\n\n";
    
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
    
    echo "1. DATOS QUE DEVUELVE LA API:\n";
    foreach($users as $user) {
        echo "   - ID {$user['id']}: {$user['nombre']} {$user['apellido']} ({$user['nivel']})\n";
        echo "     dependientes_count: {$user['dependientes_count']} (tipo: " . gettype($user['dependientes_count']) . ")\n";
        echo "     ---\n";
    }
    
    echo "\n2. VERIFICACIÓN ESPECÍFICA DE USUARIOS CON DEPENDIENTES:\n";
    
    foreach($users as $user) {
        if ($user['dependientes_count'] > 0) {
            echo "   Usuario: {$user['nombre']} {$user['apellido']} (ID: {$user['id']})\n";
            echo "   dependientes_count: {$user['dependientes_count']}\n";
            
            // Verificar dependientes específicos
            $stmt = $pdo->prepare("SELECT id, nombre, apellido, email FROM usuarios WHERE padre_id = ? AND activo = 1");
            $stmt->execute([$user['id']]);
            $dependents = $stmt->fetchAll();
            
            echo "   Dependientes reales:\n";
            foreach($dependents as $dep) {
                echo "     - {$dep['nombre']} {$dep['apellido']} ({$dep['email']})\n";
            }
            echo "   ---\n";
        }
    }
    
    echo "\n3. PRUEBA DE JSON:\n";
    
    $jsonData = json_encode($users, JSON_UNESCAPED_UNICODE);
    echo "   JSON generado: " . substr($jsonData, 0, 200) . "...\n";
    
    // Verificar si hay problemas con el JSON
    $decoded = json_decode($jsonData, true);
    if ($decoded) {
        echo "   ✓ JSON válido\n";
        echo "   Primer usuario en JSON:\n";
        $firstUser = $decoded[0];
        echo "     - dependientes_count: {$firstUser['dependientes_count']} (tipo: " . gettype($firstUser['dependientes_count']) . ")\n";
    } else {
        echo "   ✗ JSON inválido\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>


