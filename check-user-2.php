<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare('SELECT id, nombre, apellido, email FROM usuarios WHERE id = 2');
    $stmt->execute();
    $user = $stmt->fetch();
    
    if ($user) {
        echo "Usuario ID 2: " . $user['nombre'] . " " . $user['apellido'] . " (" . $user['email'] . ")\n";
    } else {
        echo "Usuario ID 2 no encontrado\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>


