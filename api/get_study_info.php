<?php
/**
 * API para obtener información de un estudio específico
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Incluir configuración de base de datos
require_once '../config/database.php';

try {
    $studyId = $_GET['study_id'] ?? null;
    
    if (!$studyId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'study_id es requerido']);
        return;
    }
    
    // Crear conexión a la base de datos
    $pdo = getDBConnection();
    
    // Consultar información del estudio desde Orthanc (simulado)
    // En producción, esto vendría de una consulta real a Orthanc
    $studyData = [
        'study_id' => $studyId,
        'patient_name' => 'Paciente ' . substr($studyId, -8),
        'patient_id' => substr($studyId, -8),
        'modality' => 'CT',
        'date' => date('d/m/Y'),
        'study_description' => 'Estudio médico',
        'accession_number' => 'ACC' . substr($studyId, -6)
    ];
    
    echo json_encode([
        'success' => true,
        'data' => $studyData
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error interno: ' . $e->getMessage()]);
}
?>

