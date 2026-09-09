<?php
echo "Test 5: Verificar tablas de base de datos\n";

try {
    require_once __DIR__ . "/../../config/database.php";
    $database = new Database();
    $pdo = $database->getConnection();
    
    $tables = ["usuarios", "sesiones", "informes", "audios_informe"];
    
    foreach ($tables as $table) {
        echo "Verificando tabla: " . $table . "\n";
        
        $stmt = $pdo->query("SHOW TABLES LIKE '" . $table . "'");
        $exists = $stmt->rowCount() > 0;
        
        if ($exists) {
            echo "✅ Tabla " . $table . " existe\n";
            
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM " . $table);
            $result = $stmt->fetch();
            echo "   Registros: " . $result["count"] . "\n";
        } else {
            echo "❌ Tabla " . $table . " NO existe\n";
        }
        echo "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>