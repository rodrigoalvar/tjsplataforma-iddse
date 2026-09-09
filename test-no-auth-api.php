<?php
/**
 * Prueba de API Sin Autenticación
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

echo "<h1>Prueba de API Sin Autenticación</h1>";
echo "<p>Sistema TJSMEDICAL - Portal de Estudios Médicos</p>";

echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4 style='color: #856404;'>⚠ ADVERTENCIA</h4>";
echo "<p style='color: #856404;'>Esta API es solo para pruebas y NO tiene autenticación. En producción debe implementarse la autenticación correcta.</p>";
echo "</div>";

echo "<h3>1. Verificación de Archivos:</h3>";

$files = [
    'api/users/manage-test.php' => 'API de prueba sin autenticación',
    'api/users/permissions-simple.php' => 'API de permisos',
    'user-management.js' => 'JavaScript del panel'
];

foreach ($files as $file => $description) {
    if (file_exists($file)) {
        echo "<div style='color: green;'>✓ {$description}: {$file}</div>";
    } else {
        echo "<div style='color: red;'>✗ {$description}: {$file} - NO ENCONTRADO</div>";
    }
}

echo "<h3>2. Prueba de API Sin Autenticación:</h3>";

try {
    $url = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/api/users/manage-test.php';
    
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response !== false) {
        $data = json_decode($response, true);
        if ($data && isset($data['success']) && $data['success']) {
            echo "<div style='color: green;'>✓ API de prueba funciona correctamente</div>";
            echo "<div style='background: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "<p><strong>Usuarios encontrados:</strong> " . count($data['data']['users']) . "</p>";
            echo "<p><strong>Jerarquías:</strong> " . count($data['data']['hierarchy']) . "</p>";
            echo "</div>";
            
            // Mostrar algunos usuarios
            if (!empty($data['data']['users'])) {
                echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
                echo "<h5>Usuarios encontrados:</h5>";
                echo "<ul>";
                foreach (array_slice($data['data']['users'], 0, 5) as $user) {
                    echo "<li><strong>{$user['nombre']} {$user['apellido']}</strong> ({$user['email']}) - Nivel: {$user['nivel']}</li>";
                }
                if (count($data['data']['users']) > 5) {
                    echo "<li>... y " . (count($data['data']['users']) - 5) . " más</li>";
                }
                echo "</ul>";
                echo "</div>";
            }
            
        } else {
            echo "<div style='color: red;'>✗ API de prueba devolvió error</div>";
            echo "<div style='background: #f8d7da; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "<pre>" . htmlspecialchars($response) . "</pre>";
            echo "</div>";
        }
    } else {
        echo "<div style='color: red;'>✗ No se pudo acceder a la API de prueba</div>";
    }
} catch (Exception $e) {
    echo "<div style='color: red;'>✗ Error probando API: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "<h3>3. Prueba de API de Permisos:</h3>";

try {
    $url = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/api/users/permissions-simple.php';
    
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response !== false) {
        $data = json_decode($response, true);
        if ($data && isset($data['success']) && $data['success']) {
            echo "<div style='color: green;'>✓ API de permisos funciona correctamente</div>";
            echo "<div style='background: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "<h5>Categorías de permisos:</h5>";
            echo "<ul>";
            foreach ($data['data'] as $category => $permissions) {
                echo "<li><strong>{$category}:</strong> " . count($permissions) . " permisos</li>";
            }
            echo "</ul>";
            echo "</div>";
        } else {
            echo "<div style='color: red;'>✗ API de permisos devolvió error</div>";
        }
    } else {
        echo "<div style='color: red;'>✗ No se pudo acceder a la API de permisos</div>";
    }
} catch (Exception $e) {
    echo "<div style='color: red;'>✗ Error probando API de permisos: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "<h3>4. Instrucciones de Prueba:</h3>";
echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4 style='color: #155724;'>Para probar el panel completo:</h4>";
echo "<ol style='color: #155724;'>";
echo "<li><strong>Accede al dashboard:</strong> <a href='dashboard-unified.html' target='_blank'>dashboard-unified.html</a></li>";
echo "<li><strong>Haz clic en 'Gestión Usuarios'</strong> en el sidebar</li>";
echo "<li><strong>Verifica que no hay errores</strong> en la consola del navegador</li>";
echo "<li><strong>Prueba todas las funcionalidades</strong> del panel</li>";
echo "</ol>";
echo "</div>";

echo "<h3>5. Funcionalidades Disponibles:</h3>";
echo "<div style='background: #e9ecef; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<ul>";
echo "<li>📊 <strong>Dashboard:</strong> Estadísticas de usuarios en tiempo real</li>";
echo "<li>👥 <strong>Lista de usuarios:</strong> Vista completa con filtros</li>";
echo "<li>🌳 <strong>Vista jerárquica:</strong> Relaciones padre-hijo</li>";
echo "<li>➕ <strong>Crear usuarios:</strong> Formulario completo</li>";
echo "<li>✏️ <strong>Editar usuarios:</strong> Modificar datos existentes</li>";
echo "<li>🔐 <strong>Gestionar permisos:</strong> Asignar permisos granulares</li>";
echo "<li>🌐 <strong>Jerarquías:</strong> Asignar usuarios padre-hijo</li>";
echo "<li>🔄 <strong>Reset contraseñas:</strong> Generar nuevas contraseñas</li>";
echo "<li>🗑️ <strong>Eliminar usuarios:</strong> Con validaciones de seguridad</li>";
echo "</ul>";
echo "</div>";

echo "<h3>6. Prueba JavaScript:</h3>";
echo "<div style='background: #e9ecef; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4>Para probar desde JavaScript:</h4>";
echo "<ol>";
echo "<li><strong>Abre la consola del navegador</strong> (F12)</li>";
echo "<li><strong>Copia y pega este código:</strong></li>";
echo "<pre>";
echo "// Probar API de usuarios
fetch('api/users/manage-test.php')
.then(response => response.json())
.then(data => {
    console.log('Usuarios:', data);
    if (data.success) {
        console.log('✓ API de usuarios funciona');
        console.log('Total usuarios:', data.data.users.length);
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

echo "<div style='background: #d1ecf1; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4 style='color: #0c5460;'>Nota Importante:</h4>";
echo "<p style='color: #0c5460;'>Esta API de prueba NO tiene autenticación para facilitar las pruebas. Una vez que confirmes que el panel funciona correctamente, deberás implementar la autenticación adecuada en producción.</p>";
echo "</div>";

echo "<hr>";
echo "<p><small>Prueba completada el " . date('Y-m-d H:i:s') . "</small></p>";
?>


