<?php
/**
 * Prueba Directa de Conexión a Base de Datos
 */

echo "=== PRUEBA DE CONEXIÓN A BASE DE DATOS ===\n";

try {
    require_once 'config/database.php';
    echo "✓ Archivo database.php cargado\n";
    
    $pdo = getDBConnection();
    echo "✓ Conexión a base de datos exitosa\n";
    
    // Probar consulta simple
    $query = "SELECT COUNT(*) as total FROM usuarios WHERE activo = 1";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch();
    
    echo "✓ Consulta ejecutada exitosamente\n";
    echo "Total usuarios activos: " . $result['total'] . "\n";
    
    // Obtener algunos usuarios
    $query = "SELECT id, nombre, apellido, email, nivel FROM usuarios WHERE activo = 1 ORDER BY id LIMIT 5";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $users = $stmt->fetchAll();
    
    echo "\nPrimeros 5 usuarios:\n";
    foreach ($users as $user) {
        echo "- ID: {$user['id']}, Nombre: {$user['nombre']} {$user['apellido']}, Email: {$user['email']}, Nivel: {$user['nivel']}\n";
    }
    
    // Probar JSON
    echo "\n=== PRUEBA DE JSON ===\n";
    $jsonData = [
        'success' => true,
        'data' => [
            'users' => $users,
            'total' => $result['total']
        ]
    ];
    
    $jsonString = json_encode($jsonData, JSON_UNESCAPED_UNICODE);
    echo "✓ JSON generado exitosamente\n";
    echo "Longitud del JSON: " . strlen($jsonString) . " caracteres\n";
    echo "Primeros 200 caracteres: " . substr($jsonString, 0, 200) . "...\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "Archivo: " . $e->getFile() . "\n";
    echo "Línea: " . $e->getLine() . "\n";
}
?>


