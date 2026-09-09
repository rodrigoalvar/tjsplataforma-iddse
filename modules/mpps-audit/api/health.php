<?php
/**
 * Salud del módulo MPPS Audit.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/_common.php';

mppsAuditRequireAccess();

try {
    $db = getDBConnection();
    $tables = ['mpps_events', 'mpps_audit_config', 'mpps_poll_state'];
    $status = [];
    foreach ($tables as $t) {
        $status[$t] = MppsAuditService::tableExists($db, $t);
    }
    $svc = new MppsAuditService($db);
    $mppsEndpoint = null;
    try {
        $client = new OrthancClient();
        $ids = $client->getMppsIds();
        $mppsEndpoint = ['ok' => true, 'count' => count($ids)];
    } catch (Throwable $e) {
        $mppsEndpoint = ['ok' => false, 'error' => $e->getMessage()];
    }
    mppsAuditJsonSuccess([
        'module' => 'mpps-audit',
        'version' => MppsAuditService::MODULE_VERSION,
        'phase' => 1,
        'tables' => $status,
        'event_count' => $svc->countEvents(),
        'use_mock_when_empty' => $svc->useMockWhenEmpty(),
        'orthanc_mpps' => $mppsEndpoint,
    ], 'Módulo operativo');
} catch (Throwable $e) {
    mppsAuditJsonError($e->getMessage(), 500);
}
