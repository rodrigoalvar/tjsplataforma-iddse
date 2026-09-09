<?php
/**
 * Utilidades comunes para APIs del módulo Audit Manager.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../classes/User.php';

function auditManagerGetToken(): ?string {
    if (!empty($_COOKIE['session_token'])) {
        return $_COOKIE['session_token'];
    }
    if (!empty($_SERVER['HTTP_AUTHORIZATION']) && strpos($_SERVER['HTTP_AUTHORIZATION'], 'Bearer ') === 0) {
        return substr($_SERVER['HTTP_AUTHORIZATION'], 7);
    }
    return null;
}

/**
 * Usuario autenticado o termina con 401 JSON.
 */
function auditManagerRequireLogin(): array {
    $token = auditManagerGetToken();
    if (!$token) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'No autenticado'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $userObj = new User();
    $user = $userObj->validateSession($token);
    if (!$user) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Sesión inválida'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $user['session_token'] = $token;
    return $user;
}

function auditManagerJsonPermissions(array $user): array {
    $p = $user['permisos'] ?? [];
    if (is_string($p)) {
        $decoded = json_decode($p, true);
        return is_array($decoded) ? $decoded : [];
    }
    return is_array($p) ? $p : [];
}

function auditManagerHasAuditPermission(array $user): bool {
    $level = $user['nivel'] ?? '';
    if ($level === 'root') {
        return true;
    }
    $perms = auditManagerJsonPermissions($user);
    if (in_array('all', $perms, true) || in_array('audit_manager', $perms, true)) {
        return true;
    }
    try {
        $db = getDBConnection();
        $stmt = $db->prepare(
            "SELECT 1 FROM user_permissions up
             INNER JOIN system_permissions sp ON up.permission_id = sp.id
             WHERE up.user_id = ? AND sp.permission_key = 'audit_manager' LIMIT 1"
        );
        $stmt->execute([(int) $user['id']]);
        return (bool) $stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Solo usuarios con permiso audit_manager (o root / all).
 */
function auditManagerRequireAuditor(): array {
    $user = auditManagerRequireLogin();
    if (!auditManagerHasAuditPermission($user)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Sin permiso de auditoría'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $user;
}

function auditManagerCloseMobileSessionsForUser(PDO $pdo, int $userId): void {
    try {
        $sql = "UPDATE mobile_sessions
                SET status = 'expired',
                    expires_at = NOW(),
                    last_activity = DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                WHERE created_by = ? AND status IN ('active', 'connected')";
        $pdo->prepare($sql)->execute([$userId]);
    } catch (Exception $e) {
        error_log('[audit-manager] mobile_sessions: ' . $e->getMessage());
    }
}
