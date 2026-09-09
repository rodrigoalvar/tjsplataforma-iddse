<?php
/**
 * Cierra sesiones (una por id o todas las de un usuario).
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../AuditLogger.php';

$actor = auditManagerRequireAuditor();
$db = getDBConnection();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$sessionId = isset($input['session_id']) ? (int) $input['session_id'] : 0;
$userId = isset($input['user_id']) ? (int) $input['user_id'] : 0;
$revokeAll = !empty($input['revoke_all_for_user']);
$reason = isset($input['reason']) ? substr((string) $input['reason'], 0, 500) : '';

function auditSesionesHasFechaCierre(PDO $db): bool {
    try {
        $st = $db->query("SHOW COLUMNS FROM sesiones LIKE 'fecha_cierre'");
        return $st && $st->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

$hasFc = auditSesionesHasFechaCierre($db);

try {
    if ($sessionId > 0) {
        $check = $db->prepare('SELECT id, usuario_id FROM sesiones WHERE id = ? AND activa = 1 AND fecha_expiracion > NOW()');
        $check->execute([$sessionId]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Sesión no encontrada o ya cerrada']);
            exit;
        }
        if ($hasFc) {
            $up = $db->prepare('UPDATE sesiones SET activa = 0, fecha_cierre = NOW() WHERE id = ?');
        } else {
            $up = $db->prepare('UPDATE sesiones SET activa = 0 WHERE id = ?');
        }
        $up->execute([$sessionId]);
        $targetUserId = (int) $row['usuario_id'];
        auditManagerCloseMobileSessionsForUser($db, $targetUserId);
        AuditLogger::log($db, [
            'user_id' => (int) $actor['id'],
            'action_key' => 'auth.session_revoked',
            'description' => 'Cierre forzado de sesión',
            'metadata' => [
                'target_user_id' => $targetUserId,
                'session_id' => $sessionId,
                'reason' => $reason,
            ],
        ]);
        echo json_encode(['success' => true, 'revoked' => 1, 'target_user_id' => $targetUserId], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($revokeAll && $userId > 0) {
        if ($hasFc) {
            $up = $db->prepare('UPDATE sesiones SET activa = 0, fecha_cierre = NOW() WHERE usuario_id = ? AND activa = 1');
        } else {
            $up = $db->prepare('UPDATE sesiones SET activa = 0 WHERE usuario_id = ? AND activa = 1');
        }
        $up->execute([$userId]);
        $n = $up->rowCount();
        auditManagerCloseMobileSessionsForUser($db, $userId);
        AuditLogger::log($db, [
            'user_id' => (int) $actor['id'],
            'action_key' => 'auth.sessions_revoked_all',
            'description' => 'Cierre forzado de todas las sesiones del usuario',
            'metadata' => [
                'target_user_id' => $userId,
                'count' => $n,
                'reason' => $reason,
            ],
        ]);
        echo json_encode(['success' => true, 'revoked' => $n, 'target_user_id' => $userId], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Indique session_id o user_id + revoke_all_for_user']);
} catch (Exception $e) {
    error_log('[audit-manager/revoke] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al revocar sesión']);
}
