<?php
/**
 * API para verificar si el sistema necesita setup inicial
 * Retorna true si no hay usuarios en la base de datos
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    if (!$conn) {
        throw new Exception('No se pudo conectar a la base de datos');
    }
    
    // Verificar si existe la tabla usuarios
    $stmt = $conn->query("SHOW TABLES LIKE 'usuarios'");
    $tableExists = $stmt->rowCount() > 0;
    
    $needsSetup = false;
    $userCount = 0;
    
    if ($tableExists) {
        // Contar usuarios activos
        $stmt = $conn->query("SELECT COUNT(*) as count FROM usuarios WHERE activo = 1");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $userCount = $result ? (int)$result['count'] : 0;
        $needsSetup = $userCount === 0;
    } else {
        // Si no existe la tabla, necesita setup
        $needsSetup = true;
    }
    
    echo json_encode([
        'success' => true,
        'needs_setup' => $needsSetup,
        'user_count' => $userCount,
        'table_exists' => $tableExists
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'needs_setup' => true, // Si hay error, asumir que necesita setup
        'error' => $e->getMessage()
    ]);
}
?>
