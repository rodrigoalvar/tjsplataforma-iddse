<?php
/**
 * Script de Prueba de API de Permisos
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

echo "<h1>Prueba de API de Permisos</h1>";
echo "<p>Sistema TJSMEDICAL - Portal de Estudios Médicos</p>";

// Simular una llamada a la API
echo "<h3>Simulando llamada a la API:</h3>";

try {
    require_once 'config/database.php';
    $pdo = getDBConnection();
    
    // Verificar si hay sesión activa
    if (isset($_COOKIE['session_token'])) {
        $token = $_COOKIE['session_token'];
        
        // Verificar sesión en la base de datos
        $query = "SELECT u.id, u.nombre, u.apellido, u.email, u.nivel, u.permisos, u.activo 
                  FROM usuarios u 
                  INNER JOIN user_sessions s ON u.id = s.user_id 
                  WHERE s.session_token = ? AND s.expires_at > NOW() AND u.activo = 1";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        
        if ($user) {
            echo "<div style='color: green;'>✓ Sesión válida encontrada</div>";
            
            // Simular verificación de permiso 'usuarios'
            $permission = 'usuarios';
            $hasPermission = false;
            
            if ($user['nivel'] === 'root') {
                $hasPermission = true;
                echo "<div style='color: green;'>✓ Usuario ROOT - tiene permiso 'usuarios'</div>";
            } elseif ($user['nivel'] === 'admin') {
                $permissions = json_decode($user['permisos'], true) ?: [];
                $hasPermission = in_array($permission, $permissions) || in_array('all', $permissions);
                echo "<div style='color: " . ($hasPermission ? 'green' : 'red') . ";'>" . 
                     ($hasPermission ? '✓' : '✗') . " Usuario ADMIN - permiso 'usuarios': " . 
                     ($hasPermission ? 'SÍ' : 'NO') . "</div>";
            } else {
                $permissions = json_decode($user['permisos'], true) ?: [];
                $hasPermission = in_array($permission, $permissions);
                echo "<div style='color: " . ($hasPermission ? 'green' : 'red') . ";'>" . 
                     ($hasPermission ? '✓' : '✗') . " Usuario USER - permiso 'usuarios': " . 
                     ($hasPermission ? 'SÍ' : 'NO') . "</div>";
            }
            
            // Simular respuesta JSON
            $response = [
                'success' => true,
                'hasPermission' => $hasPermission,
                'permission' => $permission,
                'user' => [
                    'id' => $user['id'],
                    'nombre' => $user['nombre'],
                    'apellido' => $user['apellido'],
                    'email' => $user['email'],
                    'nivel' => $user['nivel'],
                    'permisos' => json_decode($user['permisos'], true) ?: []
                ]
            ];
            
            echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "<h5>Respuesta JSON simulada:</h5>";
            echo "<pre>" . json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
            echo "</div>";
            
        } else {
            echo "<div style='color: red;'>✗ Sesión no válida o expirada</div>";
        }
        
    } else {
        echo "<div style='color: red;'>✗ No se encontró cookie session_token</div>";
        echo "<div style='background: #fff3cd; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
        echo "<p><strong>Para probar:</strong></p>";
        echo "<ol>";
        echo "<li>Inicia sesión como ROOT: <a href='login.html'>login.html</a></li>";
        echo "<li>Email: root@portal.com</li>";
        echo "<li>Contraseña: admin123</li>";
        echo "<li>Luego vuelve a esta página</li>";
        echo "</ol>";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div style='color: red;'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "<h3>Prueba JavaScript:</h3>";
echo "<div style='background: #e9ecef; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4>Para probar desde JavaScript:</h4>";
echo "<ol>";
echo "<li><strong>Abre la consola del navegador</strong> (F12)</li>";
echo "<li><strong>Copia y pega este código:</strong></li>";
echo "<pre>";
echo "fetch('api/users/check-permission-simple.php', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
    },
    body: JSON.stringify({ permission: 'usuarios' })
})
.then(response => response.json())
.then(data => {
    console.log('Respuesta:', data);
    if (data.success && data.hasPermission) {
        console.log('✓ Tienes permisos de gestión de usuarios');
    } else {
        console.log('✗ No tienes permisos de gestión de usuarios');
    }
})
.catch(error => {
    console.error('Error:', error);
});";
echo "</pre>";
echo "</ol>";
echo "</div>";

echo "<hr>";
echo "<p><small>Prueba completada el " . date('Y-m-d H:i:s') . "</small></p>";
?>


