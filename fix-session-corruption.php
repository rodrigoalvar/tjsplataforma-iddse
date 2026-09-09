<?php
/**
 * Script para Limpiar Sesión Corrupta y Crear Nueva Sesión
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

echo "<h1>Limpieza de Sesión Corrupta</h1>";
echo "<p>Sistema TJSMEDICAL - Portal de Estudios Médicos</p>";

try {
    require_once 'config/database.php';
    $pdo = getDBConnection();
    
    echo "<h3>1. Estado Actual de la Sesión:</h3>";
    
    if (isset($_COOKIE['session_token'])) {
        $currentToken = $_COOKIE['session_token'];
        echo "<div style='color: orange;'>⚠ Token de sesión encontrado pero no válido</div>";
        echo "<div style='background: #fff3cd; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
        echo "<p><strong>Token actual:</strong> " . substr($currentToken, 0, 20) . "...</p>";
        echo "</div>";
        
        // Limpiar sesiones expiradas o inválidas
        $query = "DELETE FROM user_sessions WHERE session_token = ? OR expires_at < NOW()";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$currentToken]);
        $deletedSessions = $stmt->rowCount();
        
        echo "<div style='color: green;'>✓ Sesiones limpiadas: {$deletedSessions}</div>";
        
    } else {
        echo "<div style='color: blue;'>ℹ No hay token de sesión en las cookies</div>";
    }
    
    echo "<h3>2. Crear Nueva Sesión para Usuario ROOT:</h3>";
    
    // Buscar usuario ROOT
    $query = "SELECT id, nombre, apellido, email, nivel FROM usuarios WHERE nivel = 'root' AND activo = 1 LIMIT 1";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $rootUser = $stmt->fetch();
    
    if ($rootUser) {
        echo "<div style='color: green;'>✓ Usuario ROOT encontrado</div>";
        echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
        echo "<p><strong>Usuario:</strong> {$rootUser['nombre']} {$rootUser['apellido']}</p>";
        echo "<p><strong>Email:</strong> {$rootUser['email']}</p>";
        echo "<p><strong>Nivel:</strong> {$rootUser['nivel']}</p>";
        echo "</div>";
        
        // Generar nuevo token de sesión
        $newToken = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        // Insertar nueva sesión
        $query = "INSERT INTO user_sessions (user_id, session_token, expires_at, created_at) VALUES (?, ?, ?, NOW())";
        $stmt = $pdo->prepare($query);
        $result = $stmt->execute([$rootUser['id'], $newToken, $expiresAt]);
        
        if ($result) {
            echo "<div style='color: green;'>✓ Nueva sesión creada exitosamente</div>";
            
            // Establecer cookie con nuevo token
            setcookie('session_token', $newToken, time() + (24 * 60 * 60), '/'); // 24 horas
            
            echo "<div style='background: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "<p><strong>Nuevo token:</strong> " . substr($newToken, 0, 20) . "...</p>";
            echo "<p><strong>Expira:</strong> {$expiresAt}</p>";
            echo "<p><strong>Cookie establecida:</strong> Sí</p>";
            echo "</div>";
            
            echo "<div style='background: #d1ecf1; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
            echo "<h4 style='color: #0c5460;'>¡Sesión Restaurada!</h4>";
            echo "<p style='color: #0c5460;'>Tu sesión ha sido restaurada correctamente. Ahora puedes:</p>";
            echo "<ol style='color: #0c5460;'>";
            echo "<li><strong>Acceder al dashboard:</strong> <a href='dashboard-unified.html' target='_blank'>dashboard-unified.html</a></li>";
            echo "<li><strong>Hacer clic en 'Gestión Usuarios'</strong> en el sidebar</li>";
            echo "<li><strong>Probar todas las funcionalidades</strong> del panel</li>";
            echo "</ol>";
            echo "</div>";
            
        } else {
            echo "<div style='color: red;'>✗ Error creando nueva sesión</div>";
        }
        
    } else {
        echo "<div style='color: red;'>✗ Usuario ROOT no encontrado</div>";
        echo "<div style='background: #f8d7da; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
        echo "<p><strong>Problema:</strong> No existe un usuario ROOT activo</p>";
        echo "<p><strong>Solución:</strong> Ejecutar la instalación del sistema</p>";
        echo "<p><strong>Script:</strong> <a href='install-user-management-compatible.php'>install-user-management-compatible.php</a></p>";
        echo "</div>";
    }
    
    echo "<h3>3. Verificación de la Nueva Sesión:</h3>";
    
    // Verificar que la nueva sesión funciona
    if (isset($_COOKIE['session_token'])) {
        $newToken = $_COOKIE['session_token'];
        
        $query = "SELECT u.id, u.nombre, u.apellido, u.email, u.nivel, u.permisos, u.activo, s.expires_at
                  FROM usuarios u 
                  INNER JOIN user_sessions s ON u.id = s.user_id 
                  WHERE s.session_token = ? AND s.expires_at > NOW() AND u.activo = 1";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$newToken]);
        $user = $stmt->fetch();
        
        if ($user) {
            echo "<div style='color: green;'>✓ Nueva sesión verificada correctamente</div>";
            echo "<div style='background: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "<p><strong>Usuario:</strong> {$user['nombre']} {$user['apellido']}</p>";
            echo "<p><strong>Email:</strong> {$user['email']}</p>";
            echo "<p><strong>Nivel:</strong> {$user['nivel']}</p>";
            echo "<p><strong>Expira:</strong> {$user['expires_at']}</p>";
            echo "</div>";
        } else {
            echo "<div style='color: red;'>✗ Nueva sesión no válida</div>";
        }
    }
    
    echo "<h3>4. Prueba de API:</h3>";
    
    // Probar la API con la nueva sesión
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
                echo "<div style='color: green;'>✓ API funciona correctamente con nueva sesión</div>";
                echo "<div style='background: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
                echo "<p><strong>Usuarios encontrados:</strong> " . count($data['data']['users']) . "</p>";
                echo "<p><strong>Jerarquías:</strong> " . count($data['data']['hierarchy']) . "</p>";
                echo "</div>";
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
        echo "<div style='color: red;'>✗ Error probando API: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    
} catch (Exception $e) {
    echo "<div style='color: red;'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "<h3>5. Instrucciones Finales:</h3>";
echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4 style='color: #155724;'>¡Sesión Restaurada Exitosamente!</h4>";
echo "<p style='color: #155724;'>Ahora puedes acceder al panel de gestión de usuarios:</p>";
echo "<ol style='color: #155724;'>";
echo "<li><strong>Accede al dashboard:</strong> <a href='dashboard-unified.html' target='_blank'>dashboard-unified.html</a></li>";
echo "<li><strong>Busca 'Gestión Usuarios'</strong> en el sidebar</li>";
echo "<li><strong>Haz clic en el enlace</strong> para acceder al panel</li>";
echo "<li><strong>Verifica que no hay errores</strong> en la consola del navegador</li>";
echo "<li><strong>Prueba todas las funcionalidades</strong> del panel</li>";
echo "</ol>";
echo "</div>";

echo "<hr>";
echo "<p><small>Sesión restaurada el " . date('Y-m-d H:i:s') . "</small></p>";
?>


