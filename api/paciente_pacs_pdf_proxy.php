<?php
/**
 * Proxy PDF para portal paciente: estudios DOC + descripción INFORME (solo DOC).
 * Valida PatientID y sirve el PDF desde Orthanc (inline o descarga).
 */
@ini_set('display_errors', '0');
@error_reporting(0);

$studyId = isset($_GET['study_id']) ? trim((string) $_GET['study_id']) : '';
$patientId = isset($_GET['patient_id']) ? trim((string) $_GET['patient_id']) : '';
$disposition = isset($_GET['disposition']) ? strtolower(trim((string) $_GET['disposition'])) : 'inline';
if ($disposition !== 'attachment') {
    $disposition = 'inline';
}

if ($studyId === '' || $patientId === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Parámetros incompletos';
    exit;
}

try {
    if (!class_exists('OrthancClient')) {
        require_once __DIR__ . '/OrthancClient.php';
    }
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Error de configuración';
    exit;
}

$client = new OrthancClient();
$instanceId = $client->resolvePacsPdfInformeInstanceForPatient($studyId, $patientId);
if ($instanceId === null) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No autorizado o estudio no disponible';
    exit;
}

$encId = rawurlencode($instanceId);
$candidates = [
    '/instances/' . $encId . '/pdf',
    '/instances/' . $encId . '/file',
];

$pdfBinary = null;
foreach ($candidates as $path) {
    $res = $client->getBinary($path);
    if ($res['http_code'] === 200 && strlen($res['body']) > 4 && substr($res['body'], 0, 4) === '%PDF') {
        $pdfBinary = $res['body'];
        break;
    }
}

if ($pdfBinary === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No se pudo obtener un PDF válido para este estudio';
    exit;
}

$shortId = strlen($studyId) > 8 ? substr($studyId, 0, 8) : $studyId;
$filename = 'INFORME-' . date('Ymd') . '-' . $shortId . '.pdf';
$filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($pdfBinary));
header('Cache-Control: private, max-age=0, must-revalidate');
if ($disposition === 'attachment') {
    header('Content-Disposition: attachment; filename="' . $filename . '"');
} else {
    header('Content-Disposition: inline; filename="' . $filename . '"');
}
echo $pdfBinary;
exit;
