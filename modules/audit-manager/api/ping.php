<?php
/**
 * Ping liviano para medir RTT desde el cliente. Cualquier sesión válida.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

$t0 = microtime(true);
require_once __DIR__ . '/_common.php';
auditManagerRequireLogin();
$serverMs = (int) round((microtime(true) - $t0) * 1000);

echo json_encode([
    'success' => true,
    'server_time' => gmdate('c'),
    'server_processing_ms' => $serverMs,
], JSON_UNESCAPED_UNICODE);
