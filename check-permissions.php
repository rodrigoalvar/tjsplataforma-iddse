<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare('SELECT id, nombre, apellido, email, permisos FROM usuarios WHERE id = 2');
    $stmt->execute();
    $user = $stmt->fetch();
    
    if ($user) {
        echo "Usuario ID 2: " . $user['nombre'] . " " . $user['apellido'] . " (" . $user['email'] . ")\n";
        echo "Permisos en BD: " . ($user['permisos'] ?? 'NULL') . "\n";
        
        if ($user['permisos']) {
            $permisos = json_decode($user['permisos'], true);
            echo "Permisos decodificados: " . json_encode($permisos) . "\n";
            echo "Cantidad de permisos: " . count($permisos) . "\n";
        }
    } else {
        echo "Usuario ID 2 no encontrado\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>


