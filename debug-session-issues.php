<?php
/**
 * Diagnóstico de Problemas de Sesión
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

echo "<h1>Diagnóstico de Problemas de Sesión</h1>";
echo "<p>Sistema TJSMEDICAL - Portal de Estudios Médicos</p>";

echo "<h3>1. Estado de la Sesión Actual:</h3>";

if (isset($_COOKIE['session_token'])) {
    echo "<div style='color: green;'>✓ Cookie session_token encontrada</div>";
    echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
    echo "<p><strong>Token:</strong> " . substr($_COOKIE['session_token'], 0, 20) . "...</p>";
    echo "</div>";
    
    try {
        require_once 'config/database.php';
        $pdo = getDBConnection();
        
        // Verificar si la tabla user_sessions existe
        $tablesQuery = "SHOW TABLES LIKE 'user_sessions'";
        $tablesStmt = $pdo->prepare($tablesQuery);
        $tablesStmt->execute();
        $sessionsTableExists = $tablesStmt->fetch();
        
        if ($sessionsTableExists) {
            echo "<div style='color: green;'>✓ Tabla user_sessions existe</div>";
            
            // Verificar sesión en user_sessions
            $query = "SELECT u.id, u.nombre, u.apellido, u.email, u.nivel, u.permisos, u.activo, s.expires_at
                      FROM usuarios u 
                      INNER JOIN user_sessions s ON u.id = s.user_id 
                      WHERE s.session_token = ? AND s.expires_at > NOW() AND u.activo = 1";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute([$_COOKIE['session_token']]);
            $user = $stmt->fetch();
            
            if ($user) {
                echo "<div style='color: green;'>✓ Sesión válida en user_sessions</div>";
                echo "<div style='background: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
                echo "<p><strong>Usuario:</strong> {$user['nombre']} {$user['apellido']}</p>";
                echo "<p><strong>Email:</strong> {$user['email']}</p>";
                echo "<p><strong>Nivel:</strong> {$user['nivel']}</p>";
                echo "<p><strong>Expira:</strong> {$user['expires_at']}</p>";
                echo "</div>";
            } else {
                echo "<div style='color: red;'>✗ Sesión no válida en user_sessions</div>";
                
                // Verificar si el token existe pero está expirado
                $query = "SELECT u.id, u.nombre, u.apellido, u.email, s.expires_at
                          FROM usuarios u 
                          INNER JOIN user_sessions s ON u.id = s.user_id 
                          WHERE s.session_token = ?";
                
                $stmt = $pdo->prepare($query);
                $stmt->execute([$_COOKIE['session_token']]);
                $expiredSession = $stmt->fetch();
                
                if ($expiredSession) {
                    echo "<div style='color: orange;'>⚠ Sesión expirada</div>";
                    echo "<div style='background: #fff3cd; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
                    echo "<p><strong>Usuario:</strong> {$expiredSession['nombre']} {$expiredSession['apellido']}</p>";
                    echo "<p><strong>Expiró:</strong> {$expiredSession['expires_at']}</p>";
                    echo "</div>";
                } else {
                    echo "<div style='color: red;'>✗ Token no encontrado en user_sessions</div>";
                }
            }
            
        } else {
            echo "<div style='color: orange;'>⚠ Tabla user_sessions no existe</div>";
            echo "<div style='background: #fff3cd; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "<p><strong>Problema:</strong> La tabla user_sessions no existe</p>";
            echo "<p><strong>Solución:</strong> Ejecutar la instalación del sistema de gestión de usuarios</p>";
            echo "<p><strong>Script:</strong> <a href='install-user-management-compatible.php'>install-user-management-compatible.php</a></p>";
            echo "</div>";
        }
        
    } catch (Exception $e) {
        echo "<div style='color: red;'>✗ Error verificando sesión: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    
} else {
    echo "<div style='color: red;'>✗ No se encontró cookie session_token</div>";
    echo "<div style='background: #f8d7da; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
    echo "<p><strong>Problema:</strong> No hay sesión activa</p>";
    echo "<p><strong>Solución:</strong> Iniciar sesión primero</p>";
    echo "<p><strong>Enlace:</strong> <a href='login.html'>login.html</a></p>";
    echo "</div>";
}

echo "<h3>2. Prueba de API Robusta:</h3>";

if (isset($_COOKIE['session_token'])) {
    try {
        $url = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/api/users/manage-robust.php';
        
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
                echo "<div style='color: green;'>✓ API robusta funciona correctamente</div>";
                echo "<div style='background: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
                echo "<p><strong>Usuarios encontrados:</strong> " . count($data['data']['users']) . "</p>";
                echo "<p><strong>Jerarquías:</strong> " . count($data['data']['hierarchy']) . "</p>";
                echo "</div>";
            } else {
                echo "<div style='color: red;'>✗ API robusta devolvió error</div>";
                echo "<div style='background: #f8d7da; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
                echo "<pre>" . htmlspecialchars($response) . "</pre>";
                echo "</div>";
            }
        } else {
            echo "<div style='color: red;'>✗ No se pudo acceder a la API robusta</div>";
        }
    } catch (Exception $e) {
        echo "<div style='color: red;'>✗ Error probando API robusta: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

echo "<h3>3. Soluciones Recomendadas:</h3>";
echo "<div style='background: #e9ecef; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4>Si no tienes sesión activa:</h4>";
echo "<ol>";
echo "<li><strong>Inicia sesión:</strong> <a href='login.html' target='_blank'>login.html</a></li>";
echo "<li><strong>Usa credenciales ROOT:</strong></li>";
echo "<ul>";
echo "<li>Email: root@portal.com</li>";
echo "<li>Contraseña: admin123</li>";
echo "</ul>";
echo "</ol>";
echo "</div>";

echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4>Si la tabla user_sessions no existe:</h4>";
echo "<ol>";
echo "<li><strong>Ejecuta la instalación:</strong> <a href='install-user-management-compatible.php' target='_blank'>install-user-management-compatible.php</a></li>";
echo "<li><strong>Luego inicia sesión</strong> nuevamente</li>";
echo "</ol>";
echo "</div>";

echo "<div style='background: #d1ecf1; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4>Si la sesión está expirada:</h4>";
echo "<ol>";
echo "<li><strong>Cierra sesión</strong> y vuelve a iniciar</li>";
echo "<li><strong>O ejecuta:</strong> <a href='fix-root-permissions.php' target='_blank'>fix-root-permissions.php</a></li>";
echo "</ol>";
echo "</div>";

echo "<h3>4. Prueba del Panel:</h3>";
echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4 style='color: #155724;'>Una vez solucionado el problema de sesión:</h4>";
echo "<ol style='color: #155724;'>";
echo "<li><strong>Accede al dashboard:</strong> <a href='dashboard-unified.html' target='_blank'>dashboard-unified.html</a></li>";
echo "<li><strong>Haz clic en 'Gestión Usuarios'</strong> en el sidebar</li>";
echo "<li><strong>Verifica que no hay errores</strong> en la consola</li>";
echo "<li><strong>Prueba las funcionalidades</strong> del panel</li>";
echo "</ol>";
echo "</div>";

echo "<hr>";
echo "<p><small>Diagnóstico completado el " . date('Y-m-d H:i:s') . "</small></p>";
?>


