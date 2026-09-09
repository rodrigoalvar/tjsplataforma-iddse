<?php
/**
 * Reprocesador de informes carpeta.
 * Fase 1: reintenta emparejamiento de filas ya en BD en estado detectado/pendiente_par.
 * Fase 2: escanea archivos en disco no registrados aún y los ingresa (sin usleep de estabilidad).
 *
 * GET  → devuelve estadísticas de lo que hay pendiente (dry-run).
 * POST → ejecuta el reprocesamiento.
 *
 * Params (POST o GET):
 *   limite   int  Máx. archivos a procesar en fase 2 por llamada (default 200, max 500).
 *   fase     int  1 = solo fase 1 (retry BD), 2 = solo fase 2 (scan disco), 0/omitido = ambas.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
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
    $headers      = function_exists('getallheaders') ? getallheaders() : [];
    $sessionToken = $headers['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
        $sessionToken = substr($sessionToken, 7);
    }
    if (!$sessionToken) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Token requerido']);
        exit();
    }

    $user     = new User();
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

    $activo = ic_is_activo($db);

    // GET → solo estadísticas
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stPendiente = $db->query(
            "SELECT COUNT(*) FROM informes_carpeta_archivos WHERE estado IN ('detectado','pendiente_par')"
        );
        $pendienteBd = (int)$stPendiente->fetchColumn();

        $stTotal = $db->query("SELECT COUNT(*) FROM informes_carpeta_archivos");
        $totalBd = (int)$stTotal->fetchColumn();

        $stError = $db->query("SELECT COUNT(*) FROM informes_carpeta_archivos WHERE estado = 'error'");
        $errorBd = (int)$stError->fetchColumn();

        // Contar archivos en disco
        $paths   = ic_get_config_paths($db);
        $enDisco = 0;
        foreach ([$paths['pdf'], $paths['txt']] as $dir) {
            $dir = rtrim(str_replace('\\', '/', $dir), '/');
            if (!is_dir($dir)) {
                continue;
            }
            foreach (['*.pdf', '*.PDF', '*.txt', '*.TXT'] as $pat) {
                $enDisco += count(glob($dir . '/' . $pat, GLOB_NOSORT) ?: []);
            }
        }

        echo json_encode([
            'success'            => true,
            'activo'             => $activo,
            'estadisticas'       => [
                'en_disco'            => $enDisco,
                'en_bd_total'         => $totalBd,
                'en_bd_pendiente_par' => $pendienteBd,
                'en_bd_error'         => $errorBd,
                'nuevos_disco_aprox'  => max(0, $enDisco - $totalBd),
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // POST → reprocesar
    $body   = json_decode(file_get_contents('php://input'), true) ?: [];
    $limite = min(500, max(10, (int)($body['limite'] ?? $_POST['limite'] ?? 200)));
    $fase   = (int)($body['fase'] ?? $_POST['fase'] ?? 0); // 0 = ambas

    set_time_limit(300); // 5 min para lotes grandes

    $resultado = [
        'success' => true,
        'activo'  => $activo,
        'fase1'   => null,
        'fase2'   => null,
    ];

    if ($fase === 0 || $fase === 1) {
        $resultado['fase1'] = ic_retry_pendiente_par($db, $limite);
    }

    if ($fase === 0 || $fase === 2) {
        $resultado['fase2'] = ic_scan_and_ingest_batch($db, $limite);
    }

    echo json_encode($resultado, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[IC_REPROCESAR] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
