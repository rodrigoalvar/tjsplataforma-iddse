<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "=== INVESTIGANDO CUENTA prueba_curl@test.com ===\n\n";
    
    // Buscar la cuenta específica
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = 'prueba_curl@test.com'");
    $stmt->execute();
    $user = $stmt->fetch();
    
    if ($user) {
        echo "1. DATOS DE LA CUENTA:\n";
        echo "   - ID: {$user['id']}\n";
        echo "   - Nombre: {$user['nombre']} {$user['apellido']}\n";
        echo "   - Email: {$user['email']}\n";
        echo "   - Nivel: {$user['nivel']}\n";
        echo "   - Padre ID: " . ($user['padre_id'] ?? 'NULL') . "\n";
        echo "   - Activo: {$user['activo']}\n";
        echo "   - Fecha creación: {$user['created_at']}\n";
        
        // Verificar si tiene dependientes
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM usuarios WHERE padre_id = ? AND activo = 1");
        $stmt->execute([$user['id']]);
        $dependents = $stmt->fetch()['count'];
        
        echo "   - Dependientes: {$dependents}\n";
        
        // Verificar si tiene estudios asignados
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM study_assignments WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $studies = $stmt->fetch()['count'];
        
        echo "   - Estudios asignados: {$studies}\n";
        
        echo "\n2. VERIFICANDO TODAS LAS CUENTAS:\n";
        
        // Mostrar todas las cuentas para comparar
        $stmt = $pdo->query("SELECT id, nombre, apellido, email, nivel, activo FROM usuarios ORDER BY id");
        $allUsers = $stmt->fetchAll();
        
        foreach($allUsers as $u) {
            $status = $u['activo'] ? 'Activo' : 'Inactivo';
            echo "   - ID {$u['id']}: {$u['nombre']} {$u['apellido']} ({$u['email']}) - {$u['nivel']} - {$status}\n";
        }
        
    } else {
        echo "✗ Usuario no encontrado\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>


