<?php
/**
 * Script de Prueba de APIs de Gestión de Usuarios
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

echo "<h1>Prueba de APIs de Gestión de Usuarios</h1>";
echo "<p>Sistema TJSMEDICAL - Portal de Estudios Médicos</p>";

echo "<h3>1. Verificación de Archivos de API:</h3>";

$apiFiles = [
    'api/users/manage-simple.php' => 'API de gestión de usuarios',
    'api/users/permissions-simple.php' => 'API de permisos del sistema',
    'api/users/check-permission-simple.php' => 'API de verificación de permisos'
];

foreach ($apiFiles as $file => $description) {
    if (file_exists($file)) {
        echo "<div style='color: green;'>✓ {$description}: {$file}</div>";
    } else {
        echo "<div style='color: red;'>✗ {$description}: {$file} - NO ENCONTRADO</div>";
    }
}

echo "<h3>2. Prueba de API de Permisos:</h3>";

try {
    $url = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/api/users/permissions-simple.php';
    $response = file_get_contents($url);
    
    if ($response) {
        $data = json_decode($response, true);
        if ($data && isset($data['success']) && $data['success']) {
            echo "<div style='color: green;'>✓ API de permisos funciona correctamente</div>";
            echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "<h5>Categorías de permisos encontradas:</h5>";
            echo "<ul>";
            foreach ($data['data'] as $category => $permissions) {
                echo "<li><strong>{$category}:</strong> " . count($permissions) . " permisos</li>";
            }
            echo "</ul>";
            echo "</div>";
        } else {
            echo "<div style='color: red;'>✗ API de permisos devolvió error</div>";
            echo "<div style='background: #f8d7da; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "<pre>" . htmlspecialchars($response) . "</pre>";
            echo "</div>";
        }
    } else {
        echo "<div style='color: red;'>✗ No se pudo acceder a la API de permisos</div>";
    }
} catch (Exception $e) {
    echo "<div style='color: red;'>✗ Error probando API de permisos: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "<h3>3. Prueba de API de Gestión de Usuarios:</h3>";

// Verificar si hay sesión activa
if (isset($_COOKIE['session_token'])) {
    echo "<div style='color: green;'>✓ Cookie de sesión encontrada</div>";
    
    try {
        $url = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/api/users/manage-simple.php';
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'Cookie: session_token=' . $_COOKIE['session_token']
            ]
        ]);
        
        $response = file_get_contents($url, false, $context);
        
        if ($response) {
            $data = json_decode($response, true);
            if ($data && isset($data['success']) && $data['success']) {
                echo "<div style='color: green;'>✓ API de gestión de usuarios funciona correctamente</div>";
                echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
                echo "<h5>Usuarios encontrados:</h5>";
                echo "<p><strong>Total usuarios:</strong> " . count($data['data']['users']) . "</p>";
                echo "<p><strong>Jerarquías:</strong> " . count($data['data']['hierarchy']) . "</p>";
                echo "</div>";
            } else {
                echo "<div style='color: red;'>✗ API de gestión de usuarios devolvió error</div>";
                echo "<div style='background: #f8d7da; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
                echo "<pre>" . htmlspecialchars($response) . "</pre>";
                echo "</div>";
            }
        } else {
            echo "<div style='color: red;'>✗ No se pudo acceder a la API de gestión de usuarios</div>";
        }
    } catch (Exception $e) {
        echo "<div style='color: red;'>✗ Error probando API de gestión de usuarios: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
} else {
    echo "<div style='color: orange;'>⚠ No se encontró cookie de sesión</div>";
    echo "<div style='background: #fff3cd; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
    echo "<p><strong>Para probar la API de gestión de usuarios:</strong></p>";
    echo "<ol>";
    echo "<li>Inicia sesión como cualquier usuario: <a href='login.html'>login.html</a></li>";
    echo "<li>Luego vuelve a esta página</li>";
    echo "</ol>";
    echo "</div>";
}

echo "<h3>4. Prueba JavaScript:</h3>";
echo "<div style='background: #e9ecef; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4>Para probar desde JavaScript:</h4>";
echo "<ol>";
echo "<li><strong>Abre la consola del navegador</strong> (F12)</li>";
echo "<li><strong>Copia y pega este código:</strong></li>";
echo "<pre>";
echo "// Probar API de permisos
fetch('api/users/permissions-simple.php')
.then(response => response.json())
.then(data => {
    console.log('Permisos:', data);
    if (data.success) {
        console.log('✓ API de permisos funciona');
    } else {
        console.log('✗ Error en API de permisos:', data.error);
    }
})
.catch(error => {
    console.error('Error:', error);
});

// Probar API de usuarios
fetch('api/users/manage-simple.php')
.then(response => response.json())
.then(data => {
    console.log('Usuarios:', data);
    if (data.success) {
        console.log('✓ API de usuarios funciona');
    } else {
        console.log('✗ Error en API de usuarios:', data.error);
    }
})
.catch(error => {
    console.error('Error:', error);
});";
echo "</pre>";
echo "</ol>";
echo "</div>";

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

echo "<hr>";
echo "<p><small>Prueba completada el " . date('Y-m-d H:i:s') . "</small></p>";
?>


