<?php
echo "Probando API de jerarquías...\n";

$url = 'http://localhost/portal_estudios/api/users/hierarchy.php';
$response = file_get_contents($url);

if ($response === false) {
    echo "Error: No se pudo conectar a la API\n";
} else {
    echo "Respuesta recibida:\n";
    echo $response . "\n";
    
    $data = json_decode($response, true);
    if ($data && isset($data['success'])) {
        echo "API funcionando correctamente\n";
        if ($data['success']) {
            echo "Usuarios encontrados: " . count($data['data']['users']) . "\n";
        }
    } else {
        echo "Error en la respuesta JSON\n";
    }
}
?>


