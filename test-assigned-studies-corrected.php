<?php
echo "=== PRUEBA DE API DE ESTUDIOS ASIGNADOS CORREGIDA ===\n\n";

// Configuración de base de datos
$host = 'localhost';
$dbname = 'tjsmedical';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ Conexión a base de datos exitosa\n\n";
    
    // Para testing, usar usuario ID 2 (admin)
    $userId = 2;
    
    // Obtener información del usuario actual
    $stmt = $pdo->prepare("SELECT id, nombre, apellido, nivel, permisos FROM usuarios WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception('Usuario no encontrado');
    }
    
    echo "👤 Usuario encontrado: {$user['nombre']} {$user['apellido']} ({$user['nivel']})\n\n";
    
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
    
    echo "📊 Asignaciones encontradas: " . count($assignments) . "\n\n";
    
    // Procesar los datos para el frontend
    $processedStudies = [];
    
    foreach ($assignments as $assignment) {
        // Generar datos de ejemplo basados en el study_id
        $studyId = $assignment['study_id'];
        
        $study = [
            'id' => $studyId,
            'assignment_id' => $assignment['assignment_id'],
            'patient_name' => 'Paciente ' . substr($studyId, 0, 8),
            'patient_id' => substr($studyId, 0, 8),
            'date' => date('Ymd'),
            'time' => '140000',
            'modality' => 'OT',
            'study_description' => 'Estudio asignado',
            'accession_number' => 'ACC' . substr($studyId, -3),
            'referring_physician' => 'Dr. García',
            'patient_birth_date' => '19800101',
            'patient_sex' => 'M',
            'series_count' => 3,
            'instances_count' => 150,
            'orthanc_study_id' => $studyId,
            'study_instance_uid' => '1.2.3.4.5.6.7.8.' . substr($studyId, -1),
            'viewer_url' => '#',
            'status' => 'assigned',
            'assigned_at' => $assignment['assigned_date'],
            'assigned_by' => $assignment['assigned_by_name'] . ' ' . $assignment['assigned_by_surname'],
            'assignment_notes' => 'Estudio asignado para revisión',
            'antecedents' => [
                'has_notes' => !empty($assignment['antecedents_notes']),
                'has_images' => false,
                'has_files' => false,
                'has_camera_captures' => false,
                'notes' => $assignment['antecedents_notes'] ?: '',
                'created_at' => $assignment['antecedents_created_at'],
                'updated_at' => $assignment['antecedents_updated_at'],
                'total_count' => 0
            ]
        ];
        
        // Calcular total de antecedentes
        $antecedentsCount = 0;
        if ($study['antecedents']['has_notes']) $antecedentsCount++;
        $study['antecedents']['total_count'] = $antecedentsCount;
        
        $processedStudies[] = $study;
        
        echo "📋 Estudio procesado:\n";
        echo "   • ID: {$study['id']}\n";
        echo "   • Paciente: {$study['patient_name']} ({$study['patient_id']})\n";
        echo "   • Modalidad: {$study['modality']}\n";
        echo "   • Descripción: {$study['study_description']}\n";
        echo "   • Asignado por: {$study['assigned_by']}\n";
        echo "   • Antecedentes: {$study['antecedents']['total_count']} elementos\n";
        echo "   • Notas: " . (empty($study['antecedents']['notes']) ? 'No' : 'Sí') . "\n\n";
    }
    
    // Simular respuesta JSON
    $response = [
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
    ];
    
    echo "📤 RESPUESTA JSON SIMULADA:\n";
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    
    echo "✅ Prueba completada exitosamente\n";
    
} catch (PDOException $e) {
    echo "❌ Error de conexión: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
