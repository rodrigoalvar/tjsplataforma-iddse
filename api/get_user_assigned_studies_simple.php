<?php
/**
 * API simplificada para obtener estudios asignados al usuario actual
 * Funciona solo con la tabla study_assignments
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../../config/database.php';

try {
    // Verificar sesión del usuario
    session_start();
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Usuario no autenticado');
    }
    
    $userId = $_SESSION['user_id'];
    
    // Conectar a la base de datos
    $pdo = new PDO($dsn, $username, $password, $options);
    
    // Obtener información del usuario actual
    $stmt = $pdo->prepare("SELECT id, nombre, apellido, nivel, permisos FROM usuarios WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception('Usuario no encontrado');
    }
    
    // Obtener estudios asignados al usuario
    $query = "
        SELECT 
            sa.id as assignment_id,
            sa.study_id,
            sa.assigned_at,
            sa.assigned_by,
            sa.status as assignment_status,
            sa.notes as assignment_notes,
            sa.patient_name,
            sa.patient_id,
            sa.study_date,
            sa.study_time,
            sa.modality,
            sa.study_description,
            sa.accession_number,
            sa.referring_physician,
            sa.patient_birth_date,
            sa.patient_sex,
            sa.series_count,
            sa.instances_count,
            sa.orthanc_study_id,
            sa.study_instance_uid,
            sa.viewer_url,
            sa.study_status,
            -- Información de antecedentes
            a.notes as antecedents_notes,
            a.has_images,
            a.has_files,
            a.has_camera_captures,
            a.created_at as antecedents_created_at,
            a.updated_at as antecedents_updated_at,
            -- Información del usuario que asignó
            ua.nombre as assigned_by_name,
            ua.apellido as assigned_by_surname
        FROM study_assignments sa
        LEFT JOIN study_antecedents a ON sa.study_id = a.study_id
        LEFT JOIN usuarios ua ON sa.assigned_by = ua.id
        WHERE sa.user_id = ? 
        AND sa.status = 'active'
        ORDER BY sa.assigned_at DESC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$userId]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Procesar los datos para el frontend
    $processedStudies = [];
    
    foreach ($assignments as $assignment) {
        $study = [
            'id' => $assignment['study_id'],
            'assignment_id' => $assignment['assignment_id'],
            'patient_name' => $assignment['patient_name'] ?: 'Paciente no especificado',
            'patient_id' => $assignment['patient_id'] ?: 'ID no disponible',
            'date' => $assignment['study_date'] ?: date('Ymd'),
            'time' => $assignment['study_time'] ?: '000000',
            'modality' => $assignment['modality'] ?: 'OT',
            'study_description' => $assignment['study_description'] ?: 'Estudio asignado',
            'accession_number' => $assignment['accession_number'] ?: '',
            'referring_physician' => $assignment['referring_physician'] ?: '',
            'patient_birth_date' => $assignment['patient_birth_date'] ?: '',
            'patient_sex' => $assignment['patient_sex'] ?: '',
            'series_count' => $assignment['series_count'] ?: 0,
            'instances_count' => $assignment['instances_count'] ?: 0,
            'orthanc_study_id' => $assignment['orthanc_study_id'] ?: $assignment['study_id'],
            'study_instance_uid' => $assignment['study_instance_uid'] ?: '',
            'viewer_url' => $assignment['viewer_url'] ?: '#',
            'status' => $assignment['study_status'] ?: 'assigned',
            'assigned_at' => $assignment['assigned_at'],
            'assigned_by' => $assignment['assigned_by_name'] . ' ' . $assignment['assigned_by_surname'],
            'assignment_notes' => $assignment['assignment_notes'] ?: '',
            'antecedents' => [
                'has_notes' => !empty($assignment['antecedents_notes']),
                'has_images' => $assignment['has_images'] ?: false,
                'has_files' => $assignment['has_files'] ?: false,
                'has_camera_captures' => $assignment['has_camera_captures'] ?: false,
                'notes' => $assignment['antecedents_notes'] ?: '',
                'created_at' => $assignment['antecedents_created_at'],
                'updated_at' => $assignment['antecedents_updated_at'],
                'total_count' => 0
            ]
        ];
        
        // Calcular total de antecedentes
        $antecedentsCount = 0;
        if ($study['antecedents']['has_notes']) $antecedentsCount++;
        if ($study['antecedents']['has_images']) $antecedentsCount++;
        if ($study['antecedents']['has_files']) $antecedentsCount++;
        if ($study['antecedents']['has_camera_captures']) $antecedentsCount++;
        $study['antecedents']['total_count'] = $antecedentsCount;
        
        $processedStudies[] = $study;
    }
    
    // Respuesta exitosa
    echo json_encode([
        'success' => true,
        'data' => [
            'studies' => $processedStudies,
            'user' => [
                'id' => $user['id'],
                'name' => $user['nombre'] . ' ' . $user['apellido'],
                'level' => $user['nivel'],
                'permissions' => json_decode($user['permisos'], true) ?: []
            ],
            'total' => count($processedStudies),
            'message' => 'Estudios asignados obtenidos correctamente'
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'data' => null
    ], JSON_UNESCAPED_UNICODE);
}
?>
