<?php
/**
 * Utilidades comunes para APIs del módulo MPPS Audit.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../classes/User.php';
require_once __DIR__ . '/../MppsAuditService.php';

function mppsAuditGetToken(): ?string
{
    if (!empty($_COOKIE['session_token'])) {
        return $_COOKIE['session_token'];
    }
    if (!empty($_SERVER['HTTP_AUTHORIZATION']) && str_starts_with($_SERVER['HTTP_AUTHORIZATION'], 'Bearer ')) {
        return substr($_SERVER['HTTP_AUTHORIZATION'], 7);
    }
    return null;
}

function mppsAuditJsonError(string $error, int $code = 400): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['success' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

function mppsAuditJsonSuccess(array $data = [], ?string $message = null): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    $out = array_merge(['success' => true], $data);
    if ($message !== null) {
        $out['message'] = $message;
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function mppsAuditRequireLogin(): array
{
    $token = mppsAuditGetToken();
    if (!$token) {
        mppsAuditJsonError('No autenticado', 401);
    }
    $userObj = new User();
    $user = $userObj->validateSession($token);
    if (!$user) {
        mppsAuditJsonError('Sesión inválida', 401);
    }
    $user['session_token'] = $token;
    return $user;
}

function mppsAuditJsonPermissions(array $user): array
{
    $p = $user['permisos'] ?? [];
    if (is_string($p)) {
        $decoded = json_decode($p, true);
        return is_array($decoded) ? $decoded : [];
    }
    return is_array($p) ? $p : [];
}

/**
 * Acceso: root, all, mpps_audit o audit_manager.
 */
function mppsAuditHasPermission(array $user): bool
{
    $level = $user['nivel'] ?? '';
    if ($level === 'root') {
        return true;
    }
    $perms = mppsAuditJsonPermissions($user);
    foreach (['all', 'mpps_audit', 'audit_manager'] as $key) {
        if (in_array($key, $perms, true)) {
            return true;
        }
    }
    try {
        $db = getDBConnection();
        $stmt = $db->prepare(
            "SELECT 1 FROM user_permissions up
             INNER JOIN system_permissions sp ON up.permission_id = sp.id
             WHERE up.user_id = ? AND sp.permission_key IN ('mpps_audit', 'audit_manager', 'all')
             LIMIT 1"
        );
        $stmt->execute([(int) $user['id']]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function mppsAuditRequireAccess(): array
{
    $user = mppsAuditRequireLogin();
    if (!mppsAuditHasPermission($user)) {
        mppsAuditJsonError('Sin permiso de auditoría MPPS', 403);
    }
    return $user;
}
