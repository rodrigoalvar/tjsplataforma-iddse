<?php
/**
 * Script de prueba para diagnosticar errores en los endpoints
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

try {
    echo "1. Cargando _auth.php...\n";
    require_once __DIR__ . '/_auth.php';
    echo "✓ _auth.php cargado\n";
    
    echo "2. Cargando database.php...\n";
    require_once __DIR__ . '/../../../config/database.php';
    echo "✓ database.php cargado\n";
    
    echo "3. Verificando conexión a BD...\n";
    $db = getDBConnection();
    if (!$db) {
        throw new Exception('No se pudo conectar a la base de datos');
    }
    echo "✓ Conexión a BD exitosa\n";
    
    echo "4. Verificando si existe la tabla pacs_nodes...\n";
    $stmt = $db->query("SHOW TABLES LIKE 'pacs_nodes'");
    $tableExists = $stmt->rowCount() > 0;
    if (!$tableExists) {
        echo "✗ La tabla pacs_nodes NO existe. Ejecute install.sql\n";
    } else {
        echo "✓ La tabla pacs_nodes existe\n";
        
        echo "5. Contando nodos...\n";
        $stmt = $db->query("SELECT COUNT(*) as count FROM pacs_nodes");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "✓ Nodos en la tabla: " . $result['count'] . "\n";
    }
    
    echo "6. Verificando PacsNodeConfig...\n";
    require_once __DIR__ . '/../../PacsNodeConfig.php';
    echo "✓ PacsNodeConfig cargado\n";
    
    echo "\n✅ Todas las verificaciones pasaron\n";
    
} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "Archivo: " . $e->getFile() . "\n";
    echo "Línea: " . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
