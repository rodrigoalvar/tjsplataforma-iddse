<?php
// Test específico de list.php con cookie
error_reporting(E_ALL);
ini_set("display_errors", 1);

echo "=== TEST ESPECÍFICO DE LIST.PHP ===\n\n";

// Simular cookie de sesión
$_COOKIE["session_token"] = "ba22f765b6b44fe4a76d65cf1c957dd8b1e59f98bcff611e39c8669adcd94e32";

echo "Cookie simulada: " . $_COOKIE["session_token"] . "\n\n";

try {
    echo "PASO 1: Incluir dependencias\n";
    require_once "config/database.php";
    require_once "classes/User.php";
    require_once "middleware/auth.php";
    echo "✅ Dependencias incluidas\n\n";
    
    echo "PASO 2: Probar validateSessionToken\n";
    $token = $_COOKIE["session_token"];
    $isValid = validateSessionToken($token);
    
    if ($isValid) {
        echo "✅ validateSessionToken: VÁLIDO\n\n";
        
        echo "PASO 3: Probar getUserFromToken\n";
        $userData = getUserFromToken($token);
        
        if ($userData) {
            echo "✅ getUserFromToken: ÉXITO\n";
            echo "   Usuario: " . $userData["nombre"] . "\n";
            echo "   Email: " . $userData["email"] . "\n\n";
            
            echo "PASO 4: Probar conexión a BD\n";
            $database = new Database();
            $pdo = $database->getConnection();
            echo "✅ Conexión a BD: ÉXITO\n\n";
            
            echo "PASO 5: Probar query de informes\n";
            $query = "SELECT COUNT(*) as total FROM informes";
            $stmt = $pdo->prepare($query);
            $stmt->execute();
            $result = $stmt->fetch();
            echo "✅ Query de informes: ÉXITO\n";
            echo "   Total informes: " . $result["total"] . "\n\n";
            
            echo "🎯 CONCLUSIÓN:\n\n";
            echo "Todos los componentes funcionan correctamente\n";
            echo "El problema debe estar en la lógica específica de list.php\n\n";
            
        } else {
            echo "❌ getUserFromToken: FALLO\n";
        }
        
    } else {
        echo "❌ validateSessionToken: INVÁLIDO\n";
        echo "Este es el problema principal\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Archivo: " . $e->getFile() . "\n";
    echo "Línea: " . $e->getLine() . "\n";
}

echo "\n📋 PRÓXIMO PASO:\n\n";

echo "Si validateSessionToken falla, el problema está en:\n";
echo "1. La función validateSessionToken en middleware/auth.php\n";
echo "2. La función validateSession en classes/User.php\n";
echo "3. La tabla sesiones o su estructura\n\n";

echo "Si todo funciona, el problema está en:\n";
echo "1. La lógica específica de list.php\n";
echo "2. Headers HTTP o configuración del servidor\n";
echo "3. Variables de entorno o contexto de ejecución\n\n";
?>