<?php
echo "=== PROBANDO API CORREGIDA ===\n\n";

// Simular la llamada a la API
$url = 'http://localhost/portal_estudios/api/users/manage-real-complete.php';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

echo "Respuesta de la API:\n";
echo $response . "\n\n";

$data = json_decode($response, true);

if ($data && $data['success']) {
    echo "✓ API funcionando correctamente\n";
    echo "Usuarios encontrados: " . count($data['data']['users']) . "\n\n";
    
    echo "Verificando campo dependientes_count:\n";
    foreach ($data['data']['users'] as $user) {
        echo "  - {$user['nombre']} {$user['apellido']}: dependientes_count = {$user['dependientes_count']} (tipo: " . gettype($user['dependientes_count']) . ")\n";
    }
} else {
    echo "✗ Error en la API: " . ($data['error'] ?? 'Error desconocido') . "\n";
}
?>


