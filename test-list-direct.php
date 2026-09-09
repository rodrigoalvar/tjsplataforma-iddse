<?php
// Test directo para list.php - Diagnóstico de error 500
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== TEST DIRECTO: LIST.PHP ===\n\n";

echo "🔍 PASO 1: Verificar archivos\n\n";

// Verificar si existe list.php
$listPath = 'api/informes/list.php';
if (file_exists($listPath)) {
    echo "✅ list.php existe: $listPath\n";
} else {
    echo "❌ list.php NO existe: $listPath\n";
    exit;
}

// Verificar middleware
$middlewarePath = 'middleware/auth.php';
if (file_exists($middlewarePath)) {
    echo "✅ middleware/auth.php existe: $middlewarePath\n";
} else {
    echo "❌ middleware/auth.php NO existe: $middlewarePath\n";
}

// Verificar database config
$dbPath = 'config/database.php';
if (file_exists($dbPath)) {
    echo "✅ config/database.php existe: $dbPath\n";
} else {
    echo "❌ config/database.php NO existe: $dbPath\n";
}

// Verificar User class
$userPath = 'classes/User.php';
if (file_exists($userPath)) {
    echo "✅ classes/User.php existe: $userPath\n";
} else {
    echo "❌ classes/User.php NO existe: $userPath\n";
}

echo "\n🔍 PASO 2: Probar includes\n\n";

try {
    echo "Probando require de database.php...\n";
    require_once 'config/database.php';
    echo "✅ database.php cargado correctamente\n";
    
    echo "Probando require de User.php...\n";
    require_once 'classes/User.php';
    echo "✅ User.php cargado correctamente\n";
    
    echo "Probando require de middleware/auth.php...\n";
    require_once 'middleware/auth.php';
    echo "✅ middleware/auth.php cargado correctamente\n";
    
} catch (Exception $e) {
    echo "❌ Error en includes: " . $e->getMessage() . "\n";
    exit;
}

echo "\n🔍 PASO 3: Probar conexión a base de datos\n\n";

try {
    $db = getDBConnection();
    echo "✅ Conexión a base de datos exitosa\n";
    
    // Probar query simple
    $stmt = $db->query("SELECT COUNT(*) as total FROM informes");
    $result = $stmt->fetch();
    echo "✅ Query de prueba exitosa. Total informes: " . $result['total'] . "\n";
    
} catch (Exception $e) {
    echo "❌ Error en base de datos: " . $e->getMessage() . "\n";
}

echo "\n🔍 PASO 4: Simular variables de entorno\n\n";

// Simular $_SERVER para el test
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['page'] = '1';

echo "✅ Variables simuladas:\n";
echo "   REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD'] . "\n";
echo "   GET[page]: " . $_GET['page'] . "\n";

echo "\n🔍 PASO 5: Probar autenticación\n\n";

// Simular cookie de sesión (si existe)
if (isset($_COOKIE['session_token'])) {
    echo "✅ Cookie session_token encontrada: " . substr($_COOKIE['session_token'], 0, 10) . "...\n";
} else {
    echo "⚠️ No hay cookie session_token (normal en CLI)\n";
}

echo "\n🎯 CONCLUSIÓN:\n\n";

echo "Si todos los pasos anteriores son exitosos,\n";
echo "el problema está en la lógica específica de list.php.\n";
echo "Si hay errores, esos son los que causan el 500.\n\n";

echo "📋 PRÓXIMO PASO:\n\n";

echo "Ejecutar este test y ver qué errores específicos aparecen.\n";
?>
