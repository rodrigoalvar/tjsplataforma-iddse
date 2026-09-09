<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "=== VERIFICANDO DEPENDIENTES DE prueba_curl@test.com ===\n\n";
    
    // Buscar la cuenta específica
    $stmt = $pdo->prepare("SELECT id, nombre, apellido, email FROM usuarios WHERE email = 'prueba_curl@test.com'");
    $stmt->execute();
    $user = $stmt->fetch();
    
    if ($user) {
        echo "Usuario: {$user['nombre']} {$user['apellido']} (ID: {$user['id']})\n\n";
        
        // Verificar dependientes directos
        $stmt = $pdo->prepare("SELECT id, nombre, apellido, email FROM usuarios WHERE padre_id = ? AND activo = 1");
        $stmt->execute([$user['id']]);
        $dependents = $stmt->fetchAll();
        
        echo "Dependientes directos: " . count($dependents) . "\n";
        foreach($dependents as $dep) {
            echo "  - {$dep['nombre']} {$dep['apellido']} ({$dep['email']})\n";
        }
        
        // Verificar si tiene estudios asignados
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM study_assignments WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $studies = $stmt->fetch()['count'];
        
        echo "\nEstudios asignados: {$studies}\n";
        
        // Verificar la lógica de eliminación
        echo "\n=== ANÁLISIS DE ELIMINACIÓN ===\n";
        
        $canDelete = true;
        $reasons = [];
        
        // Verificar dependientes
        if (count($dependents) > 0) {
            $canDelete = false;
            $reasons[] = "Tiene " . count($dependents) . " dependientes";
        }
        
        // Verificar estudios
        if ($studies > 0) {
            $canDelete = false;
            $reasons[] = "Tiene {$studies} estudios asignados";
        }
        
        echo "¿Se puede eliminar?: " . ($canDelete ? "SÍ" : "NO") . "\n";
        if (!$canDelete) {
            echo "Razones:\n";
            foreach($reasons as $reason) {
                echo "  - {$reason}\n";
            }
        }
        
    } else {
        echo "✗ Usuario no encontrado\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>


