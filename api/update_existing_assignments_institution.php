<?php
/**
 * Script para actualizar institution_name en registros existentes de study_assignments
 * Obtiene el institution_name desde Orthanc usando el orthanc_study_id
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/OrthancClient.php';
require_once __DIR__ . '/config/orthanc_config.php';

try {
    $pdo = getDBConnection();
    $orthancClient = new OrthancClient();
    
    echo "=== ACTUALIZANDO institution_name EN study_assignments ===\n\n";
    
    // Obtener todos los registros sin institution_name pero con orthanc_study_id
    $stmt = $pdo->query("
        SELECT id, study_id, orthanc_study_id, patient_name 
        FROM study_assignments 
        WHERE (institution_name IS NULL OR institution_name = '') 
        AND orthanc_study_id IS NOT NULL 
        AND orthanc_study_id != ''
        LIMIT 100
    ");
    
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total = count($records);
    
    echo "Registros encontrados sin institution_name: $total\n\n";
    
    if ($total == 0) {
        echo "✅ No hay registros para actualizar.\n";
        exit(0);
    }
    
    $updated = 0;
    $errors = 0;
    
    foreach ($records as $record) {
        $assignmentId = $record['id'];
        $orthancStudyId = $record['orthanc_study_id'];
        $patientName = $record['patient_name'];
        
        echo "Procesando registro ID: $assignmentId, Orthanc ID: $orthancStudyId, Paciente: $patientName\n";
        
        try {
            // Obtener detalles del estudio desde Orthanc
            $studyDetails = $orthancClient->getStudyDetails($orthancStudyId);
            
            if ($studyDetails && isset($studyDetails['institution_name'])) {
                $institutionName = $studyDetails['institution_name'];
                
                if (!empty($institutionName)) {
                    // Actualizar el registro
                    $updateStmt = $pdo->prepare("UPDATE study_assignments SET institution_name = ? WHERE id = ?");
                    $updateStmt->execute([$institutionName, $assignmentId]);
                    
                    echo "  ✅ Actualizado: $institutionName\n";
                    $updated++;
                } else {
                    echo "  ⚠️ InstitutionName vacío en Orthanc\n";
                }
            } else {
                echo "  ⚠️ No se pudo obtener institution_name desde Orthanc\n";
            }
            
        } catch (Exception $e) {
            echo "  ❌ Error: " . $e->getMessage() . "\n";
            $errors++;
        }
        
        // Pequeña pausa para no sobrecargar Orthanc
        usleep(100000); // 0.1 segundos
    }
    
    echo "\n=== RESUMEN ===\n";
    echo "Total procesados: $total\n";
    echo "Actualizados: $updated\n";
    echo "Errores: $errors\n";
    echo "\n✅ Proceso completado.\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>

