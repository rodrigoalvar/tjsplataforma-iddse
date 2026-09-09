<?php
/**
 * Contador de filas de carpeta que requieren atención (no ingresadas ni omitidas).
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit();
}

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../classes/User.php';
require_once __DIR__ . '/informes_carpeta_lib.php';

if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) === 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}

try {
    $sessionToken = null;
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $sessionToken = $headers['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
        $sessionToken = substr($sessionToken, 7);
    }
    if (!$sessionToken) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Token requerido']);
        exit();
    }

    $user = new User();
    $userData = $user->validateSession($sessionToken);
    if (!$userData) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Sesión inválida']);
        exit();
    }

    $permisos = json_decode($userData['permisos'] ?? '[]', true);
    if (!is_array($permisos)) {
        $permisos = [];
    }
    $allowed = in_array('all', $permisos, true)
        || in_array('informes_carpeta', $permisos, true)
        || in_array('informes_recibidos', $permisos, true);
    if (!$allowed) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Sin permiso']);
        exit();
    }

    $db = getDBConnection();
    ic_ensure_table($db);

    if (!ic_is_activo($db)) {
        echo json_encode(['success' => true, 'attention_total' => 0, 'activo' => false], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $st = $db->query(
        "SELECT COUNT(*) FROM informes_carpeta_archivos
         WHERE estado NOT IN ('ingresado','omitido_duplicado')"
    );
    $n = (int)$st->fetchColumn();

    echo json_encode(['success' => true, 'attention_total' => $n, 'activo' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
