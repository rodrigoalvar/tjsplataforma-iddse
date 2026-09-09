<?php
/**
 * API para gestionar plantillas de email
 * Permite crear, editar, eliminar y obtener plantillas
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
    require_once __DIR__ . '/../EmailTemplate.php';
    
    $templatesDir = __DIR__ . '/../templates';
    
    if (!is_dir($templatesDir)) {
        mkdir($templatesDir, 0755, true);
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
                $template = new EmailTemplate($templatesDir);
                $templates = $template->listTemplates();
                
                foreach ($templates as &$tpl) {
                    $content = file_get_contents($tpl['path']);
                    $tpl['content'] = $content !== false ? $content : '';
                }
                
                sendJsonResponse(true, $templates);
            } elseif ($action === 'get' && isset($_GET['name'])) {
                $templateName = basename($_GET['name']);
                $templatePath = $templatesDir . '/' . $templateName . '.html';
                
                if (!file_exists($templatePath)) {
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
            $templatePath = $templatesDir . '/' . $templateName . '.html';
            
            if (preg_match('/[^a-zA-Z0-9_-]/', $templateName)) {
                sendJsonResponse(false, null, 'El nombre de la plantilla solo puede contener letras, números, guiones y guiones bajos');
            }
            
            if (file_exists($templatePath)) {
                sendJsonResponse(false, null, 'Ya existe una plantilla con ese nombre');
            }
            
            if (file_put_contents($templatePath, $input['content']) === false) {
                sendJsonResponse(false, null, 'Error al guardar la plantilla');
            }
            
            chmod($templatePath, 0644);
            
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
            $templatePath = $templatesDir . '/' . $templateName . '.html';
            
            // Verificar permisos de escritura
            if (!is_writable($templatesDir)) {
                error_log("Directorio templates no es escribible: $templatesDir");
                sendJsonResponse(false, null, 'Error: El directorio de plantillas no tiene permisos de escritura');
            }
            
            // Si la plantilla no existe, crearla (comportamiento de actualización)
            if (!file_exists($templatePath)) {
                // Intentar crear el archivo
                if (file_put_contents($templatePath, $input['content']) === false) {
                    error_log("Error al crear plantilla: $templatePath");
                    sendJsonResponse(false, null, 'Error al crear la plantilla. Verifique permisos.');
                }
            } else {
                // Actualizar plantilla existente
                if (file_put_contents($templatePath, $input['content']) === false) {
                    error_log("Error al actualizar plantilla: $templatePath");
                    $error = error_get_last();
                    sendJsonResponse(false, null, 'Error al actualizar la plantilla: ' . ($error['message'] ?? 'Error desconocido'));
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
            
            $templatePath = $templatesDir . '/' . $templateName . '.html';
            
            if (!file_exists($templatePath)) {
                sendJsonResponse(false, null, 'Plantilla no encontrada');
            }
            
            $protectedTemplates = ['verification', 'informe-completado', 'asignacion-estudio'];
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
    error_log('Error en manage-template.php: ' . $e->getMessage());
    sendJsonResponse(false, null, 'Error: ' . $e->getMessage());
}

