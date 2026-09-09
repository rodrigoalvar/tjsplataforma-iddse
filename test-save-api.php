<?php
/**
 * Test script para probar la API de guardado
 */

// Simular datos que envía JavaScript
$testData = [
    'id' => 13,
    'estudio_id' => 'TEST_STUDY_001', // Usar el estudio_id real del informe
    'patient_id' => 'PAT_001',
    'patient_name' => 'Juan Pérez García',
    'modality' => 'CT',
    'study_description' => 'TC de Tórax con contraste',
    'contenido_html' => '<p>Contenido actualizado desde test API</p><p>Este es un test de guardado.</p>',
    'titulo' => 'Informe actualizado via API',
    'estado' => 'borrador',
    'notas_revision' => 'Actualizado mediante test'
];

// Obtener token de sesión (simular)
require_once 'classes/User.php';
require_once 'config/database.php';

try {
    $db = getDBConnection();
    
    // Obtener un token de sesión válido de la tabla sesiones
    $query = "SELECT s.token_sesion, u.id as user_id, u.nombre 
              FROM sesiones s 
              JOIN usuarios u ON s.usuario_id = u.id 
              WHERE s.activa = 1 AND s.fecha_expiracion > NOW() AND u.activo = 1 
              LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $session = $stmt->fetch();
    
    if (!$session) {
        throw new Exception('No se encontró sesión activa');
    }
    
    $sessionToken = $session['token_sesion'];
    echo "<p><strong>Usuario:</strong> {$session['nombre']} (ID: {$session['user_id']})</p>";
    echo "<p><strong>Token:</strong> " . substr($sessionToken, 0, 20) . "...</p>";
    
    echo "<h2>Test Save API</h2>";
    echo "<h3>Datos a enviar:</h3>";
    echo "<pre>" . json_encode($testData, JSON_PRETTY_PRINT) . "</pre>";
    
    // Preparar datos para cURL
    $testData['session_token'] = $sessionToken;
    
    // Hacer petición POST a save.php
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://127.0.0.1:8010/api/informes/save.php');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $sessionToken
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    echo "<h3>Respuesta de la API:</h3>";
    echo "<p><strong>HTTP Code:</strong> $httpCode</p>";
    
    if ($error) {
        echo "<p style='color: red;'><strong>cURL Error:</strong> $error</p>";
    }
    
    if ($response) {
        $responseData = json_decode($response, true);
        if ($responseData) {
            echo "<pre>" . json_encode($responseData, JSON_PRETTY_PRINT) . "</pre>";
            
            if ($responseData['success']) {
                echo "<p style='color: green;'>✓ Guardado exitoso</p>";
                
                // Verificar en base de datos
                echo "<h3>Verificando en base de datos:</h3>";
                $checkQuery = "SELECT * FROM informes WHERE id = ?";
                $checkStmt = $db->prepare($checkQuery);
                $checkStmt->execute([13]);
                $updatedReport = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($updatedReport) {
                    echo "<pre>" . json_encode($updatedReport, JSON_PRETTY_PRINT) . "</pre>";
                } else {
                    echo "<p style='color: red;'>No se encontró el informe actualizado</p>";
                }
            } else {
                echo "<p style='color: red;'>✗ Error en el guardado: " . ($responseData['message'] ?? 'Error desconocido') . "</p>";
            }
        } else {
            echo "<p style='color: red;'>Respuesta JSON inválida:</p>";
            echo "<pre>" . htmlspecialchars($response) . "</pre>";
        }
    } else {
        echo "<p style='color: red;'>No se recibió respuesta</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>