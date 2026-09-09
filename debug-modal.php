<?php
/**
 * Script de debug para probar la API get.php y verificar los datos del informe
 */

require_once 'config/database.php';
require_once 'classes/User.php';

try {
    // Simular una sesión válida (usar el token de una sesión real)
    $db = getDBConnection();
    
    // Obtener el primer usuario activo para simular la sesión
    $userStmt = $db->query("SELECT * FROM usuarios WHERE activo = 1 LIMIT 1");
    $userData = $userStmt->fetch();
    
    if (!$userData) {
        echo "<div class='alert alert-danger'>No hay usuarios activos en la base de datos</div>";
        exit;
    }
    
    echo "<h3>Usuario de prueba:</h3>";
    echo "<p>ID: {$userData['id']}, Nombre: {$userData['nombre']}</p>";
    
    // Obtener el informe de ejemplo (ID 13)
    $informeId = 13;
    
    $query = "SELECT i.*, u.nombre as usuario_nombre, u.email as usuario_email
              FROM informes i
              LEFT JOIN usuarios u ON i.usuario_id = u.id
              WHERE i.id = ?"; // Removemos la restricción de usuario para debug
    
    $stmt = $db->prepare($query);
    $stmt->execute([$informeId]);
    $informe = $stmt->fetch();
    
    if (!$informe) {
        echo "<div class='alert alert-danger'>No se encontró el informe con ID {$informeId}</div>";
        exit;
    }
    
    echo "<h3>Datos del informe (ID {$informeId}):</h3>";
    echo "<pre>" . json_encode($informe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
    
    // Formatear datos como lo hace get.php
    if ($informe['fecha_modificacion']) {
        $informe['fecha_modificacion_formatted'] = date('d/m/Y H:i', strtotime($informe['fecha_modificacion']));
    }
    
    $estadoBadges = [
        'borrador' => 'secondary',
        'finalizado' => 'primary', 
        'revisado' => 'warning',
        'firmado' => 'success'
    ];
    $informe['estado_badge'] = $estadoBadges[$informe['estado']] ?? 'secondary';
    
    echo "<h3>Datos formateados (como los devuelve get.php):</h3>";
    echo "<pre>" . json_encode($informe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
    
    // Verificar campos específicos que usa el modal
    echo "<h3>Campos específicos del modal:</h3>";
    echo "<ul>";
    echo "<li><strong>patient_name:</strong> " . ($informe['patient_name'] ?? 'NULL') . "</li>";
    echo "<li><strong>patient_id:</strong> " . ($informe['patient_id'] ?? 'NULL') . "</li>";
    echo "<li><strong>modality:</strong> " . ($informe['modality'] ?? 'NULL') . "</li>";
    echo "<li><strong>estado:</strong> " . ($informe['estado'] ?? 'NULL') . "</li>";
    echo "<li><strong>estado_badge:</strong> " . ($informe['estado_badge'] ?? 'NULL') . "</li>";
    echo "<li><strong>version:</strong> " . ($informe['version'] ?? 'NULL') . "</li>";
    echo "<li><strong>fecha_modificacion_formatted:</strong> " . ($informe['fecha_modificacion_formatted'] ?? 'NULL') . "</li>";
    echo "</ul>";
    
    // Verificar audios
    $audioQuery = "SELECT * FROM audios_informe WHERE informe_id = ?";
    $audioStmt = $db->prepare($audioQuery);
    $audioStmt->execute([$informeId]);
    $audios = $audioStmt->fetchAll();
    
    echo "<h3>Audios asociados:</h3>";
    if (empty($audios)) {
        echo "<p>No hay audios asociados</p>";
    } else {
        echo "<pre>" . json_encode($audios, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
    }
    
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
}
?>

<!DOCTYPE html>
<html lang='es'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Debug Modal - Datos del Informe</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
</head>
<body>
    <div class='container mt-5'>
        <div class='row justify-content-center'>
            <div class='col-md-10'>
                <div class='card'>
                    <div class='card-body'>
                        <h1 class='card-title'>Debug: Datos del Informe para Modal</h1>
                        <hr>
                        <!-- El contenido PHP se muestra aquí -->
                        <hr>
                        <p><a href='components/informes-manager.html' class='btn btn-primary'>Ir a Gestión de Informes</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>