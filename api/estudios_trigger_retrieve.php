<?php
/**
 * C-MOVE desde estudios-manager (estudios solo en remoto). Evita duplicar si ya hay job activo.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST requerido'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../modules/pacs-nodes-manager/lib/cloner_order_helpers.php';
require_once __DIR__ . '/../modules/pacs-nodes-manager/lib/retrieve_execute.php';

$pacsNodeConfigLoaded = false;
if (file_exists(__DIR__ . '/../modules/pacs-nodes-manager/PacsNodeConfig.php')) {
    require_once __DIR__ . '/../modules/pacs-nodes-manager/PacsNodeConfig.php';
    $pacsNodeConfigLoaded = true;
}

function estudios_em_has_active_job(PDO $db, $nodeId, $suid) {
    try {
        $j = json_encode($suid, JSON_UNESCAPED_UNICODE);
        $st = $db->prepare("
            SELECT id FROM pacs_node_jobs
            WHERE node_id = ?
              AND status IN ('pending','running')
              AND JSON_CONTAINS(study_instance_uids, CAST(? AS JSON), '\$')
            LIMIT 1
        ");
        $st->execute([(int) $nodeId, $j]);

        return (bool) $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

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
        echo json_encode(['success' => false, 'error' => 'Sin permiso'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'JSON inválido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $nodeId = isset($body['node_id']) ? (int) $body['node_id'] : 0;
    $uids = $body['StudyInstanceUIDs'] ?? [];
    if ($nodeId < 1 || !is_array($uids) || count($uids) === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'node_id y StudyInstanceUIDs requeridos'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $clean = [];
    foreach ($uids as $u) {
        $u = trim((string) $u);
        if ($u !== '') {
            $clean[] = $u;
        }
    }
    if ($clean === []) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'UIDs vacíos'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $db = getDBConnection();
    if (!$db) {
        throw new Exception('BD no disponible');
    }

    pacsClonerSyncOrdersFromJobs($db);

    foreach ($clean as $suid) {
        if (estudios_em_has_active_job($db, $nodeId, $suid)) {
            echo json_encode([
                'success' => true,
                'skipped' => true,
                'message' => 'Ya existe un job pendiente/en curso para este estudio en el nodo.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $runUser = ['id' => (int) ($ud['id'] ?? 0)];
    $res = pacs_nodes_run_retrieve($db, $runUser, [
        'node_id' => $nodeId,
        'StudyInstanceUIDs' => $clean,
    ], $pacsNodeConfigLoaded && class_exists('PacsNodeConfig'));

    if (empty($res['success'])) {
        http_response_code((int) ($res['http_code'] ?? 500));
        echo json_encode([
            'success' => false,
            'error' => $res['error'] ?? 'retrieve falló',
            'detail' => $res['detail'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success' => true,
        'data' => $res['data'] ?? [],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[estudios_trigger_retrieve] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
