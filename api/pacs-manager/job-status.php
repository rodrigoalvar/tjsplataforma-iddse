<?php
/**
 * Estado de job Orthanc + reconciliación BD + borrado opcional del original.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/php_errors.log');
ini_set('max_execution_time', 600);
set_time_limit(600);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../OrthancPacsSender.php';
require_once __DIR__ . '/pacs_manager_bootstrap.php';
require_once __DIR__ . '/lib/PacsStudyModifyLog.php';
require_once __DIR__ . '/lib/PacsModifyPostProcessor.php';

$boot = pacsManagerBootstrap();
if ($boot === null) {
    exit;
}

$jobId = $_GET['job_id'] ?? null;
$migrationLogId = isset($_GET['migration_log_id']) ? (int) $_GET['migration_log_id'] : 0;
$deleteOriginalParam = $_GET['delete_original'] ?? null;

if (!$jobId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'job_id es requerido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$deleteOriginalOverride = null;
if ($deleteOriginalParam !== null && $deleteOriginalParam !== '') {
    $deleteOriginalOverride = !in_array($deleteOriginalParam, ['0', 'false', 'no'], true);
}

error_log('[PACS_MANAGER][JOB_STATUS] job_id=' . $jobId . ' migration_log_id=' . $migrationLogId);

try {
    $pacsSender = new OrthancPacsSender();
    $database = new Database();
    $db = $database->getConnection();

    $jobResponse = $pacsSender->makeRequestWithRetry(
        '/jobs/' . urlencode($jobId),
        'GET',
        null,
        10
    );

    if (!$jobResponse['success']) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $jobResponse['error'] ?? 'Error consultando estado del trabajo',
            'job_state' => 'Error',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $jobData = $jobResponse['data'];
    $jobState = $jobData['State'] ?? 'Unknown';
    $progress = $jobData['Progress'] ?? 0;

    if ($jobState === 'Success') {
        if ($db && $migrationLogId > 0) {
            $out = PacsModifyPostProcessor::processAfterOrthancSuccess(
                $db,
                $pacsSender,
                $migrationLogId,
                $jobData,
                $deleteOriginalOverride
            );
            echo json_encode($out, JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Compatibilidad sin migration_log_id (solo Orthanc)
        $newStudyId = PacsModifyPostProcessor::extractNewStudyIdFromJob($jobData);
        $studyToDelete = $_GET['original_study_id'] ?? $_GET['study_id'] ?? null;
        if ($newStudyId && $studyToDelete && $newStudyId !== $studyToDelete && ($deleteOriginalOverride !== false)) {
            $pacsSender->deleteStudy($studyToDelete);
        }
        echo json_encode([
            'success' => true,
            'job_state' => $jobState,
            'new_study_id' => $newStudyId,
            'progress' => 100,
            'note' => 'Procesado sin registro de auditoría (migration_log_id ausente).',
        ], JSON_UNESCAPED_UNICODE);

    } elseif ($jobState === 'Failure') {
        $errorDescription = $jobData['ErrorDescription'] ?? $jobData['Error'] ?? 'Error desconocido';
        if ($db && $migrationLogId > 0) {
            PacsStudyModifyLog::update($db, $migrationLogId, [
                'status' => 'failed',
                'error_message' => $errorDescription,
            ]);
        }
        echo json_encode([
            'success' => false,
            'job_state' => $jobState,
            'error' => $errorDescription,
            'progress' => 0,
        ], JSON_UNESCAPED_UNICODE);

    } else {
        if ($db && $migrationLogId > 0) {
            PacsStudyModifyLog::update($db, $migrationLogId, ['status' => 'orthanc_running']);
        }
        echo json_encode([
            'success' => true,
            'job_state' => $jobState,
            'progress' => $progress,
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (Exception $e) {
    error_log('[PACS_MANAGER][JOB_STATUS] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al consultar estado del trabajo: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
