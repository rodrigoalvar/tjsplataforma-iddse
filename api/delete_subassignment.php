<?php
/**
 * API para eliminar subasignaciones (derivaciones) de estudios
 * Permite eliminar derivaciones específicas para un estudio
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';
require_once '../middleware/auth.php';

try {
    // Solo permitir método POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido. Use POST.');
    }
    
    // Obtener datos del request
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Datos de entrada inválidos');
    }
    
    // Validar campos requeridos
    if (!isset($input['study_id']) || !isset($input['main_user_id']) || !isset($input['subassigned_to_user_ids'])) {
        throw new Exception('Faltan campos requeridos: study_id, main_user_id, subassigned_to_user_ids');
    }
    
    $studyId = $input['study_id'];
    $mainUserId = $input['main_user_id'];
    $subassignedToUserIds = $input['subassigned_to_user_ids'];
    
    // Validar que subassigned_to_user_ids sea un array
    if (!is_array($subassignedToUserIds)) {
        throw new Exception('subassigned_to_user_ids debe ser un array de IDs');
    }
    
    if (empty($subassignedToUserIds)) {
        throw new Exception('subassigned_to_user_ids no puede estar vacío');
    }
    
    // Conectar a la base de datos
    $pdo = getDBConnection();
    
    // Preparar placeholders para la consulta IN
    $placeholders = implode(',', array_fill(0, count($subassignedToUserIds), '?'));
    
    // Eliminar subasignaciones (cambiar status a 'inactive')
    $deleteQuery = "
        UPDATE study_subassignments 
        SET status = 'inactive'
        WHERE study_id = ? 
        AND main_user_id = ? 
        AND subassigned_to_user_id IN ($placeholders)
        AND status = 'active'
    ";
    
    $stmt = $pdo->prepare($deleteQuery);
    
    // Construir array de parámetros: [study_id, main_user_id, ...user_ids]
    $params = array_merge([$studyId, $mainUserId], $subassignedToUserIds);
    
    $stmt->execute($params);
    
    $deletedCount = $stmt->rowCount();
    
    // Log de la operación
    error_log("Derivaciones eliminadas: $deletedCount para estudio $studyId");
    
    // Respuesta exitosa
    echo json_encode([
        'success' => true,
        'data' => [
            'deleted_count' => $deletedCount,
            'study_id' => $studyId,
            'main_user_id' => $mainUserId,
            'removed_user_ids' => $subassignedToUserIds
        ],
        'message' => "Se eliminaron $deletedCount derivación(es) exitosamente"
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'data' => null
    ], JSON_UNESCAPED_UNICODE);
    
    // Log del error
    error_log("Error en delete_subassignment.php: " . $e->getMessage());
}
?>

