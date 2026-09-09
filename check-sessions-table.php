<?php
// Verificar tabla sesiones en ambas versiones
echo "=== VERIFICACIÓN DE TABLA SESIONES ===\n\n";

echo "🔍 VERIFICANDO TABLA 'sesiones' EN VERSIÓN ACTUAL...\n\n";

try {
    require_once 'config/database.php';
    $db = getDBConnection();
    
    // Verificar si existe la tabla
    $stmt = $db->query("SHOW TABLES LIKE 'sesiones'");
    $tableExists = $stmt->rowCount() > 0;
    
    if ($tableExists) {
        echo "✅ Tabla 'sesiones' EXISTE en versión actual\n";
        
        // Verificar estructura
        $stmt = $db->query("DESCRIBE sesiones");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "📋 Estructura de tabla 'sesiones':\n";
        foreach ($columns as $column) {
            echo "   • " . $column['Field'] . " (" . $column['Type'] . ")\n";
        }
        
        // Verificar datos
        $stmt = $db->query("SELECT COUNT(*) as total FROM sesiones");
        $count = $stmt->fetch();
        echo "\n📊 Total de registros: " . $count['total'] . "\n";
        
        if ($count['total'] > 0) {
            echo "✅ Hay datos en la tabla\n";
            
            // Mostrar algunos registros
            $stmt = $db->query("SELECT * FROM sesiones LIMIT 3");
            $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "\n📋 Primeros registros:\n";
            foreach ($sessions as $session) {
                echo "   • ID: " . $session['id'] . ", Usuario: " . $session['usuario_id'] . ", Activa: " . $session['activa'] . "\n";
            }
        } else {
            echo "❌ Tabla está VACÍA\n";
        }
        
    } else {
        echo "❌ Tabla 'sesiones' NO EXISTE en versión actual\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error verificando tabla: " . $e->getMessage() . "\n";
}

echo "\n🔍 VERIFICANDO TABLA 'sesiones' EN PORTAL_148...\n\n";

try {
    // Conectar a portal_148
    $host = 'localhost';
    $dbname = 'tjsmedical'; // Asumiendo misma BD
    $username = 'root';
    $password = '';
    
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    
    // Verificar si existe la tabla
    $stmt = $pdo->query("SHOW TABLES LIKE 'sesiones'");
    $tableExists = $stmt->rowCount() > 0;
    
    if ($tableExists) {
        echo "✅ Tabla 'sesiones' EXISTE en portal_148\n";
        
        // Verificar estructura
        $stmt = $pdo->query("DESCRIBE sesiones");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "📋 Estructura de tabla 'sesiones' en portal_148:\n";
        foreach ($columns as $column) {
            echo "   • " . $column['Field'] . " (" . $column['Type'] . ")\n";
        }
        
        // Verificar datos
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM sesiones");
        $count = $stmt->fetch();
        echo "\n📊 Total de registros en portal_148: " . $count['total'] . "\n";
        
        if ($count['total'] > 0) {
            echo "✅ Hay datos en la tabla de portal_148\n";
            
            // Mostrar algunos registros
            $stmt = $pdo->query("SELECT * FROM sesiones LIMIT 3");
            $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "\n📋 Primeros registros en portal_148:\n";
            foreach ($sessions as $session) {
                echo "   • ID: " . $session['id'] . ", Usuario: " . $session['usuario_id'] . ", Activa: " . $session['activa'] . "\n";
            }
        } else {
            echo "❌ Tabla está VACÍA en portal_148\n";
        }
        
    } else {
        echo "❌ Tabla 'sesiones' NO EXISTE en portal_148\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error verificando portal_148: " . $e->getMessage() . "\n";
}

echo "\n🎯 CONCLUSIÓN:\n\n";

echo "Basado en esta verificación, podremos determinar:\n";
echo "1. Si la tabla existe en ambas versiones\n";
echo "2. Si tiene la estructura correcta\n";
echo "3. Si tiene datos válidos\n";
echo "4. Qué solución implementar\n\n";
?>
