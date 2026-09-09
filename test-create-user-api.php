<?php
echo "=== PROBANDO API DE CREACIÓN DE USUARIOS ===\n\n";

// Datos de prueba para crear un usuario
$testUser = [
    'nombre' => 'Usuario',
    'apellido' => 'Prueba API',
    'email' => 'prueba_api_' . time() . '@test.com',
    'telefono' => '1234567890',
    'matricula_profesional' => 'MP' . time(),
    'especialidad' => 'Medicina General',
    'nivel' => 'user',
    'padre_id' => null,
    'permisos' => '["dashboard", "informes"]'
];

echo "Datos de prueba:\n";
foreach ($testUser as $key => $value) {
    echo "  {$key}: {$value}\n";
}
echo "\n";

// Simular la llamada a la API
$url = 'http://localhost/portal_estudios/api/users/manage-real-complete.php';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testUser));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen(json_encode($testUser))
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Respuesta HTTP: {$httpCode}\n";
echo "Respuesta de la API:\n";
echo $response . "\n\n";

$data = json_decode($response, true);

if ($data && $data['success']) {
    echo "✅ Usuario creado exitosamente!\n";
    echo "ID del usuario: " . ($data['data']['user_id'] ?? 'N/A') . "\n";
    echo "Contraseña temporal: " . ($data['data']['password'] ?? 'N/A') . "\n";
} else {
    echo "✗ Error creando usuario: " . ($data['error'] ?? 'Error desconocido') . "\n";
}
?>
