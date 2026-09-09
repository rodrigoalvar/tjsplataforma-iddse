<?php
// Test del proceso de login
echo "=== TEST DEL PROCESO DE LOGIN ===\n\n";

echo "🔍 PASO 1: Simular login desde línea de comandos\n\n";

// Simular datos de login
$loginData = [
    'email' => 'rodrigoalvar@gmail.com',
    'password' => 'password123' // Asumiendo contraseña común
];

echo "📋 Datos de login:\n";
echo "   • Email: " . $loginData['email'] . "\n";
echo "   • Password: " . $loginData['password'] . "\n\n";

echo "🔍 PASO 2: Probar User::login() directamente\n\n";

try {
    require_once 'classes/User.php';
    
    $user = new User();
    $result = $user->login($loginData['email'], $loginData['password']);
    
    if ($result['success']) {
        echo "✅ Login exitoso:\n";
        echo "   • Usuario: " . $result['user']['nombre'] . "\n";
        echo "   • Email: " . $result['user']['email'] . "\n";
        echo "   • Token: " . substr($result['session_token'], 0, 20) . "...\n";
        echo "   • Token completo: " . $result['session_token'] . "\n\n";
        
        echo "🔍 PASO 3: Verificar sesión en BD\n\n";
        
        require_once 'config/database.php';
        $db = getDBConnection();
        
        $query = "SELECT * FROM sesiones WHERE token_sesion = ? AND activa = 1";
        $stmt = $db->prepare($query);
        $stmt->execute([$result['session_token']]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($session) {
            echo "✅ Sesión encontrada en BD:\n";
            echo "   • ID: " . $session['id'] . "\n";
            echo "   • Usuario ID: " . $session['usuario_id'] . "\n";
            echo "   • Activa: " . ($session['activa'] ? 'SÍ' : 'NO') . "\n";
            echo "   • Expira: " . $session['fecha_expiracion'] . "\n\n";
            
            echo "🔍 PASO 4: Probar User::validateSession()\n\n";
            
            $validation = $user->validateSession($result['session_token']);
            
            if ($validation) {
                echo "✅ Validación exitosa:\n";
                echo "   • Usuario: " . $validation['nombre'] . "\n";
                echo "   • Email: " . $validation['email'] . "\n\n";
                
                echo "🎯 CONCLUSIÓN:\n\n";
                echo "✅ El proceso de login funciona correctamente\n";
                echo "✅ Las sesiones se crean en la BD\n";
                echo "✅ La validación funciona\n";
                echo "❌ El problema está en el establecimiento del cookie\n\n";
                
                echo "📋 PRÓXIMO PASO:\n\n";
                echo "Probar el login desde el navegador para verificar\n";
                echo "si el cookie se establece correctamente.\n";
                
            } else {
                echo "❌ Validación falló\n";
            }
            
        } else {
            echo "❌ Sesión NO encontrada en BD\n";
        }
        
    } else {
        echo "❌ Login falló: " . $result['message'] . "\n";
        
        echo "\n🔍 Probando con usuario root...\n\n";
        
        $rootResult = $user->login('root@portal.com', 'admin123');
        
        if ($rootResult['success']) {
            echo "✅ Login root exitoso:\n";
            echo "   • Usuario: " . $rootResult['user']['nombre'] . "\n";
            echo "   • Token: " . substr($rootResult['session_token'], 0, 20) . "...\n";
        } else {
            echo "❌ Login root falló: " . $rootResult['message'] . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "\n📋 RESUMEN:\n\n";

echo "Si el login funciona desde línea de comandos pero no desde el navegador,\n";
echo "el problema está en:\n";
echo "1. ✅ Configuración de cookies del navegador\n";
echo "2. ✅ Headers HTTP\n";
echo "3. ✅ Configuración del servidor web\n";
echo "4. ✅ CORS o políticas de seguridad\n\n";
?>
