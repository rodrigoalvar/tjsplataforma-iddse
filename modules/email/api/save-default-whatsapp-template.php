<?php
/**
 * API Endpoint: Guardar Plantilla por Defecto de WhatsApp del Usuario
 * 
 * Endpoint para guardar la plantilla de WhatsApp por defecto del usuario.
 * 
 * Método: POST
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
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    ob_end_clean();
    exit();
}

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../../config/database.php';

try {
    $user = validateEmailApiAuth();
    if (!$user || !isset($user['id'])) {
        sendJsonResponse(false, null, 'No autenticado o sesión inválida', 401);
    }
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(false, null, 'Método no permitido. Use POST.', 405);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST;
    }
    
    if (!isset($input['template'])) {
        sendJsonResponse(false, null, 'Campo requerido: template (nombre de la plantilla o cadena vacía)', 400);
    }
    
    $userId = (int)$user['id'];
    $template = !empty($input['template']) ? trim($input['template']) : null;
    $tipo = isset($input['tipo']) && in_array($input['tipo'], ['paciente', 'medico']) ? $input['tipo'] : 'paciente';
    
    if (!function_exists('getDBConnection')) {
        throw new Exception('Función getDBConnection no disponible');
    }
    
    $pdo = getDBConnection();
    if (!$pdo) {
        throw new Exception('Error de conexión a la base de datos');
    }
    
    // Verificar si la tabla existe, si no, crearla
    try {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'user_email_preferences'");
        $tableExists = $tableCheck && $tableCheck->rowCount() > 0;
    } catch (PDOException $e) {
        error_log('Error verificando tabla: ' . $e->getMessage());
        $tableExists = false;
    }
    
    // Determinar el nombre de la columna según el tipo
    $columnName = $tipo === 'medico' ? 'default_whatsapp_template_medico' : 'default_whatsapp_template_paciente';
    
    if (!$tableExists) {
        // Crear la tabla con columnas separadas para paciente y médico
        $createTableSQL = "CREATE TABLE IF NOT EXISTS `user_email_preferences` (
          `id` int NOT NULL AUTO_INCREMENT,
          `usuario_id` int NOT NULL,
          `default_email_template_paciente` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `default_email_template_medico` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `default_whatsapp_template_paciente` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `default_whatsapp_template_medico` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `fecha_creacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          `fecha_actualizacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `usuario_id` (`usuario_id`),
          CONSTRAINT `fk_user_email_preferences_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        try {
            $pdo->exec($createTableSQL);
        } catch (PDOException $e) {
            error_log('Error creando tabla: ' . $e->getMessage());
            // Continuar de todas formas, puede que ya exista
        }
    } else {
        // Verificar y agregar columnas si no existen
        $columnsToAdd = [
            'default_email_template_paciente',
            'default_email_template_medico',
            'default_whatsapp_template_paciente',
            'default_whatsapp_template_medico'
        ];
        
        foreach ($columnsToAdd as $colName) {
            try {
                $columnCheck = $pdo->query("SHOW COLUMNS FROM user_email_preferences LIKE '$colName'");
                $columnExists = $columnCheck && $columnCheck->rowCount() > 0;
                
                if (!$columnExists) {
                    $pdo->exec("ALTER TABLE user_email_preferences ADD COLUMN $colName varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL");
                    error_log("Columna $colName agregada a user_email_preferences");
                }
            } catch (PDOException $e) {
                error_log("Error verificando/agregando columna $colName: " . $e->getMessage());
                // Continuar de todas formas
            }
        }
    }
    
    // Verificar si ya existe una preferencia para este usuario
    $query = "SELECT id FROM user_email_preferences WHERE usuario_id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$userId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        // Actualizar preferencia existente
        $query = "UPDATE user_email_preferences SET $columnName = ?, fecha_actualizacion = NOW() WHERE usuario_id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$template, $userId]);
    } else {
        // Insertar nueva preferencia
        $query = "INSERT INTO user_email_preferences (usuario_id, $columnName) VALUES (?, ?)";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$userId, $template]);
    }
    
    sendJsonResponse(true, [
        'message' => 'Plantilla por defecto de WhatsApp guardada correctamente',
        'template' => $template,
        'user_id' => $userId
    ]);
    
} catch (PDOException $e) {
    error_log('Error PDO en save-default-whatsapp-template.php: ' . $e->getMessage());
    error_log('SQL State: ' . $e->getCode());
    sendJsonResponse(false, null, 'Error de base de datos: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    error_log('Error en save-default-whatsapp-template.php: ' . $e->getMessage());
    error_log('File: ' . $e->getFile() . ' Line: ' . $e->getLine());
    sendJsonResponse(false, null, 'Error interno del servidor: ' . $e->getMessage(), 500);
} catch (Error $e) {
    error_log('Fatal Error en save-default-whatsapp-template.php: ' . $e->getMessage());
    error_log('File: ' . $e->getFile() . ' Line: ' . $e->getLine());
    sendJsonResponse(false, null, 'Error fatal del servidor: ' . $e->getMessage(), 500);
}


