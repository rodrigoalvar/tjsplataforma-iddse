<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    require_once '../config/database.php';
    
    // Conectar a la base de datos
    $pdo = getDBConnection();
    
    // Obtener estructura de la tabla study_assignments
    $stmt = $pdo->query("DESCRIBE study_assignments");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Contar registros en la tabla
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM study_assignments");
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Obtener algunos registros de ejemplo
    $stmt = $pdo->query("SELECT * FROM study_assignments LIMIT 3");
    $samples = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Verificar campos específicos que deberían existir
    $expectedFields = [
        'id', 'study_id', 'user_id', 'assigned_by', 'assigned_date', 'status',
        'patient_name', 'patient_id', 'study_date', 'study_time', 'modality',
        'study_description', 'accession_number', 'referring_physician',
        'study_instance_uid', 'series_count', 'instances_count', 'viewer_url',
        'orthanc_study_id', 'patient_birth_date', 'patient_sex'
    ];
    
    $existingFields = array_column($columns, 'Field');
    $missingFields = array_diff($expectedFields, $existingFields);
    $extraFields = array_diff($existingFields, $expectedFields);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'table_exists' => !empty($columns),
            'total_records' => $count['total'],
            'columns' => $columns,
            'sample_records' => $samples,
            'field_analysis' => [
                'expected_fields' => $expectedFields,
                'existing_fields' => $existingFields,
                'missing_fields' => $missingFields,
                'extra_fields' => $extraFields,
                'all_fields_present' => empty($missingFields)
            ]
        ],
        'message' => 'Estructura de tabla verificada correctamente'
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