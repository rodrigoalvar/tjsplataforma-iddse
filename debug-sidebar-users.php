<?php
/**
 * Diagnóstico Específico del Sidebar - Gestión Usuarios
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

echo "<h1>Diagnóstico del Sidebar - Gestión Usuarios</h1>";
echo "<p>Sistema TJSMEDICAL - Portal de Estudios Médicos</p>";

try {
    require_once 'config/database.php';
    $pdo = getDBConnection();
    
    echo "<h3>1. Verificación de Sesión Actual:</h3>";
    
    if (isset($_COOKIE['session_token'])) {
        $token = $_COOKIE['session_token'];
        echo "<div style='color: green;'>✓ Cookie session_token encontrada</div>";
        
        // Verificar sesión en base de datos
        $query = "SELECT u.id, u.nombre, u.apellido, u.email, u.nivel, u.permisos, u.activo, s.expires_at
                  FROM usuarios u 
                  INNER JOIN user_sessions s ON u.id = s.user_id 
                  WHERE s.session_token = ? AND s.expires_at > NOW() AND u.activo = 1";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        
        if ($user) {
            echo "<div style='color: green;'>✓ Sesión válida</div>";
            echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "<p><strong>Usuario:</strong> {$user['nombre']} {$user['apellido']}</p>";
            echo "<p><strong>Email:</strong> {$user['email']}</p>";
            echo "<p><strong>Nivel:</strong> {$user['nivel']}</p>";
            echo "<p><strong>Permisos:</strong> {$user['permisos']}</p>";
            echo "</div>";
            
            // Verificar específicamente el permiso 'usuarios'
            $permissions = json_decode($user['permisos'], true) ?: [];
            $hasUserPermission = false;
            
            if ($user['nivel'] === 'root') {
                $hasUserPermission = true;
                echo "<div style='color: green;'>✓ Usuario ROOT - debería tener acceso a Gestión Usuarios</div>";
            } elseif ($user['nivel'] === 'admin') {
                $hasUserPermission = in_array('usuarios', $permissions) || in_array('all', $permissions);
                echo "<div style='color: " . ($hasUserPermission ? 'green' : 'red') . ";'>" . 
                     ($hasUserPermission ? '✓' : '✗') . " Usuario ADMIN - permiso 'usuarios': " . 
                     ($hasUserPermission ? 'SÍ' : 'NO') . "</div>";
            } else {
                echo "<div style='color: red;'>✗ Usuario USER - no debería tener acceso a Gestión Usuarios</div>";
            }
            
        } else {
            echo "<div style='color: red;'>✗ Sesión no válida o expirada</div>";
        }
        
    } else {
        echo "<div style='color: red;'>✗ No se encontró cookie session_token</div>";
        echo "<div style='background: #fff3cd; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
        echo "<p><strong>Para continuar:</strong></p>";
        echo "<ol>";
        echo "<li>Inicia sesión como ROOT: <a href='login.html'>login.html</a></li>";
        echo "<li>Email: root@portal.com</li>";
        echo "<li>Contraseña: admin123</li>";
        echo "<li>Luego vuelve a esta página</li>";
        echo "</ol>";
        echo "</div>";
    }
    
    echo "<h3>2. Verificación de Usuario ROOT:</h3>";
    
    $query = "SELECT id, nombre, apellido, email, nivel, permisos FROM usuarios WHERE nivel = 'root' AND activo = 1 LIMIT 1";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $rootUser = $stmt->fetch();
    
    if ($rootUser) {
        echo "<div style='color: green;'>✓ Usuario ROOT existe</div>";
        echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
        echo "<p><strong>Email:</strong> {$rootUser['email']}</p>";
        echo "<p><strong>Nivel:</strong> {$rootUser['nivel']}</p>";
        echo "<p><strong>Permisos:</strong> {$rootUser['permisos']}</p>";
        echo "</div>";
        
        // Verificar que tenga permisos correctos
        $rootPermissions = json_decode($rootUser['permisos'], true) ?: [];
        if (in_array('all', $rootPermissions) || in_array('usuarios', $rootPermissions)) {
            echo "<div style='color: green;'>✓ Usuario ROOT tiene permisos correctos</div>";
        } else {
            echo "<div style='color: red;'>✗ Usuario ROOT NO tiene permisos correctos</div>";
            echo "<div style='background: #f8d7da; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "<p><strong>Problema:</strong> El usuario ROOT no tiene permisos de 'usuarios' o 'all'</p>";
            echo "<p><strong>Solución:</strong> Actualizar permisos del usuario ROOT</p>";
            echo "</div>";
        }
        
    } else {
        echo "<div style='color: red;'>✗ Usuario ROOT no existe</div>";
    }
    
    echo "<h3>3. Verificación de API de Permisos:</h3>";
    
    if (isset($_COOKIE['session_token'])) {
        // Simular llamada a la API
        $token = $_COOKIE['session_token'];
        
        $query = "SELECT u.id, u.nombre, u.apellido, u.email, u.nivel, u.permisos, u.activo 
                  FROM usuarios u 
                  INNER JOIN user_sessions s ON u.id = s.user_id 
                  WHERE s.session_token = ? AND s.expires_at > NOW() AND u.activo = 1";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        
        if ($user) {
            $permission = 'usuarios';
            $hasPermission = false;
            
            if ($user['nivel'] === 'root') {
                $hasPermission = true;
            } elseif ($user['nivel'] === 'admin') {
                $permissions = json_decode($user['permisos'], true) ?: [];
                $hasPermission = in_array($permission, $permissions) || in_array('all', $permissions);
            }
            
            $apiResponse = [
                'success' => true,
                'hasPermission' => $hasPermission,
                'permission' => $permission,
                'user' => [
                    'id' => $user['id'],
                    'nivel' => $user['nivel'],
                    'permisos' => json_decode($user['permisos'], true) ?: []
                ]
            ];
            
            echo "<div style='color: " . ($hasPermission ? 'green' : 'red') . ";'>" . 
                 ($hasPermission ? '✓' : '✗') . " API devolvería: hasPermission = " . 
                 ($hasPermission ? 'true' : 'false') . "</div>";
            
            echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "<h5>Respuesta de la API:</h5>";
            echo "<pre>" . json_encode($apiResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
            echo "</div>";
            
        }
    }
    
    echo "<h3>4. Verificación de Archivos:</h3>";
    
    $files = [
        'dashboard-unified.html' => 'Dashboard principal',
        'api/users/check-permission-simple.php' => 'API de permisos',
        'user-management.html' => 'Panel de gestión'
    ];
    
    foreach ($files as $file => $description) {
        if (file_exists($file)) {
            echo "<div style='color: green;'>✓ {$description}: {$file}</div>";
        } else {
            echo "<div style='color: red;'>✗ {$description}: {$file} - NO ENCONTRADO</div>";
        }
    }
    
    echo "<h3>5. Instrucciones de Prueba:</h3>";
    echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h4 style='color: #155724;'>Para probar el sidebar:</h4>";
    echo "<ol style='color: #155724;'>";
    echo "<li><strong>Inicia sesión como ROOT:</strong></li>";
    echo "<ul>";
    echo "<li>Email: root@portal.com</li>";
    echo "<li>Contraseña: admin123</li>";
    echo "</ul>";
    echo "<li><strong>Accede al dashboard:</strong> <a href='dashboard-unified.html' target='_blank'>dashboard-unified.html</a></li>";
    echo "<li><strong>Busca 'Gestión Usuarios' en el sidebar</strong></li>";
    echo "<li><strong>Si no aparece, abre la consola del navegador (F12) y ejecuta:</strong></li>";
    echo "</ol>";
    echo "<pre style='background: #f8f9fa; padding: 10px; border-radius: 5px;'>";
    echo "fetch('api/users/check-permission-simple.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ permission: 'usuarios' })
})
.then(response => response.json())
.then(data => {
    console.log('Respuesta API:', data);
    if (data.success && data.hasPermission) {
        console.log('✓ Debería aparecer el enlace de Gestión Usuarios');
        // Mostrar el enlace manualmente
        const link = document.getElementById('userManagementNavItem');
        if (link) {
            link.style.display = 'block';
            console.log('✓ Enlace mostrado manualmente');
        } else {
            console.log('✗ No se encontró el elemento userManagementNavItem');
        }
    } else {
        console.log('✗ No debería aparecer el enlace');
    }
});";
    echo "</pre>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='color: red;'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "<hr>";
echo "<p><small>Diagnóstico completado el " . date('Y-m-d H:i:s') . "</small></p>";
?>


