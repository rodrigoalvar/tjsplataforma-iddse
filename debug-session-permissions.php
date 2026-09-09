<?php
/**
 * Script de Diagnóstico de Sesión y Permisos
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

echo "<h1>Diagnóstico de Sesión y Permisos</h1>";
echo "<p>Sistema TJSMEDICAL - Portal de Estudios Médicos</p>";

try {
    require_once 'config/database.php';
    $pdo = getDBConnection();
    
    echo "<h3>Información de Sesión:</h3>";
    
    // Verificar cookies
    if (isset($_COOKIE['session_token'])) {
        echo "<div style='color: green;'>✓ Cookie session_token encontrada: " . substr($_COOKIE['session_token'], 0, 20) . "...</div>";
        
        // Verificar sesión en base de datos
        $query = "SELECT u.id, u.nombre, u.apellido, u.email, u.nivel, u.permisos, u.activo, s.expires_at
                  FROM usuarios u 
                  INNER JOIN user_sessions s ON u.id = s.user_id 
                  WHERE s.session_token = ? AND s.expires_at > NOW() AND u.activo = 1";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$_COOKIE['session_token']]);
        $user = $stmt->fetch();
        
        if ($user) {
            echo "<div style='color: green;'>✓ Sesión válida encontrada</div>";
            echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "<p><strong>Usuario:</strong> {$user['nombre']} {$user['apellido']}</p>";
            echo "<p><strong>Email:</strong> {$user['email']}</p>";
            echo "<p><strong>Nivel:</strong> {$user['nivel']}</p>";
            echo "<p><strong>Activo:</strong> " . ($user['activo'] ? 'Sí' : 'No') . "</p>";
            echo "<p><strong>Expira:</strong> {$user['expires_at']}</p>";
            echo "<p><strong>Permisos:</strong> {$user['permisos']}</p>";
            echo "</div>";
            
            // Verificar permiso específico
            $permissions = json_decode($user['permisos'], true) ?: [];
            $hasUserPermission = false;
            
            if ($user['nivel'] === 'root') {
                $hasUserPermission = true;
                echo "<div style='color: green;'>✓ Usuario ROOT - tiene todos los permisos</div>";
            } elseif ($user['nivel'] === 'admin') {
                $hasUserPermission = in_array('usuarios', $permissions) || in_array('all', $permissions);
                echo "<div style='color: " . ($hasUserPermission ? 'green' : 'red') . ";'>" . 
                     ($hasUserPermission ? '✓' : '✗') . " Usuario ADMIN - permiso 'usuarios': " . 
                     ($hasUserPermission ? 'SÍ' : 'NO') . "</div>";
            } else {
                $hasUserPermission = in_array('usuarios', $permissions);
                echo "<div style='color: " . ($hasUserPermission ? 'green' : 'red') . ";'>" . 
                     ($hasUserPermission ? '✓' : '✗') . " Usuario USER - permiso 'usuarios': " . 
                     ($hasUserPermission ? 'SÍ' : 'NO') . "</div>";
            }
            
        } else {
            echo "<div style='color: red;'>✗ Sesión no válida o expirada</div>";
        }
        
    } else {
        echo "<div style='color: red;'>✗ No se encontró cookie session_token</div>";
    }
    
    echo "<h3>Verificación de Base de Datos:</h3>";
    
    // Verificar usuario ROOT
    $query = "SELECT id, nombre, apellido, email, nivel, permisos FROM usuarios WHERE nivel = 'root' AND activo = 1 LIMIT 1";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $rootUser = $stmt->fetch();
    
    if ($rootUser) {
        echo "<div style='color: green;'>✓ Usuario ROOT existe</div>";
        echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
        echo "<p><strong>Email:</strong> {$rootUser['email']}</p>";
        echo "<p><strong>Permisos:</strong> {$rootUser['permisos']}</p>";
        echo "</div>";
    } else {
        echo "<div style='color: red;'>✗ Usuario ROOT no existe</div>";
    }
    
    // Verificar permisos del sistema
    $query = "SELECT COUNT(*) as total FROM system_permissions WHERE permission_key = 'usuarios'";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $permissionsCount = $stmt->fetch()['total'];
    
    if ($permissionsCount > 0) {
        echo "<div style='color: green;'>✓ Permiso 'usuarios' existe en el sistema</div>";
    } else {
        echo "<div style='color: red;'>✗ Permiso 'usuarios' no existe en el sistema</div>";
    }
    
    // Verificar sesiones activas
    $query = "SELECT COUNT(*) as total FROM user_sessions WHERE expires_at > NOW()";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $activeSessions = $stmt->fetch()['total'];
    
    echo "<div style='color: blue;'>ℹ Sesiones activas: {$activeSessions}</div>";
    
} catch (Exception $e) {
    echo "<div style='color: red;'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "<h3>Prueba de API:</h3>";
echo "<div style='background: #e9ecef; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4>Para probar la API de permisos:</h4>";
echo "<ol>";
echo "<li><strong>Inicia sesión como ROOT:</strong></li>";
echo "<ul>";
echo "<li>Email: root@portal.com</li>";
echo "<li>Contraseña: admin123</li>";
echo "</ul>";
echo "<li><strong>Ejecuta esta prueba:</strong> <a href='test-permission-api.php' target='_blank'>test-permission-api.php</a></li>";
echo "<li><strong>Verifica el dashboard:</strong> <a href='dashboard-unified.html' target='_blank'>dashboard-unified.html</a></li>";
echo "</ol>";
echo "</div>";

echo "<hr>";
echo "<p><small>Diagnóstico completado el " . date('Y-m-d H:i:s') . "</small></p>";
?>


