<?php
// Simular la llamada a la API para el usuario TUCUMAN INFORMANTES
$_COOKIE['session_token'] = 'test_token_tucuman';

// Incluir el archivo de la API
ob_start();
include 'api/get_user_assigned_studies_fixed.php';
$response = ob_get_clean();

echo "=== RESPUESTA DE LA API ===\n";
echo $response . "\n";

// Decodificar y mostrar información específica de antecedentes
$data = json_decode($response, true);
if ($data && isset($data['data']['studies'])) {
    echo "\n=== ANÁLISIS DE ANTECEDENTES ===\n";
    foreach ($data['data']['studies'] as $study) {
        echo "Study ID: " . $study['study_id'] . "\n";
        echo "  - has_notes: " . ($study['antecedents']['has_notes'] ? 'SÍ' : 'NO') . "\n";
        echo "  - has_files: " . ($study['antecedents']['has_files'] ? 'SÍ' : 'NO') . "\n";
        echo "  - total_count: " . $study['antecedents']['total_count'] . "\n";
        echo "  - notes: " . (empty($study['antecedents']['notes']) ? 'VACÍO' : 'TIENE CONTENIDO') . "\n";
        echo "---\n";
    }
}