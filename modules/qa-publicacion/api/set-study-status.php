<?php
declare(strict_types=1);

ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../QaPublicationService.php';

$user = requireQaRevisarOrQuick();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    qaJsonError('Método no permitido', 405);
}

$input = qaReadJsonInput();
$orthancId = trim((string) ($input['orthanc_id'] ?? ''));
$studyUid = trim((string) ($input['study_instance_uid'] ?? ''));
$estado = trim((string) ($input['estado'] ?? ''));
$motivo = trim((string) ($input['motivo'] ?? ''));
$motivoDetalle = trim((string) ($input['motivo_detalle'] ?? ''));

if ($studyUid === '') {
    qaJsonError('study_instance_uid es requerido', 400);
}
if (!in_array($estado, ['pendiente', 'publicado', 'bloqueado'], true)) {
    qaJsonError('estado inválido', 400);
}
if ($estado === 'bloqueado' && $motivo === '') {
    qaJsonError('motivo es obligatorio al bloquear', 400);
}

$db = getDBConnection();
if (!QaPublicationService::isInstalled($db)) {
    qaJsonError('El módulo QA no está instalado', 503);
}

QaPublicationService::upsertStudyStatus($db, [
    'orthanc_id' => $orthancId !== '' ? $orthancId : null,
    'study_instance_uid' => $studyUid,
    'patient_id_pacs' => $input['patient_id_pacs'] ?? null,
    'accession_number' => $input['accession_number'] ?? null,
    'estado' => $estado,
    'motivo' => $motivo !== '' ? $motivo : null,
    'motivo_detalle' => $motivoDetalle !== '' ? $motivoDetalle : null,
], (int) $user['id']);

$cascadedInformes = 0;
if ($estado === 'bloqueado') {
    $cascadedInformes = QaPublicationService::cascadeBlockInformesForStudy(
        $db,
        $studyUid,
        (int) $user['id'],
        $motivo !== '' ? $motivo : 'estudio_bloqueado',
        $motivoDetalle !== '' ? $motivoDetalle : null
    );
}

QaPublicationService::logAction($db, [
    'accion' => $estado === 'publicado' ? 'publicar' : ($estado === 'bloqueado' ? 'bloquear' : 'pendiente'),
    'target_type' => 'estudio',
    'orthanc_id' => $orthancId !== '' ? $orthancId : null,
    'study_instance_uid' => $studyUid,
    'usuario_id' => (int) $user['id'],
    'motivo' => $motivo !== '' ? $motivo : null,
    'resultado' => 'ok',
    'patient_id_pacs' => $input['patient_id_pacs'] ?? null,
    'accession_number' => $input['accession_number'] ?? null,
    'patient_name' => $input['patient_name'] ?? null,
    'patient_id' => $input['patient_id'] ?? ($input['patient_id_pacs'] ?? null),
    'modality' => $input['modality'] ?? null,
    'study_date' => $input['study_date'] ?? null,
    'study_description' => $input['study_description'] ?? null,
]);

qaJsonSuccess([
    'status' => QaPublicationService::getStudyStatusByUid($db, $studyUid),
    'informes_cascaded' => $cascadedInformes,
    'qa_enabled' => QaPublicationService::isEnabled($db),
    'portal_effective' => QaPublicationService::isEnabled($db),
]);
