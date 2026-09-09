<?php
/**
 * API para asignación múltiple de estudios a usuarios
 * Maneja la asignación de múltiples estudios a múltiples usuarios
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
require_once '../classes/User.php';

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
    set_time_limit(120);

    // Validar sesión del usuario actual (obtener del token de sesión, no del input)
    $sessionToken = null;
    
    // Intentar obtener token desde headers
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        $sessionToken = $headers['Authorization'] ?? null;
    }
    
    if (!$sessionToken) {
        $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    }
    
    // Intentar desde POST/input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    if (!$sessionToken) {
        $sessionToken = $input['session_token'] ?? $_POST['session_token'] ?? $_COOKIE['session_token'] ?? null;
    }
    
    // Limpiar Bearer prefix si existe
    if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
        $sessionToken = substr($sessionToken, 7);
    }
    
    if (!$sessionToken) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Token de sesión requerido'
        ]);
        exit;
    }
    
    // Validar sesión
    $user = new User();
    $userData = $user->validateSession($sessionToken);
    
    if (!$userData) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Sesión inválida o expirada'
        ]);
        exit;
    }
    
    // Usar el ID del usuario autenticado (no confiar en el input)
    $assignedBy = $userData['id'];
    
    // Validar datos requeridos
    if (empty($input)) {
        throw new Exception('Datos de entrada inválidos');
    }
    
    $studyIds = $input['study_ids'] ?? [];
    $userIds = $input['user_ids'] ?? [];
    
    // Datos completos de los estudios
    $studiesData = $input['studies_data'] ?? [];

    require_once __DIR__ . '/helpers/study_assignment_metadata.php';
    
    // Validar datos requeridos
    if (empty($studyIds) || empty($userIds)) {
        throw new Exception('Faltan datos requeridos: study_ids, user_ids');
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

    // Verificar columna institution_name una sola vez (fuera de la transacción)
    try {
        $checkColumn = $pdo->query("SHOW COLUMNS FROM study_assignments LIKE 'institution_name'");
        if ($checkColumn->rowCount() == 0) {
            $pdo->exec("ALTER TABLE study_assignments ADD COLUMN institution_name VARCHAR(255) DEFAULT NULL AFTER patient_sex");
        }
    } catch (PDOException $e) {
        error_log("Error verificando campo institution_name: " . $e->getMessage());
    }

    $deleteSQL = "DELETE FROM study_assignments WHERE study_id = ?";
    $deleteStmt = $pdo->prepare($deleteSQL);

    $insertSQL = "INSERT INTO study_assignments (
        study_id, user_id, assigned_by, 
        patient_name, patient_id, study_date, study_time, 
        modality, study_description, accession_number, 
        referring_physician, study_instance_uid, series_count, 
        instances_count, viewer_url, orthanc_study_id,
        patient_birth_date, patient_sex, institution_name
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $insertStmt = $pdo->prepare($insertSQL);
    
    // Iniciar transacción
    $pdo->beginTransaction();
    
    $totalAssignments = 0;
    $assignmentsByStudy = [];
    
    // Procesar cada estudio
    foreach ($studyIds as $studyId) {
        // Eliminar asignaciones previas para este estudio
        $deleteStmt->execute([$studyId]);
        
        // Obtener datos del estudio actual (tolerar claves JSON string/int)
        $studyData = $studiesData[$studyId] ?? [];
        if (empty($studyData) && is_array($studiesData)) {
            foreach ($studiesData as $k => $v) {
                if ((string) $k === (string) $studyId && is_array($v)) {
                    $studyData = $v;
                    break;
                }
            }
        }
        $orthancId = resolveOrthancIdForEnrich($studyData, (string) $studyId);
        $studyData = enrichStudyDataFromOrthanc($studyData, $orthancId);
        
        // Log para depuración
        error_log("🔍 Asignando estudio $studyId - institution_name: " . ($studyData['institution_name'] ?? 'NULL'));
        
        $studyAssignments = 0;
        foreach ($userIds as $userId) {
            $institutionName = $studyData['institution_name'] ?? null;
            error_log("📝 Insertando asignación - study_id: $studyId, user_id: $userId, institution_name: " . ($institutionName ?? 'NULL'));
            
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
                $institutionName
            ]);
            $studyAssignments++;
            $totalAssignments++;
        }
        
        $assignmentsByStudy[$studyId] = $studyAssignments;
    }
    
    // Confirmar transacción
    $pdo->commit();

    try {
        require_once __DIR__ . '/informes/informe_medico_responsable_helper.php';
        $primaryUserId = (int)($userIds[0] ?? 0);
        if ($primaryUserId > 0) {
            foreach ($studyIds as $sid) {
                $sd = $studiesData[$sid] ?? [];
                ir_sync_medico_informes_estudio($pdo, [
                    'estudio_id' => $sid,
                    'study_id' => $sid,
                    'study_instance_uid' => $sd['study_instance_uid'] ?? null,
                    'orthanc_study_id' => $sd['orthanc_study_id'] ?? $sid,
                ], $primaryUserId);
            }
        }
    } catch (Throwable $syncEx) {
        error_log('[ASSIGN_MULTIPLE] sync medico: ' . $syncEx->getMessage());
    }
    
    // Respuesta exitosa
    $response = [
        'success' => true,
        'message' => "Asignación múltiple completada: {$totalAssignments} asignaciones en " . count($studyIds) . " estudios",
        'data' => [
            'total_studies' => count($studyIds),
            'total_users' => count($userIds),
            'total_assignments' => $totalAssignments,
            'assignments_by_study' => $assignmentsByStudy,
            'assigned_by' => $assignedBy,
            'assigned_date' => date('Y-m-d H:i:s')
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
