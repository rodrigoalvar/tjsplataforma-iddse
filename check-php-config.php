<?php
/**
 * Verificación de Configuración PHP
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

echo "<h1>Verificación de Configuración PHP</h1>";
echo "<p>Sistema TJSMEDICAL - Portal de Estudios Médicos</p>";

echo "<h3>1. Información de PHP:</h3>";
echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
echo "<p><strong>Versión de PHP:</strong> " . phpversion() . "</p>";
echo "<p><strong>Sistema Operativo:</strong> " . php_uname() . "</p>";
echo "<p><strong>Servidor:</strong> " . $_SERVER['SERVER_SOFTWARE'] . "</p>";
echo "</div>";

echo "<h3>2. Configuración de PHP:</h3>";
echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
echo "<p><strong>display_errors:</strong> " . (ini_get('display_errors') ? 'ON' : 'OFF') . "</p>";
echo "<p><strong>error_reporting:</strong> " . ini_get('error_reporting') . "</p>";
echo "<p><strong>log_errors:</strong> " . (ini_get('log_errors') ? 'ON' : 'OFF') . "</p>";
echo "<p><strong>error_log:</strong> " . ini_get('error_log') . "</p>";
echo "<p><strong>max_execution_time:</strong> " . ini_get('max_execution_time') . "</p>";
echo "<p><strong>memory_limit:</strong> " . ini_get('memory_limit') . "</p>";
echo "</div>";

echo "<h3>3. Extensiones de PHP:</h3>";
echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
echo "<p><strong>PDO:</strong> " . (extension_loaded('pdo') ? '✅ Disponible' : '❌ No disponible') . "</p>";
echo "<p><strong>PDO MySQL:</strong> " . (extension_loaded('pdo_mysql') ? '✅ Disponible' : '❌ No disponible') . "</p>";
echo "<p><strong>JSON:</strong> " . (extension_loaded('json') ? '✅ Disponible' : '❌ No disponible') . "</p>";
echo "<p><strong>cURL:</strong> " . (extension_loaded('curl') ? '✅ Disponible' : '❌ No disponible') . "</p>";
echo "</div>";

echo "<h3>4. Prueba de Conexión a Base de Datos:</h3>";

try {
    require_once 'config/database.php';
    $pdo = getDBConnection();
    
    echo "<div style='color: green;'>✅ Conexión a base de datos exitosa</div>";
    
    // Probar una consulta simple
    $query = "SELECT COUNT(*) as total FROM usuarios";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch();
    
    echo "<div style='background: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
    echo "<p><strong>Total de usuarios:</strong> {$result['total']}</p>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='color: red;'>❌ Error de conexión: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "<h3>5. Prueba de API Directa:</h3>";

try {
    // Probar la API debug-simple directamente
    $url = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/api/users/debug-simple.php';
    
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response !== false) {
        echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
        echo "<h5>Respuesta de la API debug-simple:</h5>";
        echo "<pre>" . htmlspecialchars($response) . "</pre>";
        echo "</div>";
        
        $data = json_decode($response, true);
        if ($data !== null) {
            if (isset($data['success']) && $data['success']) {
                echo "<div style='color: green;'>✅ API debug-simple funciona correctamente</div>";
            } else {
                echo "<div style='color: red;'>❌ API debug-simple devolvió error</div>";
            }
        } else {
            echo "<div style='color: red;'>❌ API debug-simple devolvió HTML en lugar de JSON</div>";
        }
    } else {
        echo "<div style='color: red;'>❌ No se pudo acceder a la API debug-simple</div>";
    }
    
} catch (Exception $e) {
    echo "<div style='color: red;'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "<h3>6. Verificación de Archivos:</h3>";

$files = [
    'api/users/debug-simple.php' => 'API debug simplificada',
    'api/users/manage-robust.php' => 'API robusta',
    'config/database.php' => 'Configuración de base de datos',
    'user-management-v2.js' => 'JavaScript actualizado'
];

foreach ($files as $file => $description) {
    if (file_exists($file)) {
        echo "<div style='color: green;'>✅ {$description}: {$file}</div>";
    } else {
        echo "<div style='color: red;'>❌ {$description}: {$file} - NO ENCONTRADO</div>";
    }
}

echo "<h3>7. Próximos Pasos:</h3>";
echo "<div style='background: #d1ecf1; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4 style='color: #0c5460;'>Para continuar el diagnóstico:</h4>";
echo "<ol style='color: #0c5460;'>";
echo "<li><strong>Ejecutar:</strong> <a href='test-modal-edit.php' target='_blank'>test-modal-edit.php</a></li>";
echo "<li><strong>Probar:</strong> <a href='test-debug-api.html' target='_blank'>test-debug-api.html</a></li>";
echo "<li><strong>Verificar logs:</strong> Revisar logs de PHP/Apache</li>";
echo "<li><strong>Probar API:</strong> Acceder directamente a api/users/debug-simple.php</li>";
echo "</ol>";
echo "</div>";

echo "<hr>";
echo "<p><small>Verificación completada el " . date('Y-m-d H:i:s') . "</small></p>";
?>


