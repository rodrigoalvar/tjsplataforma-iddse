<?php
/**
 * Autenticación para Study History Manager
 * Mismo patrón que pacs-nodes-manager/api/_auth.php
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../classes/User.php';

/**
 * @param string $permissionKey study_history_manager
 * @return array Usuario autenticado
 */
function requireStudyHistoryAuth($permissionKey = 'study_history_manager') {
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

    try {
        $userObj = new User();
        $user_data = $userObj->validateSession($token);
    } catch (Exception $e) {
        error_log('[STUDY_HISTORY] Error validando sesión: ' . $e->getMessage());
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

    $level = $user_data['nivel'] ?? $user_data['level'] ?? '';
    if ($level === 'root') {
        return $user_data;
    }

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
        error_log('[STUDY_HISTORY] Error verificando permisos: ' . $e->getMessage());
    }

    return $user_data;
}

function studyHistoryJsonError($message, $code = 400) {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json');
    }
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function studyHistoryJsonSuccess($data) {
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode(array_merge(['success' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}
