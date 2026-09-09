<?php
/**
 * Test para demostrar el conflicto entre las dos APIs de antecedentes
 */

require_once 'config/database.php';

echo "<h1>Test: Conflicto entre APIs de Antecedentes</h1>\n";

// Simular cookie de sesión para TUCUMAN
$_COOKIE['session_token'] = 'test_token_tucuman';

echo "<h2>1. API: get_user_assigned_studies_fixed.php</h2>\n";
echo "<p>Esta API devuelve TODOS los estudios con sus datos de antecedentes:</p>\n";

// Simular llamada a get_user_assigned_studies_fixed.php
ob_start();
include 'api/get_user_assigned_studies_fixed.php';
$api1_output = ob_get_clean();

$api1_data = json_decode($api1_output, true);

if ($api1_data && $api1_data['success']) {
    $studies = $api1_data['data']['studies'];
    echo "<pre>";
    foreach ($studies as $study) {
        echo "Estudio {$study['study_id']}:\n";
        echo "  - total_count: " . ($study['antecedents']['total_count'] ?? 0) . "\n";
        echo "  - has_notes: " . ($study['antecedents']['has_notes'] ? 'true' : 'false') . "\n";
        echo "  - has_files: " . ($study['antecedents']['has_files'] ? 'true' : 'false') . "\n";
        echo "\n";
    }
    echo "</pre>";
    
    // Obtener IDs de estudios
    $study_ids = array_map(function($study) { return $study['study_id']; }, $studies);
    
    echo "<h2>2. API: study_antecedents.php</h2>\n";
    echo "<p>Esta API solo devuelve estudios que TIENEN antecedentes:</p>\n";
    
    // Simular llamada a study_antecedents.php
    $url = "http://localhost:8080/api/study_antecedents.php?study_ids=" . implode(',', $study_ids);
    
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Cookie: session_token=test_token_tucuman\r\n"
        ]
    ]);
    
    $api2_output = file_get_contents($url, false, $context);
    $api2_data = json_decode($api2_output, true);
    
    echo "<pre>";
    if ($api2_data && $api2_data['success']) {
        echo "Estudios devueltos por study_antecedents.php:\n";
        foreach ($api2_data['data'] as $item) {
            echo "Estudio {$item['study_id']}:\n";
            echo "  - total_antecedents: {$item['total_antecedents']}\n";
            echo "  - files_count: {$item['files_count']}\n";
            echo "  - has_notes: {$item['has_notes']}\n";
            echo "\n";
        }
    } else {
        echo "Error o sin datos: " . json_encode($api2_data) . "\n";
    }
    echo "</pre>";
    
    echo "<h2>3. Análisis del Conflicto</h2>\n";
    echo "<div style='background: #fff3cd; padding: 15px; border: 1px solid #ffc107; border-radius: 5px;'>\n";
    echo "<h3>🚨 PROBLEMA IDENTIFICADO:</h3>\n";
    echo "<ul>\n";
    echo "<li><strong>get_user_assigned_studies_fixed.php</strong> devuelve " . count($studies) . " estudios con datos completos</li>\n";
    echo "<li><strong>study_antecedents.php</strong> devuelve " . (isset($api2_data['data']) ? count($api2_data['data']) : 0) . " estudios (solo los que tienen antecedentes)</li>\n";
    echo "<li>El dashboard llama primero a la API 1, luego a la API 2</li>\n";
    echo "<li>La API 2 <strong>sobrescribe</strong> los datos de la API 1, perdiendo información</li>\n";
    echo "</ul>\n";
    echo "</div>\n";
    
    echo "<h2>4. Solución Propuesta</h2>\n";
    echo "<div style='background: #d4edda; padding: 15px; border: 1px solid #28a745; border-radius: 5px;'>\n";
    echo "<h3>✅ OPCIONES:</h3>\n";
    echo "<ol>\n";
    echo "<li><strong>Opción A:</strong> Usar solo get_user_assigned_studies_fixed.php (ya tiene todos los datos)</li>\n";
    echo "<li><strong>Opción B:</strong> Modificar study_antecedents.php para devolver TODOS los estudios</li>\n";
    echo "<li><strong>Opción C:</strong> Modificar el dashboard para no sobrescribir datos existentes</li>\n";
    echo "</ol>\n";
    echo "</div>\n";
    
} else {
    echo "<p style='color: red;'>Error en API 1: " . json_encode($api1_data) . "</p>\n";
}
?>