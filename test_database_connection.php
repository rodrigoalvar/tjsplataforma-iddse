<?php
/**
 * Test de conexión a la base de datos
 * Para diagnosticar el error PDO en get_user_assigned_studies_fixed.php
 */

echo "=== TEST DE CONEXIÓN A LA BASE DE DATOS ===\n\n";

// Configurar manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "1. Verificando archivo de configuración...\n";
$configFile = __DIR__ . '/config/database.php';
if (file_exists($configFile)) {
    echo "✅ Archivo config/database.php existe\n";
    require_once $configFile;
} else {
    echo "❌ Archivo config/database.php NO existe\n";
    exit(1);
}

echo "\n2. Verificando extensión PDO...\n";
if (extension_loaded('pdo')) {
    echo "✅ Extensión PDO está cargada\n";
} else {
    echo "❌ Extensión PDO NO está cargada\n";
    exit(1);
}

if (extension_loaded('pdo_mysql')) {
    echo "✅ Extensión PDO MySQL está cargada\n";
} else {
    echo "❌ Extensión PDO MySQL NO está cargada\n";
    exit(1);
}

echo "\n3. Probando conexión directa...\n";
try {
    $host = 'localhost';
    $db_name = 'TJSMEDICAL';
    $username = 'root';
    $password = '';
    $charset = 'utf8mb4';
    
    $dsn = "mysql:host=$host;dbname=$db_name;charset=$charset";
    echo "DSN construido: $dsn\n";
    
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ Conexión directa exitosa\n";
    
    // Probar una consulta simple
    $stmt = $pdo->query("SELECT DATABASE() as current_db");
    $result = $stmt->fetch();
    echo "Base de datos actual: " . $result['current_db'] . "\n";
    
} catch (PDOException $e) {
    echo "❌ Error en conexión directa: " . $e->getMessage() . "\n";
    
    // Intentar sin especificar base de datos
    echo "\n4. Probando conexión sin base de datos específica...\n";
    try {
        $dsn_no_db = "mysql:host=$host;charset=$charset";
        echo "DSN sin DB: $dsn_no_db\n";
        
        $pdo_no_db = new PDO($dsn_no_db, $username, $password);
        $pdo_no_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        echo "✅ Conexión sin DB específica exitosa\n";
        
        // Listar bases de datos disponibles
        $stmt = $pdo_no_db->query("SHOW DATABASES");
        $databases = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        echo "Bases de datos disponibles:\n";
        foreach ($databases as $db) {
            echo "  - $db\n";
        }
        
        // Verificar si TJSMEDICAL existe
        if (in_array('TJSMEDICAL', $databases)) {
            echo "✅ Base de datos TJSMEDICAL existe\n";
        } else {
            echo "❌ Base de datos TJSMEDICAL NO existe\n";
            echo "Necesitas crear la base de datos TJSMEDICAL\n";
        }
        
    } catch (PDOException $e2) {
        echo "❌ Error en conexión sin DB: " . $e2->getMessage() . "\n";
    }
}

echo "\n5. Probando función helper...\n";
try {
    if (function_exists('getDBConnection')) {
        $conn = getDBConnection();
        echo "✅ Función getDBConnection() funciona correctamente\n";
        
        // Probar consulta de ejemplo
        $stmt = $conn->query("SELECT 1 as test");
        $result = $stmt->fetch();
        echo "Test query result: " . $result['test'] . "\n";
        
    } else {
        echo "❌ Función getDBConnection() no está definida\n";
    }
} catch (Exception $e) {
    echo "❌ Error en función helper: " . $e->getMessage() . "\n";
}

echo "\n6. Información del sistema...\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "PDO Drivers: " . implode(', ', PDO::getAvailableDrivers()) . "\n";

echo "\n=== FIN DEL TEST ===\n";
?>