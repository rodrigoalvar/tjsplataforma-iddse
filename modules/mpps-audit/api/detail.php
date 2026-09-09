<?php
/**
 * Detalle de un evento MPPS por id o orthanc_mpps_id.
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
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $orthancId = isset($_GET['orthanc_mpps_id']) ? trim((string) $_GET['orthanc_mpps_id']) : '';
    $source = strtolower(trim((string) ($_GET['source'] ?? '')));

    if ($orthancId !== '' && str_starts_with($orthancId, 'mock-')) {
        foreach (MppsAuditService::getMockEvents() as $row) {
            if (($row['orthanc_mpps_id'] ?? '') === $orthancId) {
                mppsAuditJsonSuccess(['row' => $row, 'is_mock' => true, 'source' => 'mock']);
            }
        }
        mppsAuditJsonError('Evento mock no encontrado', 404);
    }

    if ($source === 'orthanc' || ($orthancId !== '' && $id <= 0 && $source !== 'db')) {
        if ($orthancId === '') {
            mppsAuditJsonError('orthanc_mpps_id requerido', 400);
        }
        $client = new OrthancClient();
        $raw = $client->getMppsById($orthancId);
        $row = $svc->mapOrthancMppsToRow($orthancId, $raw);
        $row['is_mock'] = false;
        $row['source'] = 'orthanc';
        mppsAuditJsonSuccess(['row' => $row, 'raw' => $raw, 'is_mock' => false, 'source' => 'orthanc']);
    }

    if (!MppsAuditService::tableExists($db, 'mpps_events')) {
        mppsAuditJsonError('Tabla mpps_events no instalada', 503);
    }

    if ($id > 0) {
        $stmt = $db->prepare('SELECT * FROM mpps_events WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
    } elseif ($orthancId !== '') {
        $stmt = $db->prepare('SELECT * FROM mpps_events WHERE orthanc_mpps_id = ? LIMIT 1');
        $stmt->execute([$orthancId]);
    } else {
        mppsAuditJsonError('Parámetro id u orthanc_mpps_id requerido', 400);
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        mppsAuditJsonError('Evento no encontrado', 404);
    }
    $row['pacs_study_found'] = (int) $row['pacs_study_found'];
    $row['is_mock'] = false;
    mppsAuditJsonSuccess(['row' => $row, 'is_mock' => false, 'source' => 'db']);
} catch (Throwable $e) {
    mppsAuditJsonError($e->getMessage(), 500);
}
