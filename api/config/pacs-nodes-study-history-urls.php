<?php
/**
 * URLs WADO-URI / DICOMweb proxy por nodo (Historial de estudios).
 * Misma política de acceso que api/config/manage.php (root/admin).
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../config/database.php';

function pacsNodesStudyHistoryGetToken() {
    $sessionToken = null;
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        $sessionToken = $headers['Authorization'] ?? null;
    }
    if (!$sessionToken) {
        $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    }
    if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
        $sessionToken = substr($sessionToken, 7);
    }
    if (!$sessionToken) {
        $sessionToken = $_COOKIE['session_token'] ?? null;
    }
    return $sessionToken;
}

function pacsNodesStudyHistoryRequireAdmin() {
    $sessionToken = pacsNodesStudyHistoryGetToken();
    if (!$sessionToken) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Token de autorización requerido']);
        exit;
    }
    $user = new User();
    $userData = $user->validateSession($sessionToken);
    if (!$userData) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sesión inválida']);
        exit;
    }
    if (!in_array(strtolower($userData['nivel'] ?? 'user'), ['root', 'admin'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'No tienes permisos para esta configuración']);
        exit;
    }
    return $userData;
}

function pacsNodesGetColumnSet(PDO $db) {
    $stmt = $db->query('SHOW COLUMNS FROM pacs_nodes');
    if (!$stmt) {
        return [];
    }
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

try {
    pacsNodesStudyHistoryRequireAdmin();
    $db = getDBConnection();
    if (!$db) {
        throw new Exception('No se pudo conectar a la base de datos');
    }

    $check = $db->query("SHOW TABLES LIKE 'pacs_nodes'");
    if (!$check || $check->rowCount() === 0) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'La tabla pacs_nodes no existe. Instale PACS Nodes Manager primero.'
        ]);
        exit;
    }

    $columns = pacsNodesGetColumnSet($db);
    $hasWado = in_array('wado_uri_base', $columns, true);
    $hasProxy = in_array('dicomweb_proxy_base', $columns, true);
    $hasRemoteMode = in_array('remote_open_mode', $columns, true);

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $select = ['id', 'name', 'node_type', 'is_active'];
        if ($hasWado) {
            $select[] = 'wado_uri_base';
        }
        if ($hasProxy) {
            $select[] = 'dicomweb_proxy_base';
        }
        if ($hasRemoteMode) {
            $select[] = 'remote_open_mode';
        }
        $sql = 'SELECT ' . implode(', ', $select) . ' FROM pacs_nodes ORDER BY name ASC';
        $nodes = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode([
            'success' => true,
            'nodes' => $nodes,
            'columns_present' => $hasWado && $hasProxy,
            'has_wado_uri_base' => $hasWado,
            'has_dicomweb_proxy_base' => $hasProxy,
            'has_remote_open_mode' => $hasRemoteMode
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST') {
        if (!$hasWado || !$hasProxy) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Faltan columnas en pacs_nodes. Ejecute modules/study-history-manager/database/migration_add_node_viewer_bases.sql'
            ]);
            exit;
        }

        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['nodes']) || !is_array($data['nodes'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'JSON inválido: se espera { "nodes": [ ... ] }']);
            exit;
        }

        if ($hasRemoteMode) {
            $upd = $db->prepare('UPDATE pacs_nodes SET wado_uri_base = ?, dicomweb_proxy_base = ?, remote_open_mode = ? WHERE id = ?');
        } else {
            $upd = $db->prepare('UPDATE pacs_nodes SET wado_uri_base = ?, dicomweb_proxy_base = ? WHERE id = ?');
        }
        $updated = 0;
        foreach ($data['nodes'] as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }
            $id = (int) $row['id'];
            if ($id <= 0) {
                continue;
            }
            $wado = isset($row['wado_uri_base']) ? substr((string) $row['wado_uri_base'], 0, 500) : '';
            $proxy = isset($row['dicomweb_proxy_base']) ? substr((string) $row['dicomweb_proxy_base'], 0, 500) : '';
            $wado = $wado === '' ? null : $wado;
            $proxy = $proxy === '' ? null : $proxy;
            $rom = 'dicomweb';
            if ($hasRemoteMode && isset($row['remote_open_mode'])) {
                $r = trim((string) $row['remote_open_mode']);
                if (in_array($r, ['dicomweb', 'wado_manifest'], true)) {
                    $rom = $r;
                }
            }
            if ($hasRemoteMode) {
                $upd->execute([$wado, $proxy, $rom, $id]);
            } else {
                $upd->execute([$wado, $proxy, $id]);
            }
            $updated += $upd->rowCount();
        }

        echo json_encode([
            'success' => true,
            'message' => 'Guardado correctamente',
            'rows_touched' => $updated
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
} catch (Exception $e) {
    error_log('[pacs-nodes-study-history-urls] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
