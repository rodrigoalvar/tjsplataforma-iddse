<?php
/**
 * Diagnóstico Específico del Panel de Edición
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

echo "<h1>Diagnóstico del Panel de Edición</h1>";
echo "<p>Sistema TJSMEDICAL - Portal de Estudios Médicos</p>";

echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4 style='color: #856404;'>🔍 Problema Reportado:</h4>";
echo "<p style='color: #856404;'>Usuario ROOT no puede editar otros usuarios desde el panel de gestión.</p>";
echo "</div>";

echo "<h3>1. Verificación de Archivos del Panel:</h3>";

$files = [
    'user-management.html' => 'Panel principal de gestión',
    'user-management.js' => 'JavaScript del panel',
    'api/users/manage-test.php' => 'API de gestión (sin autenticación)',
    'styles.css' => 'Estilos del panel'
];

foreach ($files as $file => $description) {
    if (file_exists($file)) {
        echo "<div style='color: green;'>✓ {$description}: {$file}</div>";
    } else {
        echo "<div style='color: red;'>✗ {$description}: {$file} - NO ENCONTRADO</div>";
    }
}

echo "<h3>2. Verificación de Funciones JavaScript:</h3>";

if (file_exists('user-management.js')) {
    $jsContent = file_get_contents('user-management.js');
    
    $functions = [
        'editUser' => 'Función para editar usuario',
        'showEditModal' => 'Función para mostrar modal de edición',
        'saveUser' => 'Función para guardar cambios',
        'renderUserRow' => 'Función para renderizar fila de usuario',
        'renderUserPermissions' => 'Función para renderizar permisos'
    ];
    
    foreach ($functions as $function => $description) {
        if (strpos($jsContent, "function {$function}") !== false || strpos($jsContent, "{$function}(") !== false) {
            echo "<div style='color: green;'>✓ {$description}: {$function}</div>";
        } else {
            echo "<div style='color: red;'>✗ {$description}: {$function} - NO ENCONTRADA</div>";
        }
    }
} else {
    echo "<div style='color: red;'>✗ No se pudo verificar JavaScript - archivo no encontrado</div>";
}

echo "<h3>3. Prueba de API de Edición:</h3>";

try {
    // Obtener un usuario para editar
    require_once 'config/database.php';
    $pdo = getDBConnection();
    
    $query = "SELECT id, nombre, apellido, email, nivel FROM usuarios WHERE activo = 1 ORDER BY id LIMIT 1";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $user = $stmt->fetch();
    
    if ($user) {
        echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
        echo "<h5>Usuario para probar edición:</h5>";
        echo "<ul>";
        echo "<li><strong>ID:</strong> {$user['id']}</li>";
        echo "<li><strong>Nombre:</strong> {$user['nombre']} {$user['apellido']}</li>";
        echo "<li><strong>Email:</strong> {$user['email']}</li>";
        echo "<li><strong>Nivel:</strong> {$user['nivel']}</li>";
        echo "</ul>";
        echo "</div>";
        
        // Probar edición
        $testData = [
            'id' => $user['id'],
            'nombre' => $user['nombre'] . ' (Editado)',
            'apellido' => $user['apellido'] . ' (Test)',
            'email' => 'editado_' . $user['email']
        ];
        
        $url = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/api/users/manage-test.php';
        
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
            $data = json_decode($response, true);
            if ($data && isset($data['success']) && $data['success']) {
                echo "<div style='color: green;'>✓ API de edición funciona correctamente</div>";
                echo "<div style='background: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
                echo "<h5>Edición exitosa:</h5>";
                echo "<ul>";
                echo "<li><strong>Nombre:</strong> {$testData['nombre']}</li>";
                echo "<li><strong>Apellido:</strong> {$testData['apellido']}</li>";
                echo "<li><strong>Email:</strong> {$testData['email']}</li>";
                echo "</ul>";
                echo "</div>";
            } else {
                echo "<div style='color: red;'>✗ API de edición devolvió error</div>";
                echo "<div style='background: #f8d7da; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
                echo "<pre>" . htmlspecialchars($response) . "</pre>";
                echo "</div>";
            }
        } else {
            echo "<div style='color: red;'>✗ No se pudo acceder a la API de edición</div>";
        }
    } else {
        echo "<div style='color: red;'>✗ No se encontraron usuarios para editar</div>";
    }
    
} catch (Exception $e) {
    echo "<div style='color: red;'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "<h3>4. Verificación de Modal de Edición:</h3>";

if (file_exists('user-management.html')) {
    $htmlContent = file_get_contents('user-management.html');
    
    $elements = [
        'editUserModal' => 'Modal de edición',
        'editUserForm' => 'Formulario de edición',
        'btn-edit' => 'Botón de editar',
        'saveUser' => 'Función de guardar'
    ];
    
    foreach ($elements as $element => $description) {
        if (strpos($htmlContent, $element) !== false) {
            echo "<div style='color: green;'>✓ {$description}: {$element}</div>";
        } else {
            echo "<div style='color: red;'>✗ {$description}: {$element} - NO ENCONTRADO</div>";
        }
    }
} else {
    echo "<div style='color: red;'>✗ No se pudo verificar HTML - archivo no encontrado</div>";
}

echo "<h3>5. Prueba Manual del Panel:</h3>";
echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4 style='color: #155724;'>Para probar manualmente:</h4>";
echo "<ol style='color: #155724;'>";
echo "<li><strong>Accede al panel:</strong> <a href='user-management.html' target='_blank'>user-management.html</a></li>";
echo "<li><strong>Abre la consola del navegador</strong> (F12)</li>";
echo "<li><strong>Haz clic en el botón 'Editar'</strong> de cualquier usuario</li>";
echo "<li><strong>Verifica si aparece el modal</strong> de edición</li>";
echo "<li><strong>Revisa la consola</strong> para errores de JavaScript</li>";
echo "</ol>";
echo "</div>";

echo "<h3>6. Prueba JavaScript Directa:</h3>";
echo "<div style='background: #e9ecef; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4>Para probar desde JavaScript:</h4>";
echo "<ol>";
echo "<li><strong>Abre la consola del navegador</strong> (F12)</li>";
echo "<li><strong>Copia y pega este código:</strong></li>";
echo "<pre>";
echo "// Verificar si el objeto UserManagement existe
if (typeof userManagement !== 'undefined') {
    console.log('✓ UserManagement existe');
    console.log('Usuarios cargados:', userManagement.users.length);
    
    // Probar edición de primer usuario
    if (userManagement.users.length > 0) {
        const firstUser = userManagement.users[0];
        console.log('Probando edición de:', firstUser.nombre);
        
        // Simular clic en editar
        userManagement.editUser(firstUser.id);
    }
} else {
    console.log('✗ UserManagement no está definido');
}

// Verificar funciones específicas
if (typeof userManagement !== 'undefined' && typeof userManagement.editUser === 'function') {
    console.log('✓ Función editUser existe');
} else {
    console.log('✗ Función editUser no existe');
}";
echo "</pre>";
echo "</ol>";
echo "</div>";

echo "<h3>7. Posibles Causas del Problema:</h3>";
echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4 style='color: #721c24;'>Causas más comunes:</h4>";
echo "<ul style='color: #721c24;'>";
echo "<li>❌ <strong>JavaScript no cargado:</strong> user-management.js no se está ejecutando</li>";
echo "<li>❌ <strong>Modal no existe:</strong> El modal de edición no está en el HTML</li>";
echo "<li>❌ <strong>Eventos no vinculados:</strong> Los botones de editar no tienen eventos</li>";
echo "<li>❌ <strong>API no responde:</strong> La API de edición no funciona</li>";
echo "<li>❌ <strong>Errores de consola:</strong> JavaScript tiene errores que impiden la ejecución</li>";
echo "</ul>";
echo "</div>";

echo "<h3>8. Soluciones Recomendadas:</h3>";
echo "<div style='background: #d1ecf1; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4 style='color: #0c5460;'>Pasos para solucionar:</h4>";
echo "<ol style='color: #0c5460;'>";
echo "<li><strong>Verificar consola:</strong> Revisar errores de JavaScript</li>";
echo "<li><strong>Verificar modal:</strong> Asegurar que el modal de edición existe</li>";
echo "<li><strong>Verificar eventos:</strong> Confirmar que los botones tienen eventos</li>";
echo "<li><strong>Probar API:</strong> Verificar que la API de edición funciona</li>";
echo "<li><strong>Revisar HTML:</strong> Confirmar que todos los elementos existen</li>";
echo "</ol>";
echo "</div>";

echo "<hr>";
echo "<p><small>Diagnóstico completado el " . date('Y-m-d H:i:s') . "</small></p>";
?>


