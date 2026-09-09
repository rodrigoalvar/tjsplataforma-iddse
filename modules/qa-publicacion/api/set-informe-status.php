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
require_once __DIR__ . '/../../../api/informes/InformePacsRemover.php';

$user = requireQaRevisarOrQuick();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    qaJsonError('Método no permitido', 405);
}

$input = qaReadJsonInput();
$informeId = (int) ($input['informe_id'] ?? 0);
$estado = trim((string) ($input['estado'] ?? ''));
$motivo = trim((string) ($input['motivo'] ?? ''));
$motivoDetalle = trim((string) ($input['motivo_detalle'] ?? ''));
$bajarDePacs = !empty($input['bajar_de_pacs']);

if ($informeId <= 0) {
    qaJsonError('informe_id es requerido', 400);
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

$stmt = $db->prepare(
    'SELECT id, study_id, study_instance_uid, pacs_series_id, pacs_instance_id, patient_id,
            patient_name, modality, titulo, fecha_creacion
     FROM informes WHERE id = ?'
);
$stmt->execute([$informeId]);
$informe = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$informe) {
    qaJsonError('Informe no encontrado', 404);
}

$bajadoDePacs = false;
$removeResult = null;

if ($bajarDePacs) {
    if (!qaUserHasAnyPermission($user, ['qa_bajar_pacs', 'gestionInformes', 'all'])) {
        qaJsonError('Se requiere permiso qa_bajar_pacs o gestionInformes', 403);
    }
    $removeResult = InformePacsRemover::removeInformeFromPacs($db, $informeId);
    if (!$removeResult['success']) {
        qaJsonError($removeResult['message'], 400);
    }
    $bajadoDePacs = true;
    $estado = QaPublicationService::ESTADO_BLOQUEADO;

    QaPublicationService::logAction($db, [
        'accion' => 'bajar_pacs',
        'target_type' => 'informe',
        'orthanc_id' => $informe['study_id'] ?? null,
        'study_instance_uid' => $informe['study_instance_uid'] ?? null,
        'informe_id' => $informeId,
        'pacs_series_id' => $removeResult['data']['series_id'] ?? null,
        'pacs_instance_id' => $removeResult['data']['instance_id'] ?? null,
        'usuario_id' => (int) $user['id'],
        'motivo' => $motivo !== '' ? $motivo : 'bajar_pacs',
        'resultado' => 'ok',
        'patient_name' => $informe['patient_name'] ?? null,
        'patient_id' => $informe['patient_id'] ?? null,
        'modality' => $informe['modality'] ?? null,
        'study_date' => $informe['fecha_creacion'] ?? null,
        'study_description' => $informe['titulo'] ?? null,
        'detalle' => $removeResult,
    ]);
}

QaPublicationService::upsertInformeStatus(
    $db,
    $informeId,
    $estado,
    $motivo !== '' ? $motivo : null,
    $motivoDetalle !== '' ? $motivoDetalle : null,
    (int) $user['id'],
    $informe['study_instance_uid'] ?? null,
    $bajadoDePacs
);

if (!$bajarDePacs) {
    QaPublicationService::logAction($db, [
        'accion' => $estado === 'publicado' ? 'publicar' : ($estado === 'bloqueado' ? 'bloquear' : 'pendiente'),
        'target_type' => 'informe',
        'orthanc_id' => $informe['study_id'] ?? null,
        'study_instance_uid' => $informe['study_instance_uid'] ?? null,
        'informe_id' => $informeId,
        'usuario_id' => (int) $user['id'],
        'motivo' => $motivo !== '' ? $motivo : null,
        'resultado' => 'ok',
        'patient_name' => $informe['patient_name'] ?? null,
        'patient_id' => $informe['patient_id'] ?? null,
        'modality' => $informe['modality'] ?? null,
        'study_date' => $informe['fecha_creacion'] ?? null,
        'study_description' => $informe['titulo'] ?? null,
    ]);
}

qaJsonSuccess([
    'status' => QaPublicationService::getInformeStatus($db, $informeId),
    'remove_from_pacs' => $removeResult,
    'en_pacs' => InformePacsRemover::informeHasPacsInOrthanc($db, $informeId),
    'qa_enabled' => QaPublicationService::isEnabled($db),
    'portal_effective' => QaPublicationService::isEnabled($db),
]);
