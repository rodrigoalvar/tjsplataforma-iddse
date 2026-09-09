<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    // ID del usuario TUCUMAN INFORMANTES
    $userId = 10;
    
    echo "=== SIMULACIÓN API PARA USUARIO TUCUMAN (ID: $userId) ===\n\n";
    
    // Consulta igual a la de la API
    $query = "
        SELECT 
            sa.id as assignment_id,
            sa.study_id,
            sa.assigned_date,
            sa.assigned_by,
            sa.status as assignment_status,
            sa.patient_name,
            sa.patient_id,
            sa.study_date,
            sa.study_time,
            sa.modality,
            sa.study_description,
            sa.accession_number,
            sa.referring_physician,
            sa.study_instance_uid,
            sa.series_count,
            sa.instances_count,
            sa.viewer_url,
            sa.orthanc_study_id,
            sa.patient_birth_date,
            sa.patient_sex,
            a.id as antecedents_id,
            a.notes as antecedents_notes,
            a.created_date as antecedents_created_at,
            a.updated_date as antecedents_updated_at,
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
    
    echo "Estudios encontrados: " . count($assignments) . "\n\n";
    
    $processedStudies = [];
    
    foreach ($assignments as $assignment) {
        echo "=== PROCESANDO ESTUDIO: {$assignment['study_id']} ===\n";
        
        // Crear estructura de antecedentes
        $antecedents = [
            'has_notes' => !empty($assignment['antecedents_notes']),
            'has_images' => false,
            'has_files' => false,
            'has_camera_captures' => false,
            'notes' => $assignment['antecedents_notes'] ?: '',
            'created_at' => $assignment['antecedents_created_at'],
            'updated_at' => $assignment['antecedents_updated_at'],
            'total_count' => 0
        ];
        
        echo "Antecedents ID: " . ($assignment['antecedents_id'] ?: 'NULL') . "\n";
        echo "Has notes: " . ($antecedents['has_notes'] ? 'SÍ' : 'NO') . "\n";
        
        // Verificar archivos si hay ID de antecedentes
        if (!empty($assignment['antecedents_id'])) {
            $filesStmt = $pdo->prepare('SELECT COUNT(*) as file_count FROM study_antecedents_files WHERE antecedent_id = ?');
            $filesStmt->execute([$assignment['antecedents_id']]);
            $fileCount = $filesStmt->fetchColumn();
            $antecedents['has_files'] = $fileCount > 0;
            echo "File count: $fileCount\n";
            echo "Has files: " . ($antecedents['has_files'] ? 'SÍ' : 'NO') . "\n";
        }
        
        // Calcular total
        $antecedentsCount = 0;
        if ($antecedents['has_notes']) $antecedentsCount++;
        if ($antecedents['has_images']) $antecedentsCount++;
        if ($antecedents['has_files']) $antecedentsCount++;
        if ($antecedents['has_camera_captures']) $antecedentsCount++;
        
        // Si hay notas pero el contador es 0, asegurar que sea al menos 1
        if (!empty($assignment['antecedents_notes']) && $antecedentsCount === 0) {
            $antecedentsCount = 1;
        }
        
        $antecedents['total_count'] = $antecedentsCount;
        
        echo "Total count calculado: $antecedentsCount\n";
        echo "---\n\n";
        
        $processedStudies[] = [
            'study_id' => $assignment['study_id'],
            'patient_name' => $assignment['patient_name'],
            'antecedents' => $antecedents
        ];
    }
    
    echo "=== RESUMEN FINAL ===\n";
    foreach ($processedStudies as $study) {
        echo "Study: {$study['study_id']}\n";
        echo "  Total count: {$study['antecedents']['total_count']}\n";
        echo "  Has notes: " . ($study['antecedents']['has_notes'] ? 'SÍ' : 'NO') . "\n";
        echo "  Has files: " . ($study['antecedents']['has_files'] ? 'SÍ' : 'NO') . "\n";
        echo "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}