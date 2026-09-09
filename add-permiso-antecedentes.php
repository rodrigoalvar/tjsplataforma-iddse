<?php
echo "=== AGREGANDO PERMISO ANTECEDENTES A LA BASE DE DATOS ===\n\n";

// Configuración de base de datos
$host = 'localhost';
$dbname = 'tjsmedical';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ Conexión a base de datos exitosa\n\n";
    
    // Verificar si Antecedentes ya existe
    $stmt = $pdo->prepare("SELECT id FROM system_permissions WHERE permission_key = 'antecedentes'");
    $stmt->execute();
    $exists = $stmt->fetch();
    
    if ($exists) {
        echo "⚠️ Antecedentes ya existe en la base de datos (ID: {$exists['id']})\n";
        echo "   Actualizando información...\n";
        
        // Actualizar el permiso existente
        $stmt = $pdo->prepare("
            UPDATE system_permissions 
            SET permission_name = ?, description = ?, category = ? 
            WHERE permission_key = 'antecedentes'
        ");
        
        $result = $stmt->execute([
            'Antecedentes',
            'Permite gestionar antecedentes de pacientes',
            'estudios'
        ]);
        
        if ($result) {
            echo "✅ Permiso Antecedentes actualizado exitosamente\n";
        } else {
            echo "❌ Error al actualizar Antecedentes\n";
        }
    } else {
        echo "🔍 Antecedentes no existe, agregándolo...\n";
        
        // Insertar Antecedentes
        $stmt = $pdo->prepare("
            INSERT INTO system_permissions (permission_key, permission_name, description, category, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        
        $result = $stmt->execute([
            'antecedentes',
            'Antecedentes',
            'Permite gestionar antecedentes de pacientes',
            'estudios'
        ]);
        
        if ($result) {
            $newId = $pdo->lastInsertId();
            echo "✅ Antecedentes agregado exitosamente (ID: $newId)\n";
        } else {
            echo "❌ Error al agregar Antecedentes\n";
        }
    }
    
    echo "\n🔍 VERIFICANDO PERMISOS ACTUALES EN ESTUDIOS...\n";
    
    $stmt = $pdo->query("SELECT * FROM system_permissions WHERE category = 'estudios' ORDER BY permission_key");
    $estudiosPermissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "📋 Permisos en categoría 'estudios':\n";
    foreach ($estudiosPermissions as $perm) {
        echo "   • {$perm['permission_name']} ({$perm['permission_key']})\n";
        if ($perm['permission_key'] === 'antecedentes') {
            echo "   ✅ Antecedentes confirmado!\n";
        }
    }
    
    echo "\n🔍 VERIFICANDO TODOS LOS PERMISOS...\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM system_permissions");
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    echo "📊 Total de permisos en la tabla: $total\n";
    
    $stmt = $pdo->query("SELECT category, COUNT(*) as count FROM system_permissions GROUP BY category ORDER BY category");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\n📋 Permisos por categoría:\n";
    foreach ($categories as $cat) {
        echo "   • {$cat['category']}: {$cat['count']} permisos\n";
    }
    
    echo "\n✅ PERMISO AGREGADO COMPLETADO\n";
    echo "   Ahora Antecedentes debería aparecer en el modal Editar Usuario\n";
    echo "   en la sección 'Estudios' junto con los demás permisos\n";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>

