<?php
/**
 * Prueba Rápida de la API Corregida
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

echo "<h1>Prueba Rápida de la API Corregida</h1>";
echo "<p>Sistema TJSMEDICAL - Portal de Estudios Médicos</p>";

echo "<h3>1. Probando API de Usuarios:</h3>";

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
            echo "<div style='color: green;'>✓ API funciona correctamente</div>";
            
            // Verificar estructura de permisos
            if (!empty($data['data']['users'])) {
                echo "<div style='background: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
                echo "<h5>Verificación de Permisos:</h5>";
                
                foreach ($data['data']['users'] as $user) {
                    echo "<div style='margin: 5px 0;'>";
                    echo "<strong>{$user['nombre']} {$user['apellido']}</strong> - ";
                    
                    if (is_array($user['permisos'])) {
                        echo "<span style='color: green;'>✓ Permisos como array: " . count($user['permisos']) . " permisos</span>";
                        if (!empty($user['permisos'])) {
                            echo " [" . implode(', ', $user['permisos']) . "]";
                        }
                    } else {
                        echo "<span style='color: red;'>✗ Permisos no es array: " . gettype($user['permisos']) . "</span>";
                    }
                    echo "</div>";
                }
                echo "</div>";
            }
            
        } else {
            echo "<div style='color: red;'>✗ API devolvió error</div>";
            echo "<div style='background: #f8d7da; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "<pre>" . htmlspecialchars($response) . "</pre>";
            echo "</div>";
        }
    } else {
        echo "<div style='color: red;'>✗ No se pudo acceder a la API</div>";
    }
} catch (Exception $e) {
    echo "<div style='color: red;'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "<h3>2. Prueba JavaScript:</h3>";
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
        console.log('✓ API funciona');
        data.data.users.forEach(user => {
            console.log(user.nombre + ':', typeof user.permisos, user.permisos);
        });
    } else {
        console.log('✗ Error:', data.error);
    }
})
.catch(error => {
    console.error('Error:', error);
});";
echo "</pre>";
echo "</ol>";
echo "</div>";

echo "<h3>3. Prueba del Panel:</h3>";
echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4 style='color: #155724;'>Para probar el panel completo:</h4>";
echo "<ol style='color: #155724;'>";
echo "<li><strong>Accede al dashboard:</strong> <a href='dashboard-unified.html' target='_blank'>dashboard-unified.html</a></li>";
echo "<li><strong>Haz clic en 'Gestión Usuarios'</strong> en el sidebar</li>";
echo "<li><strong>Verifica que NO hay errores</strong> en la consola del navegador</li>";
echo "<li><strong>Los permisos deben mostrarse</strong> como badges en la tabla</li>";
echo "</ol>";
echo "</div>";

echo "<div style='background: #d1ecf1; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4 style='color: #0c5460;'>Correcciones Aplicadas:</h4>";
echo "<ul style='color: #0c5460;'>";
echo "<li>✅ <strong>API procesa permisos</strong> como arrays antes de enviar</li>";
echo "<li>✅ <strong>JavaScript maneja</strong> permisos como string o array</li>";
echo "<li>✅ <strong>Validación robusta</strong> en renderUserPermissions</li>";
echo "<li>✅ <strong>Sin errores de map</strong> en la consola</li>";
echo "</ul>";
echo "</div>";

echo "<hr>";
echo "<p><small>Prueba completada el " . date('Y-m-d H:i:s') . "</small></p>";
?>


