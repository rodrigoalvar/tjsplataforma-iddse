<?php
echo "=== VERIFICACIÓN DE TABLA SYSTEM_PERMISSIONS ===\n\n";

// Configuración de base de datos
$host = 'localhost';
$dbname = 'tjsmedical';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ Conexión a base de datos exitosa\n\n";
    
    // Verificar si existe la tabla system_permissions
    echo "🔍 Verificando tabla system_permissions...\n";
    
    $stmt = $pdo->query("SHOW TABLES LIKE 'system_permissions'");
    $tableExists = $stmt->rowCount() > 0;
    
    if ($tableExists) {
        echo "✅ La tabla system_permissions EXISTE\n\n";
        
        // Contar registros
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM system_permissions");
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        echo "📊 Total de permisos en la tabla: $count\n\n";
        
        if ($count > 0) {
            echo "📋 PERMISOS EN LA TABLA:\n";
            $stmt = $pdo->query("SELECT * FROM system_permissions ORDER BY category, permission_key");
            $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $currentCategory = '';
            foreach ($permissions as $perm) {
                if ($perm['category'] !== $currentCategory) {
                    $currentCategory = $perm['category'];
                    echo "\n🔹 CATEGORÍA: $currentCategory\n";
                }
                echo "   • {$perm['permission_name']} ({$perm['permission_key']})\n";
                
                if ($perm['permission_key'] === 'pacs_query') {
                    echo "   ✅ PACS Query encontrado en la BD!\n";
                }
            }
            
            // Verificar específicamente PACS Query
            $stmt = $pdo->prepare("SELECT * FROM system_permissions WHERE permission_key = 'pacs_query'");
            $stmt->execute();
            $pacsQuery = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($pacsQuery) {
                echo "\n✅ PACS Query encontrado en la base de datos:\n";
                echo "   • ID: {$pacsQuery['id']}\n";
                echo "   • Nombre: {$pacsQuery['permission_name']}\n";
                echo "   • Clave: {$pacsQuery['permission_key']}\n";
                echo "   • Categoría: {$pacsQuery['category']}\n";
                echo "   • Descripción: {$pacsQuery['description']}\n";
            } else {
                echo "\n❌ PACS Query NO encontrado en la base de datos\n";
                echo "   Esto explica por qué no aparece en el modal!\n";
            }
            
        } else {
            echo "⚠️ La tabla existe pero está vacía\n";
        }
        
    } else {
        echo "❌ La tabla system_permissions NO EXISTE\n";
        echo "   Esto significa que se usan los permisos por defecto del código\n";
    }
    
    echo "\n🔍 VERIFICANDO TABLA USUARIOS...\n";
    
    // Verificar estructura de la tabla usuarios
    $stmt = $pdo->query("DESCRIBE usuarios");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "📊 Columnas en la tabla usuarios:\n";
    foreach ($columns as $col) {
        echo "   • {$col['Field']} ({$col['Type']})\n";
    }
    
    // Verificar si existe columna permisos
    $hasPermisos = false;
    foreach ($columns as $col) {
        if ($col['Field'] === 'permisos') {
            $hasPermisos = true;
            break;
        }
    }
    
    if ($hasPermisos) {
        echo "\n✅ La columna 'permisos' existe en la tabla usuarios\n";
        
        // Verificar algunos usuarios y sus permisos
        $stmt = $pdo->query("SELECT id, nombre, apellido, nivel, permisos FROM usuarios LIMIT 5");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "\n📋 PERMISOS DE ALGUNOS USUARIOS:\n";
        foreach ($users as $user) {
            echo "   • {$user['nombre']} {$user['apellido']} ({$user['nivel']}): {$user['permisos']}\n";
        }
    } else {
        echo "\n❌ La columna 'permisos' NO existe en la tabla usuarios\n";
    }
    
} catch (PDOException $e) {
    echo "❌ Error de conexión: " . $e->getMessage() . "\n";
}

echo "\n🔍 CONCLUSIÓN:\n";
echo "Si la tabla system_permissions existe y tiene permisos, la API los usará\n";
echo "Si no existe o está vacía, la API usará los permisos por defecto del código\n";
echo "Si PACS Query no está en la tabla, no aparecerá en el modal\n";
?>
