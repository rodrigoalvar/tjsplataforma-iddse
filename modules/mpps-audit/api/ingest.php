<?php
/**
 * Ingesta POST de eventos MPPS (stub entrega 2 — webhook Lua / push).
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/_common.php';

mppsAuditRequireAccess();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mppsAuditJsonError('Método no permitido', 405);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '{}', true);
if (!is_array($payload)) {
    mppsAuditJsonError('JSON inválido', 400);
}

try {
    $db = getDBConnection();
    $svc = new MppsAuditService($db);
    $result = $svc->upsertFromPayload($payload);
    mppsAuditJsonSuccess($result, $result['message'] ?? null);
} catch (Throwable $e) {
    mppsAuditJsonError($e->getMessage(), 500);
}
