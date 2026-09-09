<?php
// Test para establecer cookie manualmente
echo "=== TEST DE COOKIE MANUAL ===\n\n";

echo "PASO 1: Obtener sesion activa existente\n\n";

try {
    require_once 'config/database.php';
    $db = getDBConnection();
    
    // Obtener una sesion activa
    $query = "SELECT s.*, u.email, u.nombre 
              FROM sesiones s
              JOIN usuarios u ON s.usuario_id = u.id
              WHERE s.activa = 1 
              AND s.fecha_expiracion > NOW()
              ORDER BY s.fecha_creacion DESC
              LIMIT 1";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($session) {
        echo "Sesion activa encontrada:\n";
        echo "   Usuario: " . $session['nombre'] . " (" . $session['email'] . ")\n";
        echo "   Token: " . $session['token_sesion'] . "\n";
        echo "   Expira: " . $session['fecha_expiracion'] . "\n\n";
        
        echo "PASO 2: Crear script para establecer cookie\n\n";
        
        $cookieScript = '<!DOCTYPE html>
<html>
<head>
    <title>Establecer Cookie de Sesion</title>
    <meta charset="utf-8">
</head>
<body>
    <h1>Establecer Cookie de Sesion</h1>
    <p>Token: ' . $session['token_sesion'] . '</p>
    <p>Usuario: ' . $session['nombre'] . ' (' . $session['email'] . ')</p>
    
    <script>
        // Establecer cookie
        document.cookie = "session_token=' . $session['token_sesion'] . '; path=/; max-age=86400";
        
        // Verificar cookie
        setTimeout(function() {
            const cookies = document.cookie.split(";");
            let sessionToken = null;
            
            for (let cookie of cookies) {
                const [name, value] = cookie.trim().split("=");
                if (name === "session_token") {
                    sessionToken = value;
                    break;
                }
            }
            
            if (sessionToken) {
                document.body.innerHTML += "<p style=\"color: green;\">Cookie establecida correctamente: " + sessionToken + "</p>";
                document.body.innerHTML += "<p><a href=\"../components/informes-manager.html\">Probar Informes Manager</a></p>";
            } else {
                document.body.innerHTML += "<p style=\"color: red;\">Cookie NO establecida</p>";
            }
        }, 1000);
    </script>
</body>
</html>';

        file_put_contents('set-session-cookie-test.html', $cookieScript);
        
        echo "Script creado: set-session-cookie-test.html\n";
        echo "URL para probar: http://localhost/portal_estudios/set-session-cookie-test.html\n\n";
        
        echo "PASO 3: Crear test de validacion\n\n";
        
        $validationScript = '<?php
// Test de validacion de sesion
header("Content-Type: application/json");

try {
    require_once "classes/User.php";
    
    $token = $_COOKIE["session_token"] ?? null;
    
    if (!$token) {
        echo json_encode(["success" => false, "message" => "No hay cookie session_token"]);
        exit;
    }
    
    $user = new User();
    $result = $user->validateSession($token);
    
    if ($result) {
        echo json_encode([
            "success" => true,
            "user" => $result,
            "token" => $token
        ]);
    } else {
        echo json_encode(["success" => false, "message" => "Token invalido"]);
    }
    
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>';

        file_put_contents('test-session-validation.php', $validationScript);
        
        echo "Script de validacion creado: test-session-validation.php\n";
        echo "URL para probar: http://localhost/portal_estudios/test-session-validation.php\n\n";
        
        echo "PLAN DE TESTING:\n\n";
        
        echo "1. Abrir: http://localhost/portal_estudios/set-session-cookie-test.html\n";
        echo "2. Verificar que se establece el cookie\n";
        echo "3. Abrir: http://localhost/portal_estudios/test-session-validation.php\n";
        echo "4. Verificar que la validacion funciona\n";
        echo "5. Abrir: http://localhost/portal_estudios/components/informes-manager.html\n";
        echo "6. Verificar que informes-manager funciona\n\n";
        
        echo "INSTRUCCIONES:\n\n";
        
        echo "1. Abre el primer URL en el navegador\n";
        echo "2. Verifica que aparece el mensaje verde de cookie establecida\n";
        echo "3. Haz clic en Probar Informes Manager\n";
        echo "4. Informes-manager deberia cargar correctamente\n\n";
        
    } else {
        echo "No hay sesiones activas\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "CONCLUSION:\n\n";

echo "Si este test funciona, confirma que:\n";
echo "1. El problema NO esta en el codigo\n";
echo "2. El problema esta en el establecimiento del cookie durante el login\n";
echo "3. La solucion es corregir el proceso de login del navegador\n\n";
?>
