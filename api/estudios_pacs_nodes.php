<?php
/**
 * Lista nodos PACS remotos activos (para selector modo mixto en estudios-manager).
 * Requiere sesión + pacs_query + estudios_mixed_search (o root/all).
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/User.php';

try {
    $token = $_COOKIE['session_token'] ?? null;
    if (!$token) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Sesión requerida'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $user = new User();
    $ud = $user->validateSession($token);
    if (!$ud) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Sesión inválida'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $perm = $ud['permisos'] ?? [];
    if (is_string($perm)) {
        $perm = json_decode($perm, true) ?: [];
    }
    $level = $ud['nivel'] ?? '';
    $hasPacs = $level === 'root' || in_array('all', $perm, true) || in_array('pacs_query', $perm, true);
    $hasMixed = $level === 'root' || in_array('all', $perm, true) || in_array('estudios_mixed_search', $perm, true);
    if (!$hasPacs || !$hasMixed) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Sin permiso para búsqueda mixta'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $db = getDBConnection();
    if (!$db) {
        throw new Exception('BD no disponible');
    }

    $st = $db->query("
        SELECT id, name, aet, node_type, host, is_active
        FROM pacs_nodes
        WHERE is_active = 1 AND node_type <> 'local'
        ORDER BY name ASC
    ");
    $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];

    echo json_encode(['success' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[estudios_pacs_nodes] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
