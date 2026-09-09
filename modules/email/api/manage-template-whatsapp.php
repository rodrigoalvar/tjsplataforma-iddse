<?php
/**
 * API para gestionar plantillas de WhatsApp
 * Permite crear, editar, eliminar y obtener plantillas de WhatsApp
 */

// Headers JSON
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Función para enviar respuesta JSON
function sendJsonResponse($success, $data = null, $error = null) {
    $response = ['success' => $success];
    
    if ($success && $data !== null) {
        $response['data'] = $data;
    }
    
    if (!$success && $error !== null) {
        $response['error'] = $error;
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
}

// Verificar autenticación básica
session_start();
if (!isset($_SESSION['user_id']) && !isset($_COOKIE['session_token'])) {
    $headers = getallheaders();
    $token = null;
    if (isset($headers['Authorization'])) {
        $authHeader = $headers['Authorization'];
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $token = $matches[1];
        }
    }
    
    if (!$token && !isset($_COOKIE['session_token'])) {
        sendJsonResponse(false, null, 'No autorizado');
    }
}

try {
    require_once __DIR__ . '/../../whatsapp/WhatsAppTemplate.php';
    
    $templatesDir = __DIR__ . '/../../whatsapp/templates';
    
    if (!is_dir($templatesDir)) {
        if (!mkdir($templatesDir, 0775, true)) {
            error_log("Error al crear directorio de plantillas WhatsApp: $templatesDir");
            sendJsonResponse(false, null, 'Error al crear el directorio de plantillas. Verifique permisos.');
        }
    }
    
    // Asegurar que el directorio sea escribible
    if (!is_writable($templatesDir)) {
        // Intentar cambiar permisos
        @chmod($templatesDir, 0775);
        if (!is_writable($templatesDir)) {
            error_log("Directorio de plantillas WhatsApp no es escribible: $templatesDir");
            $perms = substr(sprintf('%o', fileperms($templatesDir)), -4);
            sendJsonResponse(false, null, "Error: El directorio de plantillas no tiene permisos de escritura (permisos actuales: $perms). Ejecute: chmod 775 $templatesDir");
        }
    }
    
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    
    $input = [];
    if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
        $rawInput = file_get_contents('php://input');
        if (!empty($rawInput)) {
            $input = json_decode($rawInput, true) ?: [];
        }
        if (empty($input) && !empty($_POST)) {
            $input = $_POST;
        }
    }
    
    switch ($method) {
        case 'GET':
            if ($action === 'list') {
                $template = new WhatsAppTemplate($templatesDir);
                $templates = $template->listTemplates();
                
                foreach ($templates as &$tpl) {
                    $content = file_get_contents($tpl['path']);
                    $tpl['content'] = $content !== false ? $content : '';
                }
                
                sendJsonResponse(true, $templates);
            } elseif ($action === 'get' && isset($_GET['name'])) {
                $templateName = basename($_GET['name']);
                // Buscar con diferentes extensiones
                $extensions = ['.txt', '.text', '.html', '.htm'];
                $templatePath = null;
                
                foreach ($extensions as $ext) {
                    $path = $templatesDir . '/' . $templateName . $ext;
                    if (file_exists($path)) {
                        $templatePath = $path;
                        break;
                    }
                }
                
                if (!$templatePath) {
                    sendJsonResponse(false, null, 'Plantilla no encontrada');
                }
                
                $content = file_get_contents($templatePath);
                if ($content === false) {
                    sendJsonResponse(false, null, 'Error al leer la plantilla');
                }
                
                sendJsonResponse(true, [
                    'name' => $templateName,
                    'content' => $content,
                    'size' => filesize($templatePath),
                    'modified' => filemtime($templatePath)
                ]);
            } else {
                sendJsonResponse(false, null, 'Acción no válida');
            }
            break;
            
        case 'POST':
            if (empty($input['name']) || empty($input['content'])) {
                sendJsonResponse(false, null, 'Nombre y contenido son requeridos');
            }
            
            $templateName = basename($input['name']);
            $templatePath = $templatesDir . '/' . $templateName . '.txt';
            
            if (preg_match('/[^a-zA-Z0-9_-]/', $templateName)) {
                sendJsonResponse(false, null, 'El nombre de la plantilla solo puede contener letras, números, guiones y guiones bajos');
            }
            
            // Verificar permisos de escritura del directorio
            if (!is_writable($templatesDir)) {
                error_log("Directorio templates no es escribible: $templatesDir");
                $owner = fileowner($templatesDir);
                $perms = substr(sprintf('%o', fileperms($templatesDir)), -4);
                sendJsonResponse(false, null, "Error: El directorio de plantillas no tiene permisos de escritura (permisos: $perms)");
            }
            
            if (file_exists($templatePath)) {
                sendJsonResponse(false, null, 'Ya existe una plantilla con ese nombre');
            }
            
            // Intentar crear el directorio si no existe
            if (!is_dir($templatesDir)) {
                if (!mkdir($templatesDir, 0755, true)) {
                    error_log("Error al crear directorio: $templatesDir");
                    sendJsonResponse(false, null, 'Error al crear el directorio de plantillas. Verifique permisos.');
                }
            }
            
            $result = @file_put_contents($templatePath, $input['content']);
            if ($result === false) {
                $error = error_get_last();
                $errorMsg = $error ? $error['message'] : 'Error desconocido';
                $currentUser = function_exists('posix_geteuid') ? @posix_getpwuid(posix_geteuid())['name'] : get_current_user();
                $dirWritable = is_writable($templatesDir) ? 'Sí' : 'No';
                $dirPerms = substr(sprintf('%o', fileperms($templatesDir)), -4);
                error_log("Error al guardar plantilla WhatsApp: $templatePath");
                error_log("  - Error: $errorMsg");
                error_log("  - Usuario PHP: $currentUser");
                error_log("  - Directorio escribible: $dirWritable");
                error_log("  - Permisos directorio: $dirPerms");
                sendJsonResponse(false, null, "Error al guardar la plantilla: $errorMsg. Verifique permisos del directorio (actualmente: $dirPerms, escribible: $dirWritable)");
            }
            
            @chmod($templatePath, 0644);
            
            sendJsonResponse(true, [
                'name' => $templateName,
                'message' => 'Plantilla creada exitosamente'
            ]);
            break;
            
        case 'PUT':
            if (empty($input['name']) || empty($input['content'])) {
                sendJsonResponse(false, null, 'Nombre y contenido son requeridos');
            }
            
            $templateName = basename($input['name']);
            $templatePath = $templatesDir . '/' . $templateName . '.txt';
            
            // Verificar permisos de escritura
            if (!is_writable($templatesDir)) {
                error_log("Directorio templates no es escribible: $templatesDir");
                $perms = substr(sprintf('%o', fileperms($templatesDir)), -4);
                sendJsonResponse(false, null, "Error: El directorio de plantillas no tiene permisos de escritura (permisos: $perms)");
            }
            
            // Intentar crear el directorio si no existe
            if (!is_dir($templatesDir)) {
                if (!mkdir($templatesDir, 0755, true)) {
                    error_log("Error al crear directorio: $templatesDir");
                    sendJsonResponse(false, null, 'Error al crear el directorio de plantillas. Verifique permisos.');
                }
            }
            
            // Si la plantilla no existe, crearla (comportamiento de actualización)
            if (!file_exists($templatePath)) {
                // Intentar crear el archivo
                $result = @file_put_contents($templatePath, $input['content']);
                if ($result === false) {
                    $error = error_get_last();
                    $errorMsg = $error ? $error['message'] : 'Error desconocido';
                    $currentUser = function_exists('posix_geteuid') ? @posix_getpwuid(posix_geteuid())['name'] : get_current_user();
                    $dirWritable = is_writable($templatesDir) ? 'Sí' : 'No';
                    $dirPerms = substr(sprintf('%o', fileperms($templatesDir)), -4);
                    error_log("Error al crear plantilla WhatsApp: $templatePath");
                    error_log("  - Error: $errorMsg");
                    error_log("  - Usuario PHP: $currentUser");
                    error_log("  - Directorio escribible: $dirWritable");
                    error_log("  - Permisos directorio: $dirPerms");
                    sendJsonResponse(false, null, "Error al crear la plantilla: $errorMsg. Verifique permisos del directorio (actualmente: $dirPerms, escribible: $dirWritable)");
                }
            } else {
                // Actualizar plantilla existente
                $result = @file_put_contents($templatePath, $input['content']);
                if ($result === false) {
                    $error = error_get_last();
                    $errorMsg = $error ? $error['message'] : 'Error desconocido';
                    $currentUser = function_exists('posix_geteuid') ? @posix_getpwuid(posix_geteuid())['name'] : get_current_user();
                    $dirWritable = is_writable($templatesDir) ? 'Sí' : 'No';
                    $dirPerms = substr(sprintf('%o', fileperms($templatesDir)), -4);
                    error_log("Error al actualizar plantilla WhatsApp: $templatePath");
                    error_log("  - Error: $errorMsg");
                    error_log("  - Usuario PHP: $currentUser");
                    error_log("  - Directorio escribible: $dirWritable");
                    error_log("  - Permisos directorio: $dirPerms");
                    sendJsonResponse(false, null, "Error al actualizar la plantilla: $errorMsg. Verifique permisos del directorio (actualmente: $dirPerms, escribible: $dirWritable)");
                }
            }
            
            // Asegurar permisos correctos
            @chmod($templatePath, 0644);
            
            sendJsonResponse(true, [
                'name' => $templateName,
                'message' => 'Plantilla actualizada exitosamente'
            ]);
            break;
            
        case 'DELETE':
            $templateName = isset($input['name']) ? basename($input['name']) : (isset($_GET['name']) ? basename($_GET['name']) : '');
            
            if (empty($templateName)) {
                sendJsonResponse(false, null, 'Nombre de plantilla requerido');
            }
            
            // Buscar con diferentes extensiones
            $extensions = ['.txt', '.text', '.html', '.htm'];
            $templatePath = null;
            
            foreach ($extensions as $ext) {
                $path = $templatesDir . '/' . $templateName . $ext;
                if (file_exists($path)) {
                    $templatePath = $path;
                    break;
                }
            }
            
            if (!$templatePath) {
                sendJsonResponse(false, null, 'Plantilla no encontrada');
            }
            
            $protectedTemplates = []; // Plantillas protegidas de WhatsApp (si las hay)
            if (in_array($templateName, $protectedTemplates)) {
                sendJsonResponse(false, null, 'No se puede eliminar una plantilla del sistema');
            }
            
            if (!unlink($templatePath)) {
                sendJsonResponse(false, null, 'Error al eliminar la plantilla');
            }
            
            sendJsonResponse(true, [
                'name' => $templateName,
                'message' => 'Plantilla eliminada exitosamente'
            ]);
            break;
            
        default:
            sendJsonResponse(false, null, 'Método no permitido');
    }
    
} catch (Exception $e) {
    $errorMsg = $e->getMessage();
    $trace = $e->getTraceAsString();
    error_log('Error en manage-template-whatsapp.php: ' . $errorMsg);
    error_log('Trace: ' . $trace);
    sendJsonResponse(false, null, 'Error: ' . $errorMsg);
} catch (Error $e) {
    $errorMsg = $e->getMessage();
    $trace = $e->getTraceAsString();
    error_log('Error fatal en manage-template-whatsapp.php: ' . $errorMsg);
    error_log('Trace: ' . $trace);
    sendJsonResponse(false, null, 'Error fatal: ' . $errorMsg);
}

