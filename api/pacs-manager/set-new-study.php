<?php
/**
 * Asigna manualmente new_orthanc_study_id a un log de auditoría cuyo
 * post-proceso falló por no poder resolver el estudio nuevo desde el job.
 * Valida que el UUID exista en Orthanc y que el PatientID coincida.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../OrthancPacsSender.php';
require_once __DIR__ . '/pacs_manager_bootstrap.php';
require_once __DIR__ . '/lib/PacsStudyModifyLog.php';

$boot = pacsManagerBootstrap();
if ($boot === null) {
    exit;
}

$raw   = file_get_contents('php://input');
$input = json_decode($raw, true) ?: [];
$migrationLogId  = (int) ($input['migration_log_id'] ?? 0);
$newOrthancId    = trim((string) ($input['new_orthanc_study_id'] ?? ''));

if ($migrationLogId <= 0 || $newOrthancId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'migration_log_id y new_orthanc_study_id son requeridos'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        throw new Exception('Sin conexión a base de datos');
    }

    $log = PacsStudyModifyLog::getById($db, $migrationLogId);
    if (!$log) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Registro de auditoría no encontrado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!in_array($log['status'] ?? '', ['failed', 'partial', 'orthanc_success', 'orthanc_running', 'pending_resolution'], true)) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error' => 'Solo se puede asignar estudio nuevo en logs con estado failed, partial u orthanc_success',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pacs = new OrthancPacsSender();

    // Verificar que el UUID existe en Orthanc
    $studyResp = $pacs->makeRequestWithRetry('/studies/' . rawurlencode($newOrthancId), 'GET', null, 10);
    if (!$studyResp['success']) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'El UUID ingresado no existe en Orthanc o no fue posible verificarlo: ' . ($studyResp['error'] ?? ''),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $studyData    = $studyResp['data'];
    $orthancPid   = trim((string) ($studyData['PatientMainDicomTags']['PatientID'] ?? ''));
    $expectedPid  = trim((string) ($log['patient_id_pacs'] ?? ''));

    if ($expectedPid !== '' && $orthancPid !== $expectedPid) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'error' => "El PatientID del estudio en Orthanc ($orthancPid) no coincide con el del log ($expectedPid). Verifique el UUID.",
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $newSuid = trim((string) ($studyData['MainDicomTags']['StudyInstanceUID'] ?? ''));

    PacsStudyModifyLog::update($db, $migrationLogId, [
        'new_orthanc_study_id'    => $newOrthancId,
        'new_study_instance_uid'  => $newSuid ?: null,
        'status'                  => 'partial',
        'error_message'           => null,
    ]);

    $patientName  = trim((string) ($studyData['PatientMainDicomTags']['PatientName'] ?? ''));
    $studyDesc    = trim((string) ($studyData['MainDicomTags']['StudyDescription'] ?? ''));
    $studyDate    = trim((string) ($studyData['MainDicomTags']['StudyDate'] ?? ''));

    error_log('[PACS_MANAGER][SET_NEW_STUDY] log=' . $migrationLogId
        . ' new_orthanc=' . $newOrthancId
        . ' patient=' . $orthancPid . '/' . $patientName
        . ' study=' . $studyDesc . ' ' . $studyDate);

    echo json_encode([
        'success'               => true,
        'migration_log_id'      => $migrationLogId,
        'new_orthanc_study_id'  => $newOrthancId,
        'new_study_instance_uid'=> $newSuid,
        'patient_id_orthanc'    => $orthancPid,
        'patient_name_orthanc'  => $patientName,
        'study_description'     => $studyDesc,
        'study_date'            => $studyDate,
        'note'                  => 'UUID asignado. Ahora puede ejecutar la reconciliación completa.',
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
