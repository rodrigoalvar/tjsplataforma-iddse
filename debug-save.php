<?php
/**
 * Debug script para probar el guardado de informes
 */

require_once 'classes/User.php';
require_once 'config/database.php';

echo "<h2>Debug Save Report</h2>";

// Simular datos de guardado
$testData = [
    'id' => 13,
    'estudio_id' => 'EST001',
    'patient_id' => 'PAT001',
    'contenido_html' => '<p>Contenido de prueba actualizado</p>',
    'titulo' => 'Informe de prueba actualizado',
    'estado' => 'borrador'
];

echo "<h3>Datos de prueba:</h3>";
echo "<pre>" . json_encode($testData, JSON_PRETTY_PRINT) . "</pre>";

// Verificar informe existente
try {
    $db = getDBConnection();
    
    echo "<h3>Verificando informe existente:</h3>";
    $query = "SELECT * FROM informes WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([13]);
    $informe = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($informe) {
        echo "<pre>" . json_encode($informe, JSON_PRETTY_PRINT) . "</pre>";
        
        // Probar actualización
        echo "<h3>Probando actualización:</h3>";
        
        $contenidoTexto = strip_tags($testData['contenido_html']);
        $contenidoTexto = html_entity_decode($contenidoTexto, ENT_QUOTES, 'UTF-8');
        $contenidoTexto = preg_replace('/\s+/', ' ', trim($contenidoTexto));
        
        $updateQuery = "UPDATE informes SET 
                        contenido_html = ?,
                        contenido_texto = ?,
                        titulo = ?,
                        estado = ?,
                        fecha_modificacion = CURRENT_TIMESTAMP
                        WHERE id = ?";
        
        $updateStmt = $db->prepare($updateQuery);
        $result = $updateStmt->execute([
            $testData['contenido_html'],
            $contenidoTexto,
            $testData['titulo'],
            $testData['estado'],
            13
        ]);
        
        if ($result) {
            echo "<p style='color: green;'>✓ Actualización exitosa</p>";
            
            // Verificar cambios
            $stmt->execute([13]);
            $informeActualizado = $stmt->fetch(PDO::FETCH_ASSOC);
            echo "<h4>Informe actualizado:</h4>";
            echo "<pre>" . json_encode($informeActualizado, JSON_PRETTY_PRINT) . "</pre>";
        } else {
            echo "<p style='color: red;'>✗ Error en la actualización</p>";
        }
        
    } else {
        echo "<p style='color: red;'>No se encontró el informe con ID 13</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}

echo "<h3>Simulando llamada a save.php:</h3>";

// Simular datos que envía JavaScript
$jsData = [
    'id' => 13,
    'estudio_id' => 'EST001', // Este campo es requerido por save.php
    'patient_id' => 'PAT001',
    'contenido_html' => '<p>Contenido desde JavaScript</p>',
    'titulo' => 'Título desde JS',
    'estado' => 'borrador'
];

echo "<p>Datos que debería enviar JavaScript:</p>";
echo "<pre>" . json_encode($jsData, JSON_PRETTY_PRINT) . "</pre>";

echo "<p><strong>Problema identificado:</strong> JavaScript envía 'id' del informe, pero save.php necesita 'estudio_id'</p>";
?>