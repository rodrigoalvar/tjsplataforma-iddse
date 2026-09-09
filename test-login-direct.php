<?php
echo "=== PRUEBA DIRECTA DE LOGIN ===\n\n";

// Simular datos de login
$login_data = [
    'email' => 'prueba_curl@test.com',
    'password' => '123456'
];

echo "🔍 Probando login con:\n";
echo "Email: " . $login_data['email'] . "\n";
echo "Password: " . $login_data['password'] . "\n\n";

// Simular llamada a login.php
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/json';

// Simular input JSON
$json_input = json_encode($login_data);

echo "📤 JSON enviado: " . $json_input . "\n\n";

// Capturar output
ob_start();

// Simular file_get_contents('php://input')
$GLOBALS['php_input'] = $json_input;

// Redefinir file_get_contents temporalmente
function file_get_contents($filename) {
    if ($filename === 'php://input') {
        return $GLOBALS['php_input'];
    }
    return \file_get_contents($filename);
}

try {
    // Incluir login.php
    include 'api/auth/login.php';
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

$output = ob_get_clean();

echo "📥 Respuesta del login:\n";
echo $output . "\n\n";

echo "🍪 Cookies establecidas:\n";
foreach ($_COOKIE as $name => $value) {
    echo "  $name: $value\n";
}

echo "\n✅ Prueba completada\n";
?>
