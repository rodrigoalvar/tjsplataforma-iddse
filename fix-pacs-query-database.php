<?php
echo "=== VERIFICANDO ESTRUCTURA Y AGREGANDO PACS QUERY ===\n\n";

// Configuración de base de datos
$host = 'localhost';
$dbname = 'tjsmedical';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ Conexión a base de datos exitosa\n\n";
    
    // Verificar estructura de la tabla
    echo "🔍 Estructura de la tabla system_permissions:\n";
    $stmt = $pdo->query("DESCRIBE system_permissions");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($columns as $col) {
        echo "   • {$col['Field']} ({$col['Type']}) - {$col['Null']} - {$col['Default']}\n";
    }
    
    echo "\n🔍 Verificando si PACS Query ya existe...\n";
    
    $stmt = $pdo->prepare("SELECT id FROM system_permissions WHERE permission_key = 'pacs_query'");
    $stmt->execute();
    $exists = $stmt->fetch();
    
    if ($exists) {
        echo "⚠️ PACS Query ya existe en la base de datos (ID: {$exists['id']})\n";
    } else {
        echo "🔍 PACS Query no existe, agregándolo...\n";
        
        // Insertar PACS Query con solo las columnas que existen
        $stmt = $pdo->prepare("
            INSERT INTO system_permissions (permission_key, permission_name, description, category) 
            VALUES (?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            'pacs_query',
            'PACS Query',
            'Permite consultar el PACS directamente',
            'estudios'
        ]);
        
        if ($result) {
            $newId = $pdo->lastInsertId();
            echo "✅ PACS Query agregado exitosamente (ID: $newId)\n";
        } else {
            echo "❌ Error al agregar PACS Query\n";
        }
    }
    
    echo "\n🔍 VERIFICACIÓN FINAL...\n";
    
    $stmt = $pdo->query("SELECT * FROM system_permissions WHERE category = 'estudios' ORDER BY permission_key");
    $estudiosPermissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "📋 Permisos en categoría 'estudios':\n";
    foreach ($estudiosPermissions as $perm) {
        echo "   • {$perm['permission_name']} ({$perm['permission_key']})\n";
        if ($perm['permission_key'] === 'pacs_query') {
            echo "   ✅ PACS Query confirmado!\n";
        }
    }
    
    echo "\n📊 Total de permisos en la tabla: ";
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM system_permissions");
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    echo "$total\n";
    
    echo "\n✅ CORRECCIÓN COMPLETADA\n";
    echo "   Ahora PACS Query debería aparecer en el modal Editar Usuario\n";
    echo "   en la sección 'Estudios' junto con 'Gestión de Estudios'\n";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
