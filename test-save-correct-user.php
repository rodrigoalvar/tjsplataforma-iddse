<?php
/**
 * Test script para probar el guardado con el usuario correcto
 */

require_once 'classes/User.php';
require_once 'config/database.php';

echo "<h2>Test Save API - Usuario Correcto</h2>";

try {
    $db = getDBConnection();
    
    // Obtener el informe y su propietario
    $query = "SELECT * FROM informes WHERE id = 13";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $informe = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$informe) {
        throw new Exception('Informe no encontrado');
    }
    
    echo "<h3>Informe actual:</h3>";
    echo "<p><strong>ID:</strong> {$informe['id']}</p>";
    echo "<p><strong>Propietario:</strong> Usuario ID {$informe['usuario_id']}</p>";
    echo "<p><strong>Título actual:</strong> {$informe['titulo']}</p>";
    
    // Buscar sesión activa del propietario del informe
    $sessionQuery = "SELECT s.token_sesion, u.id as user_id, u.nombre 
                     FROM sesiones s 
                     JOIN usuarios u ON s.usuario_id = u.id 
                     WHERE s.activa = 1 AND s.fecha_expiracion > NOW() 
                     AND u.activo = 1 AND u.id = ? 
                     LIMIT 1";
    $sessionStmt = $db->prepare($sessionQuery);
    $sessionStmt->execute([$informe['usuario_id']]);
    $session = $sessionStmt->fetch();
    
    if (!$session) {
        // Crear una sesión temporal para el propietario
        echo "<p style='color: orange;'>No hay sesión activa para el propietario. Creando sesión temporal...</p>";
        
        $user = new User();
        // Obtener datos del usuario propietario
        $userQuery = "SELECT * FROM usuarios WHERE id = ? AND activo = 1";
        $userStmt = $db->prepare($userQuery);
        $userStmt->execute([$informe['usuario_id']]);
        $userData = $userStmt->fetch();
        
        if (!$userData) {
            throw new Exception('Usuario propietario no encontrado o inactivo');
        }
        
        // Crear sesión manualmente
        $sessionToken = bin2hex(random_bytes(32));
        $expiration = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        $insertSession = "INSERT INTO sesiones (usuario_id, token_sesion, fecha_expiracion, fecha_creacion, activa) 
                         VALUES (?, ?, ?, NOW(), 1)";
        $insertStmt = $db->prepare($insertSession);
        $insertStmt->execute([$informe['usuario_id'], $sessionToken, $expiration]);
        
        $session = [
            'token_sesion' => $sessionToken,
            'user_id' => $informe['usuario_id'],
            'nombre' => $userData['nombre']
        ];
    }
    
    echo "<p><strong>Usuario con sesión:</strong> {$session['nombre']} (ID: {$session['user_id']})</p>";
    echo "<p><strong>Token:</strong> " . substr($session['token_sesion'], 0, 20) . "...</p>";
    
    // Datos de prueba para actualizar
    $testData = [
        'id' => 13,
        'estudio_id' => $informe['estudio_id'],
        'patient_id' => $informe['patient_id'],
        'patient_name' => $informe['patient_name'],
        'modality' => $informe['modality'],
        'study_description' => $informe['study_description'],
        'contenido_html' => '<p>Contenido actualizado correctamente desde test API</p><p>Este test verifica que el guardado funciona con el usuario correcto.</p>',
        'titulo' => 'Informe actualizado - Test exitoso',
        'estado' => 'borrador',
        'notas_revision' => 'Actualizado mediante test con usuario correcto'
    ];
    
    echo "<h3>Datos a enviar:</h3>";
    echo "<pre>" . json_encode($testData, JSON_PRETTY_PRINT) . "</pre>";
    
    // Preparar datos para cURL
    $testData['session_token'] = $session['token_sesion'];
    
    // Hacer petición POST a save.php
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://127.0.0.1:8010/api/informes/save.php');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $session['token_sesion']
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
                echo "<h3>Verificando cambios en base de datos:</h3>";
                $checkQuery = "SELECT * FROM informes WHERE id = ?";
                $checkStmt = $db->prepare($checkQuery);
                $checkStmt->execute([13]);
                $updatedReport = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($updatedReport) {
                    echo "<p><strong>Título actualizado:</strong> {$updatedReport['titulo']}</p>";
                    echo "<p><strong>Fecha modificación:</strong> {$updatedReport['fecha_modificacion']}</p>";
                    echo "<p><strong>Contenido HTML:</strong></p>";
                    echo "<div style='border: 1px solid #ccc; padding: 10px; margin: 10px 0;'>";
                    echo $updatedReport['contenido_html'];
                    echo "</div>";
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