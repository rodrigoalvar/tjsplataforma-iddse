<?php
/**
 * API para asignar estudios a usuarios
 * Maneja la asignación de estudios específicos a usuarios del sistema
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Solo permitir método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Método no permitido. Use POST.'
    ]);
    exit;
}

// Incluir configuración de base de datos
require_once '../config/database.php';

/**
 * Normaliza el nombre del paciente corrigiendo caracteres problemáticos
 * - Reemplaza comillas dobles (") por apóstrofes (')
 * - Limpia espacios múltiples
 * - Mantiene el formato DICOM estándar
 */
function normalizePatientName($name) {
    if (empty($name)) {
        return $name;
    }
    
    // Reemplazar comillas dobles por apóstrofes (corrección común)
    $name = str_replace('"', "'", $name);
    
    // Limpiar espacios múltiples
    $name = preg_replace('/\s+/', ' ', $name);
    
    // Trim espacios al inicio y final
    $name = trim($name);
    
    return $name;
}

try {
    // Obtener datos del POST
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Datos de entrada inválidos');
    }
    
    $studyId = $input['study_id'] ?? null;
    $userIds = $input['user_ids'] ?? [];
    $assignedBy = $input['assigned_by'] ?? null;
    
    // Datos completos del estudio
    $studyData = $input['study_data'] ?? [];

    require_once __DIR__ . '/helpers/study_assignment_metadata.php';
    $orthancId = resolveOrthancIdForEnrich($studyData, (string) $studyId);
    $studyData = enrichStudyDataFromOrthanc($studyData, $orthancId);
    
    // Validar datos requeridos
    if (!$studyId || empty($userIds) || !$assignedBy) {
        throw new Exception('Faltan datos requeridos: study_id, user_ids, assigned_by');
    }
    
    // Verificar permisos de asignación
    require_once '../middleware/permissions.php';
    $permissionManager = new PermissionManager();
    
    foreach ($userIds as $userId) {
        if (!$permissionManager->canAssignToUser($assignedBy, $userId)) {
            throw new Exception("No tienes permisos para asignar estudios al usuario ID: {$userId}");
        }
    }
    
    // Crear conexión a la base de datos usando la clase Database
    $pdo = getDBConnection();
    
    // Crear tabla de asignaciones si no existe
    $createTableSQL = "
        CREATE TABLE IF NOT EXISTS study_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            study_id VARCHAR(255) NOT NULL,
            user_id INT NOT NULL,
            assigned_by INT NOT NULL,
            assigned_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            status ENUM('active', 'inactive') DEFAULT 'active',
            INDEX idx_study_user (study_id, user_id),
            INDEX idx_user_id (user_id),
            INDEX idx_assigned_by (assigned_by),
            FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            FOREIGN KEY (assigned_by) REFERENCES usuarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    $pdo->exec($createTableSQL);
    
    // Iniciar transacción
    $pdo->beginTransaction();
    
    // Eliminar asignaciones previas para este estudio
    $deleteSQL = "DELETE FROM study_assignments WHERE study_id = ?";
    $deleteStmt = $pdo->prepare($deleteSQL);
    $deleteStmt->execute([$studyId]);
    
    // Verificar y agregar campo institution_name si no existe
    try {
        $checkColumn = $pdo->query("SHOW COLUMNS FROM study_assignments LIKE 'institution_name'");
        if ($checkColumn->rowCount() == 0) {
            $pdo->exec("ALTER TABLE study_assignments ADD COLUMN institution_name VARCHAR(255) DEFAULT NULL AFTER patient_sex");
        }
    } catch (PDOException $e) {
        // Ignorar si el campo ya existe o hay otro error
        error_log("Error verificando campo institution_name: " . $e->getMessage());
    }
    
    // Insertar nuevas asignaciones con datos completos del estudio
    $insertSQL = "INSERT INTO study_assignments (
        study_id, user_id, assigned_by, 
        patient_name, patient_id, study_date, study_time, 
        modality, study_description, accession_number, 
        referring_physician, study_instance_uid, series_count, 
        instances_count, viewer_url, orthanc_study_id,
        patient_birth_date, patient_sex, institution_name
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $insertStmt = $pdo->prepare($insertSQL);
    
    $assignedCount = 0;
    foreach ($userIds as $userId) {
        $insertStmt->execute([
            $studyId, 
            $userId, 
            $assignedBy,
            normalizePatientName($studyData['patient_name'] ?? null),
            $studyData['patient_id'] ?? null,
            $studyData['study_date'] ?? null,
            $studyData['study_time'] ?? null,
            $studyData['modality'] ?? null,
            $studyData['study_description'] ?? null,
            $studyData['accession_number'] ?? null,
            $studyData['referring_physician'] ?? null,
            $studyData['study_instance_uid'] ?? null,
            $studyData['series_count'] ?? 0,
            $studyData['instances_count'] ?? 0,
            $studyData['viewer_url'] ?? null,
            $studyData['orthanc_study_id'] ?? null,
            $studyData['patient_birth_date'] ?? null,
            $studyData['patient_sex'] ?? null,
            $studyData['institution_name'] ?? null
        ]);
        $assignedCount++;
    }
    
    // Confirmar transacción
    $pdo->commit();

    // Sincronizar dueño clínico de informes externos / sin médico del estudio
    try {
        require_once __DIR__ . '/informes/informe_medico_responsable_helper.php';
        $primaryUserId = (int)($userIds[0] ?? 0);
        if ($primaryUserId > 0) {
            ir_sync_medico_informes_estudio($pdo, [
                'estudio_id' => $studyId,
                'study_id' => $studyId,
                'study_instance_uid' => $studyData['study_instance_uid'] ?? null,
                'orthanc_study_id' => $studyData['orthanc_study_id'] ?? $studyId,
            ], $primaryUserId);
        }
    } catch (Throwable $syncEx) {
        error_log('[ASSIGN_STUDY] sync medico informes: ' . $syncEx->getMessage());
    }

    // Timeline SLA: asignado
    try {
        require_once __DIR__ . '/estudios/sla_helper.php';
        sla_mark_assigned_for_study(
            $pdo,
            $studyId,
            date('Y-m-d H:i:s'),
            $studyData['orthanc_study_id'] ?? null,
            $studyData['study_instance_uid'] ?? null
        );
    } catch (Throwable $slaEx) {
        error_log('[ASSIGN_STUDY] sla assigned: ' . $slaEx->getMessage());
    }
    
    // Respuesta exitosa
    $response = [
        'success' => true,
        'message' => "Estudio asignado exitosamente a {$assignedCount} usuario(s)",
        'data' => [
            'study_id' => $studyId,
            'assigned_users' => $assignedCount,
            'assigned_by' => $assignedBy,
            'assigned_date' => date('Y-m-d H:i:s')
        ]
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    // Rollback en caso de error
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollback();
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
        $pdo->rollback();
    }
    
    $response = [
        'success' => false,
        'error' => $e->getMessage()
    ];
    
    http_response_code(400);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
}
?>