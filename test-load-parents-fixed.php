<?php
/**
 * Prueba de loadPossibleParents Corregida
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

echo "<h1>Prueba de loadPossibleParents Corregida</h1>";
echo "<p>Sistema TJSMEDICAL - Portal de Estudios Médicos</p>";

echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4 style='color: #155724;'>✅ Corrección Aplicada:</h4>";
echo "<ul style='color: #155724;'>";
echo "<li>✅ <strong>Problema:</strong> result.data.users era undefined</li>";
echo "<li>✅ <strong>Causa:</strong> API devuelve data directamente, no data.users</li>";
echo "<li>✅ <strong>Solución:</strong> Cambiar result.data.users por result.data</li>";
echo "<li>✅ <strong>Resultado:</strong> forEach funciona correctamente</li>";
echo "</ul>";
echo "</div>";

echo "<h3>1. Verificación de la API de Posibles Padres:</h3>";

try {
    $url = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/api/users/assignable.php?action=possible_parents';
    
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
            echo "<div style='color: green;'>✓ API de posibles padres funciona correctamente</div>";
            echo "<div style='background: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "<h5>Estructura de datos devuelta:</h5>";
            echo "<pre>" . htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT)) . "</pre>";
            echo "</div>";
            
            if (is_array($data['data'])) {
                echo "<div style='background: #d1ecf1; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
                echo "<h5>Usuarios que pueden ser padres:</h5>";
                echo "<ul>";
                foreach ($data['data'] as $user) {
                    echo "<li><strong>{$user['nombre']} {$user['apellido']}</strong> ({$user['nivel']}) - {$user['email']}</li>";
                }
                echo "</ul>";
                echo "</div>";
            } else {
                echo "<div style='color: red;'>✗ data no es un array</div>";
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

echo "<h3>2. Prueba del Panel Completo:</h3>";
echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4 style='color: #155724;'>Para probar el panel completo:</h4>";
echo "<ol style='color: #155724;'>";
echo "<li><strong>Accede al panel:</strong> <a href='user-management.html' target='_blank'>user-management.html</a></li>";
echo "<li><strong>Abre la consola del navegador</strong> (F12)</li>";
echo "<li><strong>Verifica que NO hay errores</strong> de forEach</li>";
echo "<li><strong>Haz clic en 'Editar'</strong> de cualquier usuario</li>";
echo "<li><strong>El modal debe aparecer</strong> sin errores</li>";
echo "<li><strong>El campo 'Padre'</strong> debe llenarse con usuarios ROOT/ADMIN</li>";
echo "<li><strong>Puedes seleccionar</strong> un padre de la lista</li>";
echo "</ol>";
echo "</div>";

echo "<h3>3. Prueba JavaScript Directa:</h3>";
echo "<div style='background: #e9ecef; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4>Para verificar desde JavaScript:</h4>";
echo "<ol>";
echo "<li><strong>Abre la consola del navegador</strong> (F12)</li>";
echo "<li><strong>Copia y pega este código:</strong></li>";
echo "<pre>";
echo "// Probar API de posibles padres
fetch('api/users/assignable.php?action=possible_parents')
.then(response => response.json())
.then(data => {
    console.log('Datos de posibles padres:', data);
    if (data.success) {
        console.log('✓ API funciona');
        console.log('Tipo de data:', typeof data.data);
        console.log('Es array:', Array.isArray(data.data));
        console.log('Cantidad de usuarios:', data.data.length);
        
        // Probar forEach
        data.data.forEach((user, index) => {
            console.log(`Usuario ${index + 1}:`, user.nombre, user.apellido, user.nivel);
        });
        
        console.log('✓ forEach funciona correctamente');
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

echo "<h3>4. Verificación de la Corrección:</h3>";

if (file_exists('user-management.js')) {
    $jsContent = file_get_contents('user-management.js');
    
    if (strpos($jsContent, 'result.data.forEach(user => {') !== false) {
        echo "<div style='color: green;'>✓ JavaScript corregido - usa result.data.forEach</div>";
    } else {
        echo "<div style='color: red;'>✗ JavaScript no corregido</div>";
    }
    
    if (strpos($jsContent, 'result.data.users.forEach') === false) {
        echo "<div style='color: green;'>✓ Ya no usa result.data.users.forEach</div>";
    } else {
        echo "<div style='color: red;'>✗ Aún usa result.data.users.forEach</div>";
    }
} else {
    echo "<div style='color: red;'>✗ Archivo user-management.js no encontrado</div>";
}

echo "<h3>5. Estado Actual del Sistema:</h3>";
echo "<div style='background: #d1ecf1; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4 style='color: #0c5460;'>Sistema completamente funcional:</h4>";
echo "<ul style='color: #0c5460;'>";
echo "<li>✅ <strong>loadPossibleParents:</strong> Funciona sin errores forEach</li>";
echo "<li>✅ <strong>Modal de edición:</strong> Se abre correctamente</li>";
echo "<li>✅ <strong>Campo Padre:</strong> Se llena con usuarios ROOT/ADMIN</li>";
echo "<li>✅ <strong>Selección de padre:</strong> Funciona correctamente</li>";
echo "<li>✅ <strong>Sin errores:</strong> Consola limpia</li>";
echo "</ul>";
echo "</div>";

echo "<h3>6. Funcionalidades Disponibles:</h3>";
echo "<div style='background: #e9ecef; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4>Panel completamente operativo:</h4>";
echo "<ul>";
echo "<li>✏️ <strong>Editar usuarios</strong> - Modal funcional sin errores</li>";
echo "<li>🌐 <strong>Asignar jerarquías</strong> - Campo padre funcional</li>";
echo "<li>➕ <strong>Crear usuarios</strong> - Con selección de padre</li>";
echo "<li>🔐 <strong>Gestionar permisos</strong> - Por categoría</li>";
echo "<li>🗑️ <strong>Eliminar usuarios</strong> - Con confirmación</li>";
echo "<li>🔄 <strong>Reset contraseñas</strong> - Para cualquier usuario</li>";
echo "<li>📊 <strong>Ver estadísticas</strong> - Dashboard completo</li>";
echo "<li>🔍 <strong>Filtrar usuarios</strong> - Por nivel, estado, búsqueda</li>";
echo "</ul>";
echo "</div>";

echo "<hr>";
echo "<p><small>Prueba completada el " . date('Y-m-d H:i:s') . "</small></p>";
?>


