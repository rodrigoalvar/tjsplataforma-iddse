<?php
/**
 * Script para probar la eliminación de informes
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
    
    // Obtener informes disponibles para eliminar
    $informesQuery = "SELECT id, titulo, patient_name, estado, fecha_creacion, usuario_id FROM informes WHERE usuario_id = ? ORDER BY fecha_creacion DESC LIMIT 5";
    $informesStmt = $db->prepare($informesQuery);
    $informesStmt->execute([$userData['id']]);
    $informes = $informesStmt->fetchAll();
    
    echo "<h3>Informes disponibles:</h3>";
    if (empty($informes)) {
        echo "<p>No hay informes para este usuario</p>";
    } else {
        echo "<table class='table table-striped'>";
        echo "<thead><tr><th>ID</th><th>Título</th><th>Paciente</th><th>Estado</th><th>Fecha Creación</th><th>¿Puede eliminar?</th></tr></thead>";
        echo "<tbody>";
        
        foreach ($informes as $informe) {
            $fecha_creacion = new DateTime($informe['fecha_creacion']);
            $ahora = new DateTime();
            $diferencia_horas = $ahora->diff($fecha_creacion)->h + ($ahora->diff($fecha_creacion)->days * 24);
            
            $puede_eliminar = (
                $informe['estado'] === 'borrador' || 
                ($diferencia_horas < 24 && $informe['estado'] !== 'finalizado')
            );
            
            $badge_class = $puede_eliminar ? 'success' : 'danger';
            $badge_text = $puede_eliminar ? 'SÍ' : 'NO';
            
            echo "<tr>";
            echo "<td>{$informe['id']}</td>";
            echo "<td>" . htmlspecialchars($informe['titulo'] ?? 'Sin título') . "</td>";
            echo "<td>" . htmlspecialchars($informe['patient_name'] ?? 'N/A') . "</td>";
            echo "<td><span class='badge bg-secondary'>{$informe['estado']}</span></td>";
            echo "<td>{$informe['fecha_creacion']}</td>";
            echo "<td><span class='badge bg-{$badge_class}'>{$badge_text}</span></td>";
            echo "</tr>";
        }
        
        echo "</tbody></table>";
    }
    
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
    <title>Test Delete Report</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
</head>
<body>
    <div class='container mt-5'>
        <div class='row justify-content-center'>
            <div class='col-md-12'>
                <div class='card'>
                    <div class='card-body'>
                        <h1 class='card-title'>Test: Eliminación de Informes</h1>
                        <hr>
                        
                        <div class='mb-3'>
                            <label for='reportIdInput' class='form-label'>ID del Informe a Eliminar:</label>
                            <input type='number' class='form-control' id='reportIdInput' placeholder='Ingrese el ID del informe'>
                        </div>
                        
                        <button class='btn btn-danger' onclick='testDeleteReport()'>Eliminar Informe</button>
                        <button class='btn btn-secondary ms-2' onclick='clearResults()'>Limpiar Resultados</button>
                        
                        <div id='results' class='mt-4'></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const sessionToken = '<?php echo $sessionToken; ?>';
        
        async function testDeleteReport() {
            const reportId = document.getElementById('reportIdInput').value;
            const resultsDiv = document.getElementById('results');
            
            if (!reportId) {
                resultsDiv.innerHTML = '<div class="alert alert-warning">Por favor ingrese un ID de informe</div>';
                return;
            }
            
            resultsDiv.innerHTML = '<div class="alert alert-info">Eliminando informe...</div>';
            
            try {
                // Simular la llamada que hace informes-manager.js
                const response = await fetch('../api/informes/delete.php', {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${sessionToken}`
                    },
                    body: JSON.stringify({ id: parseInt(reportId) })
                });
                
                const data = await response.json();
                
                let html = '<div class="alert alert-' + (response.ok ? 'success' : 'danger') + '">Respuesta de la API:</div>';
                html += '<h4>Status HTTP: ' + response.status + '</h4>';
                html += '<h4>Response Data:</h4>';
                html += '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
                
                if (data.success) {
                    html += '<div class="alert alert-success">✅ Informe eliminado exitosamente</div>';
                    html += '<p><strong>Informe eliminado:</strong> ' + (data.data?.report_title || 'N/A') + '</p>';
                    html += '<p><strong>Paciente:</strong> ' + (data.data?.patient_name || 'N/A') + '</p>';
                    html += '<p><strong>Audios eliminados:</strong> ' + (data.data?.deleted_audios_count || 0) + '</p>';
                } else {
                    html += '<div class="alert alert-danger">❌ Error al eliminar informe</div>';
                    html += '<p><strong>Error:</strong> ' + (data.error || 'Error desconocido') + '</p>';
                    if (data.details) {
                        html += '<p><strong>Detalles:</strong> ' + data.details + '</p>';
                    }
                }
                
                resultsDiv.innerHTML = html;
                
            } catch (error) {
                resultsDiv.innerHTML = '<div class="alert alert-danger">Error de conexión: ' + error.message + '</div>';
            }
        }
        
        function clearResults() {
            document.getElementById('results').innerHTML = '';
            document.getElementById('reportIdInput').value = '';
        }
    </script>
</body>
</html>