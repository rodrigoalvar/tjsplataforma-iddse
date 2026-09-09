<?php
echo "=== CREAR TESTS ULTRA-BÁSICOS ===\n\n";

echo "Creando test1-basic.php...\n";
$test1 = '<?php
echo "Test 1: PHP básico funciona\n";
echo "PHP Version: " . phpversion() . "\n";
echo "Working Directory: " . getcwd() . "\n";
echo "Script Location: " . __FILE__ . "\n";
?>';
file_put_contents('api/informes/test1-basic.php', $test1);
echo "✅ test1-basic.php creado\n\n";

echo "Creando test2-files.php...\n";
$test2 = '<?php
echo "Test 2: Verificar archivos\n";

$files = [
    "../../config/database.php",
    "../../classes/User.php", 
    "../../middleware/auth.php"
];

foreach ($files as $file) {
    $fullPath = __DIR__ . "/" . $file;
    echo "Archivo: " . $file . "\n";
    echo "Ruta completa: " . $fullPath . "\n";
    echo "Existe: " . (file_exists($fullPath) ? "SÍ" : "NO") . "\n";
    echo "Legible: " . (is_readable($fullPath) ? "SÍ" : "NO") . "\n\n";
}
?>';
file_put_contents('api/informes/test2-files.php', $test2);
echo "✅ test2-files.php creado\n\n";

echo "Creando test3-syntax.php...\n";
$test3 = '<?php
echo "Test 3: Verificar sintaxis PHP\n";

$files = [
    "../../config/database.php",
    "../../classes/User.php", 
    "../../middleware/auth.php"
];

foreach ($files as $file) {
    $fullPath = __DIR__ . "/" . $file;
    echo "Verificando sintaxis: " . $file . "\n";
    
    $output = shell_exec("php -l " . escapeshellarg($fullPath) . " 2>&1");
    echo "Resultado: " . trim($output) . "\n\n";
}
?>';
file_put_contents('api/informes/test3-syntax.php', $test3);
echo "✅ test3-syntax.php creado\n\n";

echo "Creando test4-database.php...\n";
$test4 = '<?php
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
?>';
file_put_contents('api/informes/test4-database.php', $test4);
echo "✅ test4-database.php creado\n\n";

echo "Creando test5-tables.php...\n";
$test5 = '<?php
echo "Test 5: Verificar tablas de base de datos\n";

try {
    require_once __DIR__ . "/../../config/database.php";
    $database = new Database();
    $pdo = $database->getConnection();
    
    $tables = ["usuarios", "sesiones", "informes", "audios_informe"];
    
    foreach ($tables as $table) {
        echo "Verificando tabla: " . $table . "\n";
        
        $stmt = $pdo->query("SHOW TABLES LIKE \'" . $table . "\'");
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
?>';
file_put_contents('api/informes/test5-tables.php', $test5);
echo "✅ test5-tables.php creado\n\n";

echo "TESTS CREADOS EXITOSAMENTE:\n\n";

echo "1. test1-basic.php - PHP básico\n";
echo "   URL: http://localhost/portal_estudios/api/informes/test1-basic.php\n\n";

echo "2. test2-files.php - Verificar archivos\n";
echo "   URL: http://localhost/portal_estudios/api/informes/test2-files.php\n\n";

echo "3. test3-syntax.php - Verificar sintaxis\n";
echo "   URL: http://localhost/portal_estudios/api/informes/test3-syntax.php\n\n";

echo "4. test4-database.php - Conexión BD\n";
echo "   URL: http://localhost/portal_estudios/api/informes/test4-database.php\n\n";

echo "5. test5-tables.php - Verificar tablas\n";
echo "   URL: http://localhost/portal_estudios/api/informes/test5-tables.php\n\n";

echo "INSTRUCCIONES:\n\n";

echo "Ejecutar los tests en orden:\n";
echo "1. Probar test1-basic.php\n";
echo "2. Si funciona, probar test2-files.php\n";
echo "3. Si funciona, probar test3-syntax.php\n";
echo "4. Si funciona, probar test4-database.php\n";
echo "5. Si funciona, probar test5-tables.php\n\n";

echo "DIAGNÓSTICO:\n\n";

echo "El primer test que falle nos dirá:\n";
echo "- Si PHP funciona correctamente\n";
echo "- Si los archivos existen\n";
echo "- Si hay errores de sintaxis\n";
echo "- Si la conexión a BD funciona\n";
echo "- Si las tablas existen\n\n";

echo "Una vez identificado el problema:\n";
echo "- Podemos aplicar la corrección específica\n";
echo "- Resolver el error 500\n";
echo "- Hacer que informes-manager funcione\n\n";
?>
