<?php
/**
 * Estadísticas resumen MPPS (misma fuente que list: Orthanc por defecto).
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
    $source = strtolower(trim((string) ($_GET['source'] ?? 'orthanc')));
    if ($source === 'mock') {
        $list = ['rows' => MppsAuditService::getMockEvents(), 'total' => 4, 'is_mock' => true, 'source' => 'mock'];
    } elseif ($source === 'db') {
        $list = $svc->listEvents(200, 0, null);
        $list['source'] = 'db';
    } else {
        $list = $svc->listFromOrthanc(80, 0, null);
    }
    mppsAuditJsonSuccess(['stats' => $svc->getStatsFromList($list)]);
} catch (Throwable $e) {
    mppsAuditJsonError($e->getMessage(), 500);
}
