<?php
echo "Test 4: Conexión a base de datos\n";

try {
    echo "Intentando incluir database.php...\n";
    require_once __DIR__ . "/../../config/database.php";
    echo "✅ database.php incluido\n";
    
    if (class_exists("Database")) {
        echo "✅ Clase Database existe\n";
        
        echo "Intentando crear instancia Database...\n";
        $database = new Database();
        echo "✅ Instancia Database creada\n";
        
        echo "Intentando obtener conexión...\n";
        $pdo = $database->getConnection();
        echo "✅ Conexión PDO obtenida\n";
        
        echo "Intentando query simple...\n";
        $stmt = $pdo->query("SELECT 1 as test");
        $result = $stmt->fetch();
        echo "✅ Query simple exitosa: " . $result["test"] . "\n";
        
    } else {
        echo "❌ Clase Database NO existe\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Archivo: " . $e->getFile() . "\n";
    echo "Línea: " . $e->getLine() . "\n";
} catch (Error $e) {
    echo "❌ Fatal Error: " . $e->getMessage() . "\n";
    echo "Archivo: " . $e->getFile() . "\n";
    echo "Línea: " . $e->getLine() . "\n";
}
?>