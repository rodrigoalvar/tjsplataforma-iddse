<?php
/**
 * API para obtener las instituciones permitidas de un usuario
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../../config/database.php';

try {
    $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;
    
    if (!$user_id) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'user_id es requerido'
        ]);
        exit;
    }
    
    $db = getDBConnection();
    
    $stmt = $db->prepare("SELECT instituciones_permitidas FROM usuarios WHERE id = ? AND activo = 1");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result && !empty($result['instituciones_permitidas'])) {
        $instituciones = json_decode($result['instituciones_permitidas'], true) ?: [];
        echo json_encode([
            'success' => true,
            'instituciones' => $instituciones
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'instituciones' => []
        ]);
    }
    
} catch (Exception $e) {
    error_log('[GET_INSTITUTIONS] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error del servidor'
    ]);
}
?>

