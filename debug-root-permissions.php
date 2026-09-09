<?php
/**
 * Diagnóstico de Permisos ROOT
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

echo "<h1>Diagnóstico de Permisos ROOT</h1>";
echo "<p>Sistema TJSMEDICAL - Portal de Estudios Médicos</p>";

// Conectar a la base de datos
try {
    require_once 'config/database.php';
    $pdo = getDBConnection();
    
    echo "<h3>1. Estado de la Base de Datos:</h3>";
    
    // Verificar usuarios ROOT
    $query = "SELECT id, nombre, apellido, email, nivel, permisos, activo FROM usuarios WHERE nivel = 'root'";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $rootUsers = $stmt->fetchAll();
    
    if (empty($rootUsers)) {
        echo "<div style='color: red;'>✗ No se encontraron usuarios ROOT</div>";
    } else {
        echo "<div style='color: green;'>✓ Usuarios ROOT encontrados: " . count($rootUsers) . "</div>";
        
        foreach ($rootUsers as $user) {
            echo "<div style='background: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "<h5>Usuario ROOT:</h5>";
            echo "<ul>";
            echo "<li><strong>ID:</strong> {$user['id']}</li>";
            echo "<li><strong>Nombre:</strong> {$user['nombre']} {$user['apellido']}</li>";
            echo "<li><strong>Email:</strong> {$user['email']}</li>";
            echo "<li><strong>Nivel:</strong> {$user['nivel']}</li>";
            echo "<li><strong>Activo:</strong> " . ($user['activo'] ? 'Sí' : 'No') . "</li>";
            echo "<li><strong>Permisos:</strong> " . htmlspecialchars($user['permisos']) . "</li>";
            echo "</ul>";
            echo "</div>";
        }
    }
    
    // Verificar todos los usuarios
    $query = "SELECT id, nombre, apellido, email, nivel, activo FROM usuarios ORDER BY nivel DESC, nombre ASC";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $allUsers = $stmt->fetchAll();
    
    echo "<h3>2. Todos los Usuarios:</h3>";
    echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
    echo "<table border='1' style='width: 100%; border-collapse: collapse;'>";
    echo "<tr style='background: #e9ecef;'>";
    echo "<th style='padding: 8px;'>ID</th>";
    echo "<th style='padding: 8px;'>Nombre</th>";
    echo "<th style='padding: 8px;'>Email</th>";
    echo "<th style='padding: 8px;'>Nivel</th>";
    echo "<th style='padding: 8px;'>Activo</th>";
    echo "</tr>";
    
    foreach ($allUsers as $user) {
        $rowColor = $user['nivel'] === 'root' ? '#d4edda' : ($user['nivel'] === 'admin' ? '#fff3cd' : '#ffffff');
        echo "<tr style='background: {$rowColor};'>";
        echo "<td style='padding: 8px;'>{$user['id']}</td>";
        echo "<td style='padding: 8px;'>{$user['nombre']} {$user['apellido']}</td>";
        echo "<td style='padding: 8px;'>{$user['email']}</td>";
        echo "<td style='padding: 8px;'>{$user['nivel']}</td>";
        echo "<td style='padding: 8px;'>" . ($user['activo'] ? 'Sí' : 'No') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='color: red;'>✗ Error conectando a la base de datos: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "<h3>3. Prueba de API de Modificación:</h3>";

try {
    // Simular una petición PUT para modificar un usuario
    $testData = [
        'id' => 1, // Asumiendo que el usuario ROOT tiene ID 1
        'nombre' => 'Root',
        'apellido' => 'Administrator',
        'email' => 'root@portal.com'
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
            echo "<div style='color: green;'>✓ API de modificación funciona correctamente</div>";
        } else {
            echo "<div style='color: red;'>✗ API de modificación devolvió error</div>";
            echo "<div style='background: #f8d7da; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "<pre>" . htmlspecialchars($response) . "</pre>";
            echo "</div>";
        }
    } else {
        echo "<div style='color: red;'>✗ No se pudo acceder a la API de modificación</div>";
    }
} catch (Exception $e) {
    echo "<div style='color: red;'>✗ Error probando API: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "<h3>4. Soluciones Recomendadas:</h3>";
echo "<div style='background: #d1ecf1; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4 style='color: #0c5460;'>Si ROOT no puede modificar usuarios:</h4>";
echo "<ol style='color: #0c5460;'>";
echo "<li><strong>Verificar permisos ROOT:</strong> Debe tener permisos ['all']</li>";
echo "<li><strong>Verificar API:</strong> La API debe permitir modificaciones a ROOT</li>";
echo "<li><strong>Verificar JavaScript:</strong> El frontend debe enviar las peticiones correctamente</li>";
echo "<li><strong>Verificar sesión:</strong> Debe estar autenticado como ROOT</li>";
echo "</ol>";
echo "</div>";

echo "<h3>5. Prueba Manual:</h3>";
echo "<div style='background: #e9ecef; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4>Para probar desde JavaScript:</h4>";
echo "<ol>";
echo "<li><strong>Abre la consola del navegador</strong> (F12)</li>";
echo "<li><strong>Copia y pega este código:</strong></li>";
echo "<pre>";
echo "// Probar modificación de usuario
const testData = {
    id: 1,
    nombre: 'Root',
    apellido: 'Administrator',
    email: 'root@portal.com'
};

fetch('api/users/manage-test.php', {
    method: 'PUT',
    headers: {
        'Content-Type': 'application/json',
    },
    body: JSON.stringify(testData)
})
.then(response => response.json())
.then(data => {
    console.log('Resultado:', data);
    if (data.success) {
        console.log('✓ Modificación exitosa');
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

echo "<hr>";
echo "<p><small>Diagnóstico completado el " . date('Y-m-d H:i:s') . "</small></p>";
?>


