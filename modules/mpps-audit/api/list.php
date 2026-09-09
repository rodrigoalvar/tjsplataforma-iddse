<?php
/**
 * Listado de eventos MPPS. Por defecto consulta Orthanc en vivo (GET /mpps).
 * source=mock | db | orthanc (default)
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/_common.php';

mppsAuditRequireAccess();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    mppsAuditJsonError('Método no permitido', 405);
}

try {
    $db = getDBConnection();
    $svc = new MppsAuditService($db);
    $limit = (int) ($_GET['limit'] ?? 50);
    $offset = (int) ($_GET['offset'] ?? 0);
    $auditStatus = isset($_GET['audit_status']) ? trim((string) $_GET['audit_status']) : null;
    if ($auditStatus === '') {
        $auditStatus = null;
    }
    $source = strtolower(trim((string) ($_GET['source'] ?? 'orthanc')));
    if ($source === 'mock') {
        $rows = MppsAuditService::getMockEvents();
        if ($auditStatus !== null) {
            $rows = array_values(array_filter($rows, static function ($r) use ($auditStatus) {
                return ($r['audit_status'] ?? '') === $auditStatus;
            }));
        }
        $result = ['rows' => $rows, 'total' => count($rows), 'is_mock' => true, 'source' => 'mock'];
    } elseif ($source === 'db') {
        $result = $svc->listEvents($limit, $offset, $auditStatus);
        $result['source'] = 'db';
    } else {
        $result = $svc->listFromOrthanc($limit, $offset, $auditStatus);
    }
    mppsAuditJsonSuccess([
        'rows' => $result['rows'],
        'total' => $result['total'],
        'is_mock' => !empty($result['is_mock']),
        'source' => $result['source'] ?? $source,
        'limit' => $limit,
        'offset' => $offset,
    ]);
} catch (Throwable $e) {
    mppsAuditJsonError($e->getMessage(), 500);
}
