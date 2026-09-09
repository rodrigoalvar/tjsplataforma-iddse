<?php
/**
 * API para desasignar estudios de usuarios
 * Maneja la eliminación de asignaciones específicas
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

// Solo permitir métodos POST y DELETE
if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'DELETE'])) {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Método no permitido. Use POST o DELETE.'
    ]);
    exit;
}

// Incluir configuración de base de datos
require_once '../config/database.php';

try {
    // Obtener datos del POST o DELETE
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Datos de entrada inválidos');
    }
    
    $studyId = $input['study_id'] ?? null;
    $userId = $input['user_id'] ?? null;
    $assignedBy = $input['assigned_by'] ?? null;
    
    // Validar datos requeridos
    if (!$studyId) {
        throw new Exception('study_id es requerido');
    }
    
    // Verificar permisos de desasignación
    require_once '../middleware/permissions.php';
    $permissionManager = new PermissionManager();
    
    // Si se especifica un usuario específico, verificar permisos
    if ($userId && $assignedBy) {
        if (!$permissionManager->canAssignToUser($assignedBy, $userId)) {
            throw new Exception("No tienes permisos para desasignar estudios del usuario ID: {$userId}");
        }
    }
    
    // Crear conexión a la base de datos
    $pdo = getDBConnection();
    
    // Iniciar transacción
    $pdo->beginTransaction();
    
    if ($userId) {
        // Desasignar usuario específico del estudio
        $deleteSQL = "DELETE FROM study_assignments WHERE study_id = ? AND user_id = ?";
        $deleteStmt = $pdo->prepare($deleteSQL);
        $result = $deleteStmt->execute([$studyId, $userId]);
        
        if ($result) {
            $affectedRows = $deleteStmt->rowCount();
            if ($affectedRows > 0) {
                $message = "Usuario desasignado del estudio exitosamente";
            } else {
                $message = "No se encontró la asignación especificada";
            }
        } else {
            throw new Exception('Error al desasignar usuario del estudio');
        }
    } else {
        // Desasignar todos los usuarios del estudio
        $deleteSQL = "DELETE FROM study_assignments WHERE study_id = ?";
        $deleteStmt = $pdo->prepare($deleteSQL);
        $result = $deleteStmt->execute([$studyId]);
        
        if ($result) {
            $affectedRows = $deleteStmt->rowCount();
            $message = "Todos los usuarios desasignados del estudio exitosamente ({$affectedRows} asignaciones eliminadas)";
        } else {
            throw new Exception('Error al desasignar todos los usuarios del estudio');
        }
    }
    
    // Confirmar transacción
    $pdo->commit();
    
    // Respuesta exitosa
    $response = [
        'success' => true,
        'message' => $message,
        'data' => [
            'study_id' => $studyId,
            'user_id' => $userId,
            'affected_rows' => $affectedRows ?? 0,
            'unassigned_date' => date('Y-m-d H:i:s')
        ]
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    // Rollback en caso de error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    $response = [
        'success' => false,
        'error' => 'Error de base de datos: ' . $e->getMessage()
    ];
    
    http_response_code(500);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // Rollback en caso de error
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    $response = [
        'success' => false,
        'error' => 'Error interno del servidor: ' . $e->getMessage()
    ];
    
    http_response_code(500);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
}
?>
