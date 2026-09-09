<?php
/**
 * Script para probar la API get.php desde JavaScript y verificar los datos del modal
 */

require_once 'config/database.php';
require_once 'classes/User.php';

try {
    // Obtener una sesión válida
    $db = getDBConnection();
    
    // Obtener el primer usuario activo
    $userStmt = $db->query("SELECT * FROM usuarios WHERE activo = 1 LIMIT 1");
    $userData = $userStmt->fetch();
    
    if (!$userData) {
        echo "<div class='alert alert-danger'>No hay usuarios activos</div>";
        exit;
    }
    
    // Obtener o crear una sesión para este usuario
    $sessionQuery = "SELECT * FROM sesiones WHERE usuario_id = ? AND activo = 1 AND fecha_expiracion > NOW() LIMIT 1";
    $sessionStmt = $db->prepare($sessionQuery);
    $sessionStmt->execute([$userData['id']]);
    $session = $sessionStmt->fetch();
    
    if (!$session) {
        // Crear una sesión temporal
        $token = bin2hex(random_bytes(32));
        $insertSession = "INSERT INTO sesiones (usuario_id, token, fecha_expiracion, activo) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), 1)";
        $insertStmt = $db->prepare($insertSession);
        $insertStmt->execute([$userData['id'], $token]);
        $sessionToken = $token;
    } else {
        $sessionToken = $session['token'];
    }
    
    echo "<h3>Datos de la sesión:</h3>";
    echo "<p>Usuario: {$userData['nombre']} (ID: {$userData['id']})</p>";
    echo "<p>Token: " . substr($sessionToken, 0, 20) . "...</p>";
    
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
    exit;
}
?>

<!DOCTYPE html>
<html lang='es'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Test Modal API</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
</head>
<body>
    <div class='container mt-5'>
        <div class='row justify-content-center'>
            <div class='col-md-10'>
                <div class='card'>
                    <div class='card-body'>
                        <h1 class='card-title'>Test: API get.php desde JavaScript</h1>
                        <hr>
                        
                        <button class='btn btn-primary' onclick='testGetAPI()'>Probar API get.php</button>
                        <button class='btn btn-secondary ms-2' onclick='clearResults()'>Limpiar</button>
                        
                        <div id='results' class='mt-4'></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const sessionToken = '<?php echo $sessionToken; ?>';
        const informeId = 13;
        
        async function testGetAPI() {
            const resultsDiv = document.getElementById('results');
            resultsDiv.innerHTML = '<div class="alert alert-info">Probando API...</div>';
            
            try {
                // Simular la llamada que hace informes-manager.js
                const response = await fetch(`../api/informes/get.php?informe_id=${informeId}`, {
                    method: 'GET',
                    headers: {
                        'Authorization': `Bearer ${sessionToken}`,
                        'Content-Type': 'application/json'
                    }
                });
                
                const data = await response.json();
                
                let html = '<div class="alert alert-success">API Response:</div>';
                html += '<h4>Status: ' + response.status + '</h4>';
                html += '<h4>Response Data:</h4>';
                html += '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
                
                if (data.success && data.data) {
                    const informe = data.data;
                    html += '<h4>Campos específicos del modal:</h4>';
                    html += '<ul>';
                    html += '<li><strong>patient_name:</strong> ' + (informe.patient_name || 'NULL') + '</li>';
                    html += '<li><strong>patient_id:</strong> ' + (informe.patient_id || 'NULL') + '</li>';
                    html += '<li><strong>modality:</strong> ' + (informe.modality || 'NULL') + '</li>';
                    html += '<li><strong>estado:</strong> ' + (informe.estado || 'NULL') + '</li>';
                    html += '<li><strong>estado_badge:</strong> ' + (informe.estado_badge || 'NULL') + '</li>';
                    html += '<li><strong>version:</strong> ' + (informe.version || 'NULL') + '</li>';
                    html += '<li><strong>fecha_modificacion_formatted:</strong> ' + (informe.fecha_modificacion_formatted || 'NULL') + '</li>';
                    html += '</ul>';
                    
                    // Simular lo que hace el modal
                    html += '<h4>Simulación del modal:</h4>';
                    html += '<div class="card bg-light">';
                    html += '<div class="card-body">';
                    html += '<h6>Estado y Versión</h6>';
                    html += '<p><strong>Estado:</strong> <span class="badge bg-' + (informe.estado_badge || 'secondary') + '">' + (informe.estado || 'N/A') + '</span></p>';
                    html += '<p><strong>Versión:</strong> ' + (informe.version || 'N/A') + '</p>';
                    html += '<p><strong>Última Modificación:</strong> ' + (informe.fecha_modificacion_formatted || 'N/A') + '</p>';
                    html += '</div>';
                    html += '</div>';
                }
                
                resultsDiv.innerHTML = html;
                
            } catch (error) {
                resultsDiv.innerHTML = '<div class="alert alert-danger">Error: ' + error.message + '</div>';
            }
        }
        
        function clearResults() {
            document.getElementById('results').innerHTML = '';
        }
    </script>
</body>
</html>