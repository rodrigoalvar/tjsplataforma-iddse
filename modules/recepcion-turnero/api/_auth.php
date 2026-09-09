<?php
/**
 * Autenticación compartida para endpoints del módulo Recepción y Turnero.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../classes/User.php';

function requireRecepcionTurneroAuth(string $permissionKey = 'turnero'): array
{
    $token = null;

    if (!empty($_COOKIE['session_token'])) {
        $token = $_COOKIE['session_token'];
    } elseif (!empty($_SERVER['HTTP_AUTHORIZATION']) && str_starts_with($_SERVER['HTTP_AUTHORIZATION'], 'Bearer ')) {
        $token = substr($_SERVER['HTTP_AUTHORIZATION'], 7);
    }

    if (!$token) {
        rtAuthJsonError('No autenticado', 'Token de sesión requerido', 401);
    }

    try {
        $userObj = new User();
        $userData = $userObj->validateSession($token);
    } catch (Throwable $e) {
        error_log('[RECEPCION_TURNERO] Error validando sesión: ' . $e->getMessage());
        rtAuthJsonError('Error validando sesión', $e->getMessage(), 500);
    }

    if (!$userData) {
        rtAuthJsonError('Sesión inválida', 'Token de sesión no válido o expirado', 401);
    }

    $level = $userData['nivel'] ?? $userData['level'] ?? '';
    if ($level === 'root') {
        return $userData;
    }

    try {
        $db = getDBConnection();
        $stmt = $db->prepare(
            'SELECT up.id
             FROM user_permissions up
             JOIN system_permissions sp ON up.permission_id = sp.id
             WHERE up.user_id = ? AND sp.permission_key IN (?, \'all\')
             LIMIT 1'
        );
        $stmt->execute([$userData['id'], $permissionKey]);
        if (!$stmt->fetch()) {
            rtAuthJsonError('Sin permisos', "Se requiere el permiso: {$permissionKey}", 403);
        }
    } catch (Throwable $e) {
        error_log('[RECEPCION_TURNERO] Error verificando permisos: ' . $e->getMessage());
    }

    return $userData;
}

function rtAuthJsonError(string $error, string $message, int $code): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'success' => false,
        'error' => $error,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function rtJsonSuccess($data = null, ?string $message = null): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    $response = ['success' => true];
    if ($data !== null) {
        $response['data'] = $data;
    }
    if ($message !== null) {
        $response['message'] = $message;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
