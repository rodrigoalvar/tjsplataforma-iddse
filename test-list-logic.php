<?php
// Test específico de la lógica de list.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== TEST ESPECÍFICO: LÓGICA DE LIST.PHP ===\n\n";

// Incluir dependencias
require_once 'config/database.php';
require_once 'classes/User.php';
require_once 'middleware/auth.php';

// Simular entorno web
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['page'] = '1';

echo "🔍 PASO 1: Simular autenticación\n\n";

// Simular cookie de sesión para testing
$_COOKIE['session_token'] = 'test_token_123';

echo "✅ Cookie simulada: " . $_COOKIE['session_token'] . "\n";

echo "\n🔍 PASO 2: Probar función validateSessionToken\n\n";

try {
    // Esta función está en middleware/auth.php
    $userData = validateSessionToken($_COOKIE['session_token']);
    
    if ($userData) {
        echo "✅ validateSessionToken exitosa\n";
        echo "   Usuario ID: " . $userData['id'] . "\n";
        echo "   Email: " . $userData['email'] . "\n";
        echo "   Nivel: " . $userData['nivel'] . "\n";
    } else {
        echo "❌ validateSessionToken falló - no hay usuario válido\n";
        echo "   Esto explicaría el error 500\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error en validateSessionToken: " . $e->getMessage() . "\n";
    echo "   Este es probablemente el error que causa el 500\n";
}

echo "\n🔍 PASO 3: Probar query de informes\n\n";

try {
    $db = getDBConnection();
    
    // Query básica de informes
    $query = "SELECT i.*, u.nombre as usuario_nombre, u.email as usuario_email
              FROM informes i
              LEFT JOIN usuarios u ON i.usuario_id = u.id
              ORDER BY i.fecha_creacion DESC
              LIMIT 10";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $informes = $stmt->fetchAll();
    
    echo "✅ Query de informes exitosa\n";
    echo "   Total informes encontrados: " . count($informes) . "\n";
    
    if (count($informes) > 0) {
        echo "   Primer informe ID: " . $informes[0]['id'] . "\n";
        echo "   Primer informe usuario: " . $informes[0]['usuario_nombre'] . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error en query de informes: " . $e->getMessage() . "\n";
}

echo "\n🔍 PASO 4: Probar paginación\n\n";

try {
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = 10;
    $offset = ($page - 1) * $limit;
    
    echo "✅ Parámetros de paginación:\n";
    echo "   Página: $page\n";
    echo "   Límite: $limit\n";
    echo "   Offset: $offset\n";
    
} catch (Exception $e) {
    echo "❌ Error en paginación: " . $e->getMessage() . "\n";
}

echo "\n🎯 DIAGNÓSTICO:\n\n";

echo "Si validateSessionToken falla, ese es el problema.\n";
echo "El middleware/auth.php necesita una sesión válida.\n\n";

echo "📋 SOLUCIÓN PROBABLE:\n\n";

echo "1. ✅ El problema está en la autenticación\n";
echo "2. ✅ validateSessionToken no encuentra usuario válido\n";
echo "3. ✅ Necesitamos crear una sesión válida o simplificar la autenticación\n\n";

echo "🔧 PRÓXIMO PASO:\n\n";

echo "Crear una versión simplificada de list.php que funcione\n";
echo "sin autenticación compleja para testing.\n";
?>
