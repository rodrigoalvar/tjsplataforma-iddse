<?php
/**
 * Script de Verificación de Rutas de APIs
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

echo "<h1>Verificación de Rutas de APIs</h1>";
echo "<p>Sistema TJSMEDICAL - Portal de Estudios Médicos</p>";

echo "<h3>1. Verificación de Archivos de Configuración:</h3>";

$configFiles = [
    'config/database.php' => 'Configuración de base de datos',
    'api/users/manage-simple.php' => 'API de gestión de usuarios',
    'api/users/permissions-simple.php' => 'API de permisos',
    'api/users/check-permission-simple.php' => 'API de verificación de permisos'
];

foreach ($configFiles as $file => $description) {
    if (file_exists($file)) {
        echo "<div style='color: green;'>✓ {$description}: {$file}</div>";
    } else {
        echo "<div style='color: red;'>✗ {$description}: {$file} - NO ENCONTRADO</div>";
    }
}

echo "<h3>2. Verificación de Rutas desde APIs:</h3>";

// Verificar rutas desde diferentes ubicaciones
$apiLocations = [
    'api/users/' => '../../config/database.php',
    'api/' => '../config/database.php',
    '' => 'config/database.php'
];

foreach ($apiLocations as $location => $path) {
    $fullPath = $location . $path;
    if (file_exists($fullPath)) {
        echo "<div style='color: green;'>✓ Ruta correcta desde {$location}: {$path}</div>";
    } else {
        echo "<div style='color: red;'>✗ Ruta incorrecta desde {$location}: {$path}</div>";
    }
}

echo "<h3>3. Prueba de Inclusión de Archivos:</h3>";

// Probar incluir el archivo de configuración
try {
    if (file_exists('config/database.php')) {
        require_once 'config/database.php';
        echo "<div style='color: green;'>✓ Archivo config/database.php incluido correctamente</div>";
        
        // Probar conexión a la base de datos
        try {
            $pdo = getDBConnection();
            echo "<div style='color: green;'>✓ Conexión a la base de datos exitosa</div>";
        } catch (Exception $e) {
            echo "<div style='color: red;'>✗ Error de conexión a la base de datos: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    } else {
        echo "<div style='color: red;'>✗ Archivo config/database.php no encontrado</div>";
    }
} catch (Exception $e) {
    echo "<div style='color: red;'>✗ Error incluyendo archivo: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "<h3>4. Prueba de APIs Corregidas:</h3>";

// Probar API de permisos
try {
    $url = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/api/users/permissions-simple.php';
    $context = stream_context_create([
        'http' => [
            'timeout' => 10
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response !== false) {
        $data = json_decode($response, true);
        if ($data && isset($data['success']) && $data['success']) {
            echo "<div style='color: green;'>✓ API de permisos funciona correctamente</div>";
        } else {
            echo "<div style='color: orange;'>⚠ API de permisos devolvió respuesta inesperada</div>";
            echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "<pre>" . htmlspecialchars(substr($response, 0, 500)) . "</pre>";
            echo "</div>";
        }
    } else {
        echo "<div style='color: red;'>✗ No se pudo acceder a la API de permisos</div>";
    }
} catch (Exception $e) {
    echo "<div style='color: red;'>✗ Error probando API de permisos: " . htmlspecialchars($e->getMessage()) . "</div>";
}

// Probar API de gestión de usuarios (solo si hay sesión)
if (isset($_COOKIE['session_token'])) {
    try {
        $url = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/api/users/manage-simple.php';
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'Cookie: session_token=' . $_COOKIE['session_token'],
                'timeout' => 10
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response !== false) {
            $data = json_decode($response, true);
            if ($data && isset($data['success']) && $data['success']) {
                echo "<div style='color: green;'>✓ API de gestión de usuarios funciona correctamente</div>";
            } else {
                echo "<div style='color: orange;'>⚠ API de gestión de usuarios devolvió respuesta inesperada</div>";
                echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
                echo "<pre>" . htmlspecialchars(substr($response, 0, 500)) . "</pre>";
                echo "</div>";
            }
        } else {
            echo "<div style='color: red;'>✗ No se pudo acceder a la API de gestión de usuarios</div>";
        }
    } catch (Exception $e) {
        echo "<div style='color: red;'>✗ Error probando API de gestión de usuarios: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
} else {
    echo "<div style='color: blue;'>ℹ No hay sesión activa - API de gestión de usuarios requiere autenticación</div>";
}

echo "<h3>5. Instrucciones de Prueba:</h3>";
echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4 style='color: #155724;'>Para probar el panel completo:</h4>";
echo "<ol style='color: #155724;'>";
echo "<li><strong>Inicia sesión:</strong> <a href='login.html' target='_blank'>login.html</a></li>";
echo "<li><strong>Accede al dashboard:</strong> <a href='dashboard-unified.html' target='_blank'>dashboard-unified.html</a></li>";
echo "<li><strong>Haz clic en 'Gestión Usuarios'</strong> en el sidebar</li>";
echo "<li><strong>Verifica que no hay errores</strong> en la consola del navegador</li>";
echo "<li><strong>Prueba las funcionalidades</strong> del panel</li>";
echo "</ol>";
echo "</div>";

echo "<h3>6. Prueba JavaScript:</h3>";
echo "<div style='background: #e9ecef; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4>Para probar desde JavaScript:</h4>";
echo "<ol>";
echo "<li><strong>Abre la consola del navegador</strong> (F12)</li>";
echo "<li><strong>Copia y pega este código:</strong></li>";
echo "<pre>";
echo "// Probar API de permisos
fetch('api/users/permissions-simple.php')
.then(response => {
    console.log('Status:', response.status);
    return response.text();
})
.then(text => {
    console.log('Response:', text);
    try {
        const data = JSON.parse(text);
        console.log('Parsed data:', data);
        if (data.success) {
            console.log('✓ API de permisos funciona');
        } else {
            console.log('✗ Error en API de permisos:', data.error);
        }
    } catch (e) {
        console.log('✗ Error parsing JSON:', e);
        console.log('Raw response:', text);
    }
})
.catch(error => {
    console.error('Error:', error);
});";
echo "</pre>";
echo "</ol>";
echo "</div>";

echo "<hr>";
echo "<p><small>Verificación completada el " . date('Y-m-d H:i:s') . "</small></p>";
?>


