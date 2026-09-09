<?php
/**
 * Vista previa de pares PDF↔TXT (dry-run) y confirmación manual de un par.
 *
 * GET  → Propone pares sin escribir nada en la BD.
 *         Params: limite int (default 300)
 *
 * POST → Confirma un par específico y lo ingresa en informes_recibidos.
 *         Body: { "pdf_id": int, "txt_id": int }
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

    // ── GET: dry-run preview ──────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        set_time_limit(120);
        $limite  = min(500, max(10, (int)($_GET['limite'] ?? 300)));
        $preview = ic_preview_pending_pairs($db, $limite);
        echo json_encode([
            'success'      => true,
            'pares'        => $preview['pares'],
            'sin_par_pdf'  => $preview['sin_par_pdf'],
            'sin_par_txt'  => $preview['sin_par_txt'],
            'total_pares'  => count($preview['pares']),
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // ── POST: confirmar par específico ────────────────────────────────────────
    $body   = json_decode(file_get_contents('php://input'), true) ?: [];
    $pdfId  = isset($body['pdf_id']) ? (int)$body['pdf_id'] : 0;
    $txtId  = isset($body['txt_id']) ? (int)$body['txt_id'] : 0;

    if ($pdfId <= 0 || $txtId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Se requieren pdf_id y txt_id']);
        exit();
    }

    set_time_limit(60);
    $result = ic_force_ingest_pair($db, $pdfId, $txtId);

    echo json_encode([
        'success'            => true,
        'duplicado'          => $result['duplicado'],
        'informe_recibido_id'=> $result['informe_recibido_id'],
        'message'            => $result['duplicado']
            ? "Par omitido: ACCNO ya existe en informes_recibidos (IR #{$result['informe_recibido_id']})"
            : "Par confirmado e ingresado como IR #{$result['informe_recibido_id']}",
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[IC_PREVIEW] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
