<?php
/**
 * Diagnóstico de Error 500 en API PUT
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

echo "<h1>Diagnóstico de Error 500 en API PUT</h1>";
echo "<p>Sistema TJSMEDICAL - Portal de Estudios Médicos</p>";

echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4 style='color: #856404;'>🔍 Problema Reportado:</h4>";
echo "<p style='color: #856404;'>Error 500 en PUT http://localhost/portal_estudios/api/users/manage-test.php?id=2</p>";
echo "</div>";

echo "<h3>1. Verificación de Archivos:</h3>";

$files = [
    'api/users/manage-test.php' => 'API de gestión de usuarios',
    'config/database.php' => 'Configuración de base de datos'
];

foreach ($files as $file => $description) {
    if (file_exists($file)) {
        echo "<div style='color: green;'>✓ {$description}: {$file}</div>";
    } else {
        echo "<div style='color: red;'>✗ {$description}: {$file} - NO ENCONTRADO</div>";
    }
}

echo "<h3>2. Verificación de Base de Datos:</h3>";

try {
    require_once 'config/database.php';
    $pdo = getDBConnection();
    
    echo "<div style='color: green;'>✓ Conexión a base de datos exitosa</div>";
    
    // Verificar que el usuario ID 2 existe
    $query = "SELECT id, nombre, apellido, email, nivel FROM usuarios WHERE id = 2";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $user = $stmt->fetch();
    
    if ($user) {
        echo "<div style='background: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
        echo "<h5>Usuario ID 2 encontrado:</h5>";
        echo "<ul>";
        echo "<li><strong>ID:</strong> {$user['id']}</li>";
        echo "<li><strong>Nombre:</strong> {$user['nombre']} {$user['apellido']}</li>";
        echo "<li><strong>Email:</strong> {$user['email']}</li>";
        echo "<li><strong>Nivel:</strong> {$user['nivel']}</li>";
        echo "</ul>";
        echo "</div>";
    } else {
        echo "<div style='color: red;'>✗ Usuario ID 2 no encontrado</div>";
    }
    
} catch (Exception $e) {
    echo "<div style='color: red;'>✗ Error conectando a base de datos: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "<h3>3. Prueba de API PUT:</h3>";

try {
    // Datos de prueba para actualizar usuario ID 2
    $testData = [
        'nombre' => 'Usuario',
        'apellido' => 'Prueba',
        'email' => 'prueba@test.com',
        'telefono' => '1234567890',
        'matricula_profesional' => 'TEST001',
        'especialidad' => 'Medicina General',
        'nivel' => 'user'
    ];
    
    $url = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/api/users/manage-test.php?id=2';
    
    $context = stream_context_create([
        'http' => [
            'method' => 'PUT',
            'header' => 'Content-Type: application/json',
            'content' => json_encode($testData),
            'timeout' => 10
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response !== false) {
        echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
        echo "<h5>Respuesta de la API:</h5>";
        echo "<pre>" . htmlspecialchars($response) . "</pre>";
        echo "</div>";
        
        // Intentar decodificar JSON
        $data = json_decode($response, true);
        if ($data !== null) {
            if (isset($data['success']) && $data['success']) {
                echo "<div style='color: green;'>✓ API PUT funciona correctamente</div>";
            } else {
                echo "<div style='color: red;'>✗ API PUT devolvió error: " . ($data['error'] ?? 'Error desconocido') . "</div>";
            }
        } else {
            echo "<div style='color: red;'>✗ API devolvió HTML en lugar de JSON</div>";
            echo "<div style='background: #f8d7da; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "<h5>Posibles causas:</h5>";
            echo "<ul>";
            echo "<li>Error de PHP en la API</li>";
            echo "<li>Problema de conexión a base de datos</li>";
            echo "<li>Error de sintaxis en el código</li>";
            echo "<li>Problema de permisos</li>";
            echo "</ul>";
            echo "</div>";
        }
    } else {
        echo "<div style='color: red;'>✗ No se pudo acceder a la API</div>";
    }
    
} catch (Exception $e) {
    echo "<div style='color: red;'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "<h3>4. Verificación de Logs de Error:</h3>";

// Verificar si hay logs de error de PHP
$errorLogs = [
    'C:\\wamp64\\logs\\php_error.log',
    'C:\\wamp64\\logs\\apache_error.log',
    'C:\\wamp64\\logs\\error.log'
];

foreach ($errorLogs as $logFile) {
    if (file_exists($logFile)) {
        echo "<div style='color: green;'>✓ Log encontrado: {$logFile}</div>";
        
        // Leer las últimas líneas del log
        $lines = file($logFile);
        $lastLines = array_slice($lines, -10);
        
        echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
        echo "<h5>Últimas 10 líneas del log:</h5>";
        echo "<pre>" . htmlspecialchars(implode('', $lastLines)) . "</pre>";
        echo "</div>";
    } else {
        echo "<div style='color: orange;'>⚠ Log no encontrado: {$logFile}</div>";
    }
}

echo "<h3>5. Prueba de API POST (para comparar):</h3>";

try {
    $testData = [
        'nombre' => 'Usuario',
        'apellido' => 'Prueba POST',
        'email' => 'prueba_post_' . time() . '@test.com',
        'password' => 'test123',
        'nivel' => 'user',
        'telefono' => '1234567890',
        'matricula_profesional' => 'TEST' . time()
    ];
    
    $url = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/api/users/manage-test.php';
    
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => json_encode($testData),
            'timeout' => 10
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response !== false) {
        $data = json_decode($response, true);
        if ($data && isset($data['success']) && $data['success']) {
            echo "<div style='color: green;'>✓ API POST funciona correctamente</div>";
        } else {
            echo "<div style='color: red;'>✗ API POST devolvió error</div>";
        }
    } else {
        echo "<div style='color: red;'>✗ No se pudo acceder a la API POST</div>";
    }
    
} catch (Exception $e) {
    echo "<div style='color: red;'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "<h3>6. Soluciones Recomendadas:</h3>";
echo "<div style='background: #d1ecf1; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4 style='color: #0c5460;'>Pasos para solucionar:</h4>";
echo "<ol style='color: #0c5460;'>";
echo "<li><strong>Verificar logs de error:</strong> Revisar logs de PHP/Apache</li>";
echo "<li><strong>Verificar base de datos:</strong> Confirmar que el usuario ID 2 existe</li>";
echo "<li><strong>Verificar permisos:</strong> Asegurar que la API puede escribir en BD</li>";
echo "<li><strong>Probar API directamente:</strong> Usar herramientas como Postman</li>";
echo "<li><strong>Revisar código:</strong> Verificar sintaxis en manage-test.php</li>";
echo "</ol>";
echo "</div>";

echo "<hr>";
echo "<p><small>Diagnóstico completado el " . date('Y-m-d H:i:s') . "</small></p>";
?>


