<?php
/**
 * Debug API Context - Simular exactamente el contexto del navegador
 * Para reproducir el error PDO que ocurre en dashboard-with-permissions.js
 */

echo "=== DEBUG API CONTEXT ===\n\n";

// Configurar manejo de errores igual que en get_user_assigned_studies_fixed.php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

echo "1. Simulando contexto del navegador...\n";

// Simular headers CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Simular cookies de sesión (como las que tendría el navegador)
$_COOKIE['session_token'] = 'test_session_token_123';

echo "2. Cookies simuladas:\n";
foreach ($_COOKIE as $key => $value) {
    echo "   $key = $value\n";
}

echo "\n3. Probando require de database.php...\n";
try {
    $configPath = '../config/database.php';
    echo "Intentando: $configPath\n";
    
    if (file_exists($configPath)) {
        echo "✅ Archivo existe\n";
        require_once $configPath;
        echo "✅ Archivo incluido correctamente\n";
    } else {
        echo "❌ Archivo NO existe\n";
        
        // Probar ruta alternativa
        $configPath2 = __DIR__ . '/config/database.php';
        echo "Intentando ruta alternativa: $configPath2\n";
        
        if (file_exists($configPath2)) {
            echo "✅ Archivo alternativo existe\n";
            require_once $configPath2;
            echo "✅ Archivo alternativo incluido correctamente\n";
        } else {
            echo "❌ Archivo alternativo NO existe\n";
        }
    }
} catch (Exception $e) {
    echo "❌ Error incluyendo database.php: " . $e->getMessage() . "\n";
}

echo "\n4. Probando conexión PDO...\n";
try {
    if (function_exists('getDBConnection')) {
        echo "Función getDBConnection disponible\n";
        $pdo = getDBConnection();
        echo "✅ Conexión PDO exitosa\n";
        
        // Probar consulta simple
        $stmt = $pdo->query("SELECT 1 as test");
        $result = $stmt->fetch();
        echo "Test query: " . $result['test'] . "\n";
        
    } else {
        echo "❌ Función getDBConnection NO disponible\n";
        
        // Intentar conexión directa
        echo "Intentando conexión directa...\n";
        $dsn = "mysql:host=localhost;dbname=TJSMEDICAL;charset=utf8mb4";
        $pdo = new PDO($dsn, 'root', '');
        echo "✅ Conexión directa exitosa\n";
    }
} catch (PDOException $e) {
    echo "❌ Error PDO: " . $e->getMessage() . "\n";
    echo "DSN usado: " . (isset($dsn) ? $dsn : 'No definido') . "\n";
} catch (Exception $e) {
    echo "❌ Error general: " . $e->getMessage() . "\n";
}

echo "\n5. Información del contexto actual...\n";
echo "Working Directory: " . getcwd() . "\n";
echo "Script Path: " . __FILE__ . "\n";
echo "Script Dir: " . __DIR__ . "\n";
echo "Include Path: " . get_include_path() . "\n";

echo "\n6. Verificando archivos en directorio actual...\n";
$files = scandir('.');
foreach ($files as $file) {
    if ($file !== '.' && $file !== '..') {
        echo "   $file\n";
    }
}

echo "\n=== FIN DEBUG ===\n";
?>