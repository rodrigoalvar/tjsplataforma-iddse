<?php
/**
 * Script para verificar la sesión y simular la llamada a get.php
 */

require_once 'config/database.php';
require_once 'classes/User.php';

try {
    echo "<h3>Debug de Sesión y API</h3>";
    
    // Verificar cookies
    echo "<h4>Cookies disponibles:</h4>";
    echo "<pre>" . json_encode($_COOKIE, JSON_PRETTY_PRINT) . "</pre>";
    
    // Verificar si hay token en cookie
    $sessionToken = $_COOKIE['session_token'] ?? null;
    echo "<h4>Token de sesión:</h4>";
    echo "<p>" . ($sessionToken ? substr($sessionToken, 0, 20) . '...' : 'No encontrado') . "</p>";
    
    if ($sessionToken) {
        // Validar token
        $user = new User();
        $userData = $user->validateSession($sessionToken);
        
        echo "<h4>Validación de token:</h4>";
        if ($userData) {
            echo "<div class='alert alert-success'>Token válido para usuario: {$userData['nombre']} (ID: {$userData['id']})</div>";
            
            // Simular llamada a get.php
            echo "<h4>Simulando llamada a get.php:</h4>";
            
            $db = getDBConnection();
            $informeId = 13;
            
            $query = "SELECT i.*, u.nombre as usuario_nombre, u.email as usuario_email
                      FROM informes i
                      LEFT JOIN usuarios u ON i.usuario_id = u.id
                      WHERE i.id = ? AND i.usuario_id = ?";
            
            $stmt = $db->prepare($query);
            $stmt->execute([$informeId, $userData['id']]);
            $informe = $stmt->fetch();
            
            if ($informe) {
                // Formatear como get.php
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
                
                echo "<div class='alert alert-success'>Informe encontrado y accesible</div>";
                echo "<h5>Respuesta simulada de get.php:</h5>";
                
                $response = [
                    'success' => true,
                    'data' => $informe
                ];
                
                echo "<pre>" . json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
                
            } else {
                echo "<div class='alert alert-warning'>Informe no encontrado o no accesible para este usuario</div>";
                
                // Verificar si existe pero pertenece a otro usuario
                $checkQuery = "SELECT usuario_id FROM informes WHERE id = ?";
                $checkStmt = $db->prepare($checkQuery);
                $checkStmt->execute([$informeId]);
                $checkResult = $checkStmt->fetch();
                
                if ($checkResult) {
                    echo "<p>El informe existe pero pertenece al usuario ID: {$checkResult['usuario_id']}</p>";
                } else {
                    echo "<p>El informe no existe en la base de datos</p>";
                }
            }
            
        } else {
            echo "<div class='alert alert-danger'>Token inválido o expirado</div>";
        }
    } else {
        echo "<div class='alert alert-warning'>No hay token de sesión. El usuario debe iniciar sesión.</div>";
        echo "<p><a href='login.html' class='btn btn-primary'>Ir a Login</a></p>";
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
    <title>Debug Sesión Modal</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
</head>
<body>
    <div class='container mt-5'>
        <div class='row justify-content-center'>
            <div class='col-md-10'>
                <div class='card'>
                    <div class='card-body'>
                        <h1 class='card-title'>Debug: Sesión y Acceso a Informes</h1>
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