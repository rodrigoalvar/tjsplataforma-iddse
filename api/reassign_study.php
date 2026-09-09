<?php
/**
 * API para cambiar asignaciones de estudios
 * Maneja la reasignación de estudios de un usuario a otro
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, PUT');
header('Access-Control-Allow-Headers: Content-Type');

// Solo permitir métodos POST y PUT
if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT'])) {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Método no permitido. Use POST o PUT.'
    ]);
    exit;
}

// Incluir configuración de base de datos
require_once '../config/database.php';

try {
    // Obtener datos del POST/PUT
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Datos de entrada inválidos');
    }
    
    $studyId = $input['study_id'] ?? null;
    $fromUserId = $input['from_user_id'] ?? null;
    $toUserId = $input['to_user_id'] ?? null;
    $assignedBy = $input['assigned_by'] ?? null;
    
    // Validar datos requeridos
    if (!$studyId || !$toUserId || !$assignedBy) {
        throw new Exception('Faltan datos requeridos: study_id, to_user_id, assigned_by');
    }
    
    // Verificar permisos de reasignación
    require_once '../middleware/permissions.php';
    $permissionManager = new PermissionManager();
    
    // Verificar permisos para asignar al usuario destino
    if (!$permissionManager->canAssignToUser($assignedBy, $toUserId)) {
        throw new Exception("No tienes permisos para asignar estudios al usuario ID: {$toUserId}");
    }
    
    // Si se especifica un usuario origen, verificar permisos para desasignar
    if ($fromUserId && !$permissionManager->canAssignToUser($assignedBy, $fromUserId)) {
        throw new Exception("No tienes permisos para desasignar estudios del usuario ID: {$fromUserId}");
    }
    
    // Crear conexión a la base de datos
    $pdo = getDBConnection();
    
    // Iniciar transacción
    $pdo->beginTransaction();
    
    $affectedRows = 0;
    
    if ($fromUserId) {
        // Cambiar asignación específica
        $updateSQL = "UPDATE study_assignments 
                      SET user_id = ?, assigned_by = ?, assigned_date = NOW() 
                      WHERE study_id = ? AND user_id = ?";
        $updateStmt = $pdo->prepare($updateSQL);
        $result = $updateStmt->execute([$toUserId, $assignedBy, $studyId, $fromUserId]);
        
        if ($result) {
            $affectedRows = $updateStmt->rowCount();
            if ($affectedRows > 0) {
                $message = "Asignación cambiada exitosamente";
            } else {
                throw new Exception('No se encontró la asignación especificada para cambiar');
            }
        } else {
            throw new Exception('Error al cambiar la asignación');
        }
    } else {
        // Reasignar todos los usuarios del estudio a un nuevo usuario
        // Primero eliminar todas las asignaciones existentes
        $deleteSQL = "DELETE FROM study_assignments WHERE study_id = ?";
        $deleteStmt = $pdo->prepare($deleteSQL);
        $deleteStmt->execute([$studyId]);
        
        // Luego crear la nueva asignación
        $insertSQL = "INSERT INTO study_assignments (study_id, user_id, assigned_by) VALUES (?, ?, ?)";
        $insertStmt = $pdo->prepare($insertSQL);
        $result = $insertStmt->execute([$studyId, $toUserId, $assignedBy]);
        
        if ($result) {
            $affectedRows = 1;
            $message = "Estudio reasignado exitosamente";
        } else {
            throw new Exception('Error al reasignar el estudio');
        }
    }
    
    // Confirmar transacción
    $pdo->commit();

    try {
        require_once __DIR__ . '/informes/informe_medico_responsable_helper.php';
        ir_sync_medico_informes_estudio($pdo, [
            'estudio_id' => $studyId,
            'study_id' => $studyId,
        ], (int)$toUserId);
    } catch (Throwable $syncEx) {
        error_log('[REASSIGN_STUDY] sync medico: ' . $syncEx->getMessage());
    }
    
    // Respuesta exitosa
    $response = [
        'success' => true,
        'message' => $message,
        'data' => [
            'study_id' => $studyId,
            'from_user_id' => $fromUserId,
            'to_user_id' => $toUserId,
            'assigned_by' => $assignedBy,
            'affected_rows' => $affectedRows,
            'reassigned_date' => date('Y-m-d H:i:s')
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
