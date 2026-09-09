<?php
require_once 'config/database.php';

try {
    $database = new Database();
    $pdo = $database->getConnection();
    
    $stmt = $pdo->query('SELECT id, nombre, apellido, email, activo FROM usuarios WHERE nivel = "root" LIMIT 1');
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo "Usuario root encontrado:\n";
        echo "ID: " . $user['id'] . "\n";
        echo "Nombre: " . $user['nombre'] . " " . $user['apellido'] . "\n";
        echo "Email: " . $user['email'] . "\n";
        echo "Activo: " . ($user['activo'] ? 'Sí' : 'No') . "\n";
    } else {
        echo "No se encontró usuario root\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
