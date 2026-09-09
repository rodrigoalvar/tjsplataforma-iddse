<?php
/**
 * API Endpoint: Obtener Plantilla por Defecto del Usuario
 * 
 * Endpoint para obtener la plantilla de email por defecto del usuario.
 * 
 * Método: GET
 * Autenticación: Requerida
 * 
 * @package EmailModule
 * @version 1.0
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    ob_end_clean();
    exit();
}

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../../config/database.php';

$user = validateEmailApiAuth();
if (!$user || !isset($user['id'])) {
    sendJsonResponse(false, null, 'No autenticado o sesión inválida', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJsonResponse(false, null, 'Método no permitido. Use GET.', 405);
}

try {
    
    $userId = (int)$user['id'];
    
    if (!function_exists('getDBConnection')) {
        throw new Exception('Función getDBConnection no disponible');
    }
    
    $pdo = getDBConnection();
    if (!$pdo) {
        throw new Exception('Error de conexión a la base de datos');
    }
    
    // Verificar si la tabla existe
    try {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'user_email_preferences'");
        $tableExists = $tableCheck && $tableCheck->rowCount() > 0;
    } catch (PDOException $e) {
        error_log('Error verificando tabla: ' . $e->getMessage());
        $tableExists = false;
    }
    
    if (!$tableExists) {
        // Si la tabla no existe, retornar sin plantilla por defecto
        sendJsonResponse(true, [
            'template' => null,
            'user_id' => $userId
        ]);
    }
    
    // Obtener el tipo desde el parámetro GET (por defecto 'paciente')
    $tipo = isset($_GET['tipo']) && in_array($_GET['tipo'], ['paciente', 'medico']) ? $_GET['tipo'] : 'paciente';
    $columnName = $tipo === 'medico' ? 'default_email_template_medico' : 'default_email_template_paciente';
    
    // Verificar si la columna existe
    try {
        $columnCheck = $pdo->query("SHOW COLUMNS FROM user_email_preferences LIKE '$columnName'");
        $columnExists = $columnCheck && $columnCheck->rowCount() > 0;
        
        if (!$columnExists) {
            // Si la columna no existe, retornar sin plantilla por defecto
            sendJsonResponse(true, [
                'template' => null,
                'user_id' => $userId
            ]);
        }
    } catch (PDOException $e) {
        error_log('Error verificando columna: ' . $e->getMessage());
        sendJsonResponse(true, [
            'template' => null,
            'user_id' => $userId
        ]);
    }
    
    // Obtener preferencia del usuario
    $query = "SELECT $columnName FROM user_email_preferences WHERE usuario_id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$userId]);
    $preference = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $template = $preference ? ($preference[$columnName] ?: null) : null;
    
    sendJsonResponse(true, [
        'template' => $template,
        'user_id' => $userId
    ]);
    
} catch (PDOException $e) {
    error_log('Error PDO en get-default-template.php: ' . $e->getMessage());
    error_log('SQL State: ' . $e->getCode());
    sendJsonResponse(false, null, 'Error de base de datos: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    error_log('Error en get-default-template.php: ' . $e->getMessage());
    error_log('File: ' . $e->getFile() . ' Line: ' . $e->getLine());
    sendJsonResponse(false, null, 'Error interno del servidor: ' . $e->getMessage(), 500);
} catch (Error $e) {
    error_log('Fatal Error en get-default-template.php: ' . $e->getMessage());
    error_log('File: ' . $e->getFile() . ' Line: ' . $e->getLine());
    sendJsonResponse(false, null, 'Error fatal del servidor: ' . $e->getMessage(), 500);
}
