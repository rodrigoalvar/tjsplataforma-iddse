<?php
/**
 * API Ultra Simplificada con Datos Reales
 */

// Desactivar display de errores
ini_set('display_errors', 0);
error_reporting(E_ERROR | E_PARSE);

// Configurar headers para JSON
header('Content-Type: application/json; charset=utf-8');

try {
    // Conectar a la base de datos
    require_once '../../config/database.php';
    $pdo = getDBConnection();
    
    // Obtener usuarios reales
    $query = "SELECT id, nombre, apellido, email, nivel, activo FROM usuarios WHERE activo = 1 ORDER BY id";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $users = $stmt->fetchAll();
    
    // Respuesta exitosa
    echo json_encode([
        'success' => true,
        'data' => [
            'users' => $users,
            'hierarchy' => []
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // Respuesta de error
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
