<?php
echo "=== PRUEBA DE APIs CORREGIDAS ===\n\n";

// Configuración de base de datos
$host = 'localhost';
$dbname = 'tjsmedical';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ Conexión a base de datos exitosa\n\n";
    
    // Simular sesión para usuario ID 2 (admin)
    session_start();
    $_SESSION['user_id'] = 2;
    
    echo "🔐 Sesión simulada para usuario ID 2\n\n";
    
    // Probar API de validación de sesión
    echo "1. 📋 PROBANDO API DE VALIDACIÓN DE SESIÓN:\n";
    
    // Simular llamada a la API
    ob_start();
    include 'api/auth/validate-session-simple.php';
    $response1 = ob_get_clean();
    
    echo "Respuesta: " . $response1 . "\n\n";
    
    // Probar API de estudios asignados
    echo "2. 📋 PROBANDO API DE ESTUDIOS ASIGNADOS:\n";
    
    ob_start();
    include 'api/get_user_assigned_studies_fixed.php';
    $response2 = ob_get_clean();
    
    echo "Respuesta: " . $response2 . "\n\n";
    
    // Verificar que las respuestas son JSON válido
    $json1 = json_decode($response1, true);
    $json2 = json_decode($response2, true);
    
    if ($json1 && $json2) {
        echo "✅ Ambas APIs devuelven JSON válido\n\n";
        
        if ($json1['success'] && $json1['user']) {
            echo "✅ API de validación de sesión funciona correctamente\n";
            echo "   Usuario: {$json1['user']['nombre']} {$json1['user']['apellido']}\n";
            echo "   Nivel: {$json1['user']['nivel']}\n";
            echo "   Permisos: " . implode(', ', $json1['user']['permisos']) . "\n\n";
        } else {
            echo "❌ API de validación de sesión falló\n\n";
        }
        
        if ($json2['success'] && isset($json2['data']['studies'])) {
            echo "✅ API de estudios asignados funciona correctamente\n";
            echo "   Estudios encontrados: " . count($json2['data']['studies']) . "\n";
            echo "   Total: {$json2['data']['total']}\n\n";
        } else {
            echo "❌ API de estudios asignados falló\n\n";
        }
        
    } else {
        echo "❌ Una o ambas APIs devuelven JSON inválido\n";
        if (!$json1) echo "   - API de validación de sesión\n";
        if (!$json2) echo "   - API de estudios asignados\n";
    }
    
    // Limpiar sesión
    session_destroy();
    
    echo "✅ Prueba completada\n";
    
} catch (PDOException $e) {
    echo "❌ Error de conexión: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
