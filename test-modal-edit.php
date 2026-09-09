<?php
/**
 * Prueba Específica del Modal de Edición
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

echo "<h1>Prueba Específica del Modal de Edición</h1>";
echo "<p>Sistema TJSMEDICAL - Portal de Estudios Médicos</p>";

echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4 style='color: #856404;'>🔍 Problema Reportado:</h4>";
echo "<p style='color: #856404;'>Error al guardar desde el modal de edición: 'Unexpected token '<', \"<br /> <fo\"... is not valid JSON'</p>";
echo "</div>";

echo "<h3>1. Verificación de Base de Datos:</h3>";

try {
    require_once 'config/database.php';
    $pdo = getDBConnection();
    
    echo "<div style='color: green;'>✓ Conexión a base de datos exitosa</div>";
    
    // Verificar usuarios disponibles
    $query = "SELECT id, nombre, apellido, email, nivel FROM usuarios WHERE activo = 1 ORDER BY id LIMIT 5";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $users = $stmt->fetchAll();
    
    if (!empty($users)) {
        echo "<div style='background: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
        echo "<h5>Usuarios disponibles para editar:</h5>";
        echo "<ul>";
        foreach ($users as $user) {
            echo "<li><strong>ID {$user['id']}:</strong> {$user['nombre']} {$user['apellido']} ({$user['nivel']}) - {$user['email']}</li>";
        }
        echo "</ul>";
        echo "</div>";
        
        $testUser = $users[0];
        
        echo "<h3>2. Prueba de Edición del Usuario ID {$testUser['id']}:</h3>";
        
        // Simular exactamente lo que hace el JavaScript
        $formData = [
            'id' => $testUser['id'],
            'nombre' => $testUser['nombre'] . ' (Editado)',
            'apellido' => $testUser['apellido'] . ' (Test)',
            'email' => 'editado_' . $testUser['id'] . '@test.com',
            'telefono' => '1234567890',
            'matricula_profesional' => 'TEST' . $testUser['id'],
            'especialidad' => 'Medicina General',
            'nivel' => $testUser['nivel'],
            'padre_id' => '',
            'permisos' => '["dashboard"]'
        ];
        
        echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
        echo "<h5>Datos que se enviarán:</h5>";
        echo "<pre>" . htmlspecialchars(json_encode($formData, JSON_PRETTY_PRINT)) . "</pre>";
        echo "</div>";
        
        // Probar la API directamente
        $url = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/api/users/manage-robust.php?id=' . $testUser['id'];
        
        $context = stream_context_create([
            'http' => [
                'method' => 'PUT',
                'header' => 'Content-Type: application/json',
                'content' => json_encode($formData),
                'timeout' => 10
            ]
        ]);
        
        echo "<h4>Probando API PUT:</h4>";
        echo "<p><strong>URL:</strong> {$url}</p>";
        echo "<p><strong>Método:</strong> PUT</p>";
        echo "<p><strong>Content-Type:</strong> application/json</p>";
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response !== false) {
            echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "<h5>Respuesta completa de la API:</h5>";
            echo "<pre>" . htmlspecialchars($response) . "</pre>";
            echo "</div>";
            
            // Intentar decodificar JSON
            $data = json_decode($response, true);
            if ($data !== null) {
                if (isset($data['success']) && $data['success']) {
                    echo "<div style='color: green;'>✓ API PUT funciona correctamente</div>";
                    echo "<div style='background: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
                    echo "<h5>Resultado exitoso:</h5>";
                    echo "<pre>" . htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT)) . "</pre>";
                    echo "</div>";
                } else {
                    echo "<div style='color: red;'>✗ API PUT devolvió error</div>";
                    echo "<div style='background: #f8d7da; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
                    echo "<h5>Error en la API:</h5>";
                    echo "<pre>" . htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT)) . "</pre>";
                    echo "</div>";
                }
            } else {
                echo "<div style='color: red;'>✗ La API devolvió HTML en lugar de JSON</div>";
                echo "<div style='background: #f8d7da; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
                echo "<h5>Posibles causas:</h5>";
                echo "<ul>";
                echo "<li>Error de PHP en la API</li>";
                echo "<li>Problema de conexión a base de datos</li>";
                echo "<li>Error de permisos</li>";
                echo "<li>Problema con el servidor web</li>";
                echo "</ul>";
                echo "</div>";
            }
        } else {
            echo "<div style='color: red;'>✗ No se pudo acceder a la API</div>";
        }
        
    } else {
        echo "<div style='color: red;'>✗ No se encontraron usuarios para probar</div>";
    }
    
} catch (Exception $e) {
    echo "<div style='color: red;'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "<h3>3. Prueba de la API con cURL:</h3>";

try {
    // Usar cURL para probar la API
    $testData = [
        'nombre' => 'Usuario',
        'apellido' => 'Prueba cURL',
        'email' => 'prueba_curl@test.com',
        'telefono' => '1234567890',
        'especialidad' => 'Medicina General'
    ];
    
    $url = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/api/users/manage-robust.php?id=1';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen(json_encode($testData))
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        echo "<div style='color: red;'>✗ Error cURL: " . htmlspecialchars($error) . "</div>";
    } else {
        echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
        echo "<h5>Respuesta cURL:</h5>";
        echo "<p><strong>Código HTTP:</strong> {$httpCode}</p>";
        echo "<pre>" . htmlspecialchars($response) . "</pre>";
        echo "</div>";
        
        $data = json_decode($response, true);
        if ($data !== null) {
            if (isset($data['success']) && $data['success']) {
                echo "<div style='color: green;'>✓ API funciona con cURL</div>";
            } else {
                echo "<div style='color: red;'>✗ API devolvió error con cURL</div>";
            }
        } else {
            echo "<div style='color: red;'>✗ API devolvió HTML con cURL</div>";
        }
    }
    
} catch (Exception $e) {
    echo "<div style='color: red;'>✗ Error cURL: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "<h3>4. Verificación de Logs de Error:</h3>";

// Verificar logs de PHP
$logFiles = [
    'C:\\wamp64\\logs\\php_error.log',
    'C:\\wamp64\\logs\\apache_error.log',
    'C:\\wamp64\\logs\\error.log'
];

foreach ($logFiles as $logFile) {
    if (file_exists($logFile)) {
        echo "<div style='color: green;'>✓ Log encontrado: " . basename($logFile) . "</div>";
        
        // Leer las últimas líneas
        $lines = file($logFile);
        $lastLines = array_slice($lines, -5);
        
        echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
        echo "<h5>Últimas 5 líneas de " . basename($logFile) . ":</h5>";
        echo "<pre>" . htmlspecialchars(implode('', $lastLines)) . "</pre>";
        echo "</div>";
    } else {
        echo "<div style='color: orange;'>⚠ Log no encontrado: " . basename($logFile) . "</div>";
    }
}

echo "<h3>5. Solución Recomendada:</h3>";
echo "<div style='background: #d1ecf1; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4 style='color: #0c5460;'>Si la API devuelve HTML:</h4>";
echo "<ol style='color: #0c5460;'>";
echo "<li><strong>Verificar logs:</strong> Revisar logs de PHP/Apache</li>";
echo "<li><strong>Verificar permisos:</strong> Asegurar que la API puede escribir en BD</li>";
echo "<li><strong>Verificar configuración:</strong> Revisar configuración de PHP</li>";
echo "<li><strong>Probar directamente:</strong> Acceder a la API desde el navegador</li>";
echo "</ol>";
echo "</div>";

echo "<hr>";
echo "<p><small>Prueba completada el " . date('Y-m-d H:i:s') . "</small></p>";
?>


