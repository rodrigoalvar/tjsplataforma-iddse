<?php
/**
 * API de prueba para obtener estudios asignados al usuario actual
 * Versión simplificada para testing sin autenticación
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

require_once '../config/database.php';

try {
    // Para testing, usar usuario ID 2 (admin)
    $userId = 2;
    
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
            sa.assigned_date,
            sa.assigned_by,
            sa.status as assignment_status,
            -- Información de antecedentes
            a.notes as antecedents_notes,
            a.created_date as antecedents_created_at,
            a.updated_date as antecedents_updated_at,
            -- Información del usuario que asignó
            ua.nombre as assigned_by_name,
            ua.apellido as assigned_by_surname
        FROM study_assignments sa
        LEFT JOIN study_antecedents a ON sa.study_id = a.study_id
        LEFT JOIN usuarios ua ON sa.assigned_by = ua.id
        WHERE sa.user_id = ? 
        AND sa.status = 'active'
        ORDER BY sa.assigned_date DESC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$userId]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Si no hay asignaciones reales, crear datos de ejemplo
    if (empty($assignments)) {
        $assignments = [
            [
                'assignment_id' => 1,
                'study_id' => 'ST001',
                'assigned_date' => date('Y-m-d H:i:s'),
                'assigned_by' => 1,
                'assignment_status' => 'active',
                'antecedents_notes' => 'Paciente con antecedentes de neumonía',
                'antecedents_created_at' => date('Y-m-d H:i:s'),
                'antecedents_updated_at' => date('Y-m-d H:i:s'),
                'assigned_by_name' => 'Usuario',
                'assigned_by_surname' => 'ROOT'
            ],
            [
                'assignment_id' => 2,
                'study_id' => 'ST002',
                'assigned_date' => date('Y-m-d H:i:s'),
                'assigned_by' => 1,
                'assignment_status' => 'active',
                'antecedents_notes' => '',
                'antecedents_created_at' => null,
                'antecedents_updated_at' => null,
                'assigned_by_name' => 'Usuario',
                'assigned_by_surname' => 'ROOT'
            ]
        ];
    }
    
    // Procesar los datos para el frontend
    $processedStudies = [];
    
    foreach ($assignments as $assignment) {
        // Generar datos de ejemplo basados en el study_id
        $studyId = $assignment['study_id'];
        $isExample = strpos($studyId, 'ST') === 0;
        
        $study = [
            'id' => $studyId,
            'assignment_id' => $assignment['assignment_id'],
            'patient_name' => $isExample ? 'Paciente ' . substr($studyId, -3) : 'Paciente ' . substr($studyId, 0, 8),
            'patient_id' => $isExample ? 'P' . substr($studyId, -3) : substr($studyId, 0, 8),
            'date' => date('Ymd'),
            'time' => '140000',
            'modality' => $isExample ? ($studyId === 'ST001' ? 'CT' : 'MR') : 'OT',
            'study_description' => $isExample ? 
                ($studyId === 'ST001' ? 'Tomografía de tórax' : 'Resonancia magnética de cráneo') : 
                'Estudio asignado',
            'accession_number' => 'ACC' . substr($studyId, -3),
            'referring_physician' => 'Dr. García',
            'patient_birth_date' => '19800101',
            'patient_sex' => 'M',
            'series_count' => 3,
            'instances_count' => 150,
            'orthanc_study_id' => $studyId, // Usar el mismo ID para que funcione la descarga
            'study_instance_uid' => '1.2.3.4.5.6.7.8.' . substr($studyId, -1),
            'viewer_url' => 'https://demoportal.tanjousoft.com.ar/visorweb/studies/' . $studyId . '/viewer', // URL real del visor
            'status' => 'assigned',
            'assigned_at' => $assignment['assigned_date'],
            'assigned_by' => $assignment['assigned_by_name'] . ' ' . $assignment['assigned_by_surname'],
            'assignment_notes' => 'Estudio asignado para revisión',
            'antecedents' => [
                'has_notes' => !empty($assignment['antecedents_notes']),
                'has_images' => false, // No disponible en la tabla actual
                'has_files' => false, // No disponible en la tabla actual
                'has_camera_captures' => false, // No disponible en la tabla actual
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
