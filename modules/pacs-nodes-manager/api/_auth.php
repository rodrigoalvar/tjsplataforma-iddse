<?php
/**
 * Autenticación compartida para endpoints del módulo PACS NODES MANAGER
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * @package PacsNodesManager
 * @version 1.1.0
 * 
 * NOTA: Usa el mismo patrón que los demás endpoints del sistema:
 *   $user = new User(); $user_data = $user->validateSession($token);
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../classes/User.php';

/**
 * Verifica autenticación y permisos para el módulo
 * 
 * @param string $permissionKey Permiso requerido (default: 'pacs_nodes_manager')
 * @return array Datos del usuario autenticado
 */
function requirePacsNodesAuth($permissionKey = 'pacs_nodes_manager') {
    // Obtener token de cookie o header Authorization
    $token = null;
    
    if (isset($_COOKIE['session_token']) && !empty($_COOKIE['session_token'])) {
        $token = $_COOKIE['session_token'];
    } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        if (strpos($authHeader, 'Bearer ') === 0) {
            $token = substr($authHeader, 7);
        }
    }
    
    if (!$token) {
        if (!headers_sent()) {
            http_response_code(401);
            header('Content-Type: application/json');
        }
        echo json_encode([
            'success' => false,
            'error' => 'No autenticado',
            'message' => 'Token de sesión requerido'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Validar sesión con el patrón estándar del sistema
    try {
        $userObj = new User();
        $user_data = $userObj->validateSession($token);
    } catch (Exception $e) {
        error_log("[PACS_NODES] Error validando sesión: " . $e->getMessage());
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo json_encode([
            'success' => false,
            'error' => 'Error validando sesión',
            'message' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if (!$user_data) {
        if (!headers_sent()) {
            http_response_code(401);
            header('Content-Type: application/json');
        }
        echo json_encode([
            'success' => false,
            'error' => 'Sesión inválida',
            'message' => 'Token de sesión no válido o expirado'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Verificar permisos: root tiene acceso a todo
    $level = $user_data['nivel'] ?? $user_data['level'] ?? '';
    if ($level === 'root') {
        return $user_data;
    }
    
    // Para otros usuarios, verificar permiso específico
    try {
        $db = getDBConnection();
        $stmt = $db->prepare("
            SELECT up.id 
            FROM user_permissions up
            JOIN system_permissions sp ON up.permission_id = sp.id
            WHERE up.user_id = ? AND sp.permission_key = ?
            LIMIT 1
        ");
        $stmt->execute([$user_data['id'], $permissionKey]);
        $hasPerm = $stmt->fetch();
        
        if (!$hasPerm) {
            if (!headers_sent()) {
                http_response_code(403);
                header('Content-Type: application/json');
            }
            echo json_encode([
                'success' => false,
                'error' => 'Sin permisos',
                'message' => "Se requiere el permiso: {$permissionKey}"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } catch (Exception $e) {
        error_log("[PACS_NODES] Error verificando permisos: " . $e->getMessage());
        // Si falla la verificación de permisos, permitir acceso (no bloquear por error de BD)
    }
    
    return $user_data;
}

/**
 * Retorna respuesta JSON estándar de error
 */
function sendErrorResponse($message, $code = 400) {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json');
    }
    echo encodeApiJson([
        'success' => false,
        'error' => $message
    ]);
    exit;
}

/**
 * Retorna respuesta JSON estándar de éxito
 */
function sendSuccessResponse($data = null, $message = null) {
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    
    $response = ['success' => true];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    if ($message !== null) {
        $response['message'] = $message;
    }
    
    echo encodeApiJson($response);
    exit;
}

/**
 * Encodifica JSON de forma robusta para evitar fallos por UTF-8 inválido.
 */
function encodeApiJson($payload) {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $fallback = [
            'success' => false,
            'error' => 'Error serializando respuesta JSON'
        ];
        $json = json_encode($fallback, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return '{"success":false,"error":"Error serializando respuesta JSON"}';
        }
    }
    return $json;
}
