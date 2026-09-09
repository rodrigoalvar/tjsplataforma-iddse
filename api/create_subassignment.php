<?php
/**
 * API para crear subasignaciones (derivaciones) de estudios
 * Permite a un usuario principal derivar estudios a usuarios hijos
 * sin modificar la asignación original
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Método no permitido. Use POST.'
    ]);
    exit;
}

require_once '../config/database.php';

try {
    // Obtener datos del POST
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Datos de entrada inválidos');
    }
    
    $studyId = $input['study_id'] ?? null;
    $mainUserId = $input['main_user_id'] ?? null;
    $subassignedToUserIds = $input['subassigned_to_user_ids'] ?? [];
    $assignedByUserId = $input['assigned_by_user_id'] ?? null;
    
    // Validar datos requeridos
    if (!$studyId || empty($subassignedToUserIds) || !$mainUserId || !$assignedByUserId) {
        throw new Exception('Faltan datos requeridos: study_id, main_user_id, subassigned_to_user_ids, assigned_by_user_id');
    }
    
    // Verificar que assigned_by_user_id sea el mismo que main_user_id
    if ($assignedByUserId != $mainUserId) {
        throw new Exception('Solo el usuario principal puede derivar estudios a sus hijos');
    }
    
    $pdo = getDBConnection();
    
    // Crear tabla de subasignaciones si no existe
    $createTableSQL = "
        CREATE TABLE IF NOT EXISTS study_subassignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            study_id VARCHAR(255) NOT NULL,
            main_user_id INT NOT NULL,
            subassigned_to_user_id INT NOT NULL,
            assigned_by_user_id INT NOT NULL,
            subassigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            status ENUM('active', 'inactive') DEFAULT 'active',
            INDEX idx_study_main (study_id, main_user_id),
            INDEX idx_subassigned_to (subassigned_to_user_id),
            INDEX idx_main_user (main_user_id),
            INDEX idx_assigned_by (assigned_by_user_id),
            INDEX idx_status (status),
            FOREIGN KEY (main_user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            FOREIGN KEY (subassigned_to_user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            FOREIGN KEY (assigned_by_user_id) REFERENCES usuarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    $pdo->exec($createTableSQL);
    
    // Verificar que el estudio esté asignado al usuario principal
    $checkAssignment = "SELECT id FROM study_assignments 
                        WHERE study_id = ? AND user_id = ? AND status = 'active'";
    $checkStmt = $pdo->prepare($checkAssignment);
    $checkStmt->execute([$studyId, $mainUserId]);
    
    if ($checkStmt->rowCount() === 0) {
        throw new Exception('El estudio no está asignado al usuario principal especificado');
    }
    
    // Iniciar transacción
    $pdo->beginTransaction();
    
    $subassignedCount = 0;
    $errors = [];
    
    foreach ($subassignedToUserIds as $userId) {
        try {
            // Verificar que el usuario hijo sea hijo del usuario principal
            $checkChild = "SELECT id FROM usuarios WHERE id = ? AND padre_id = ? AND activo = 1";
            $childStmt = $pdo->prepare($checkChild);
            $childStmt->execute([$userId, $mainUserId]);
            
            if ($childStmt->rowCount() === 0) {
                $errors[] = "El usuario ID $userId no es un hijo válido del usuario principal";
                continue;
            }
            
            // Verificar si ya existe una subasignación activa
            $checkExisting = "SELECT id FROM study_subassignments 
                              WHERE study_id = ? AND main_user_id = ? AND subassigned_to_user_id = ? AND status = 'active'";
            $existingStmt = $pdo->prepare($checkExisting);
            $existingStmt->execute([$studyId, $mainUserId, $userId]);
            
            if ($existingStmt->rowCount() > 0) {
                $errors[] = "Este estudio ya está derivado al usuario ID $userId";
                continue;
            }
            
            // Insertar subasignación
            $insertSQL = "INSERT INTO study_subassignments (
                study_id, main_user_id, subassigned_to_user_id, assigned_by_user_id, status
            ) VALUES (?, ?, ?, ?, 'active')";
            
            $insertStmt = $pdo->prepare($insertSQL);
            $insertStmt->execute([
                $studyId, 
                $mainUserId, 
                $userId, 
                $assignedByUserId
            ]);
            
            $subassignedCount++;
            
        } catch (Exception $e) {
            $errors[] = "Error al derivar al usuario ID $userId: " . $e->getMessage();
        }
    }
    
    if ($subassignedCount === 0 && !empty($errors)) {
        $pdo->rollback();
        throw new Exception(implode('; ', $errors));
    }
    
    // Confirmar transacción
    $pdo->commit();
    
    // Respuesta exitosa
    $response = [
        'success' => true,
        'message' => "Estudio derivado exitosamente a {$subassignedCount} usuario(s)",
        'data' => [
            'study_id' => $studyId,
            'main_user_id' => $mainUserId,
            'subassigned_count' => $subassignedCount,
            'assigned_by' => $assignedByUserId,
            'subassigned_at' => date('Y-m-d H:i:s')
        ]
    ];
    
    // Agregar advertencias si hubo errores
    if (!empty($errors)) {
        $response['warnings'] = $errors;
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollback();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error de base de datos: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollback();
    }
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>

