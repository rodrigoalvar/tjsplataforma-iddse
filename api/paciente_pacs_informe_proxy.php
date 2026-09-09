<?php
/**
 * Proxy de informes PACS para portal paciente v2.
 * Soporta estudios mixtos (CT+DOC): PDF encapsulado e imágenes multipágina.
 *
 * action=pdf     → application/pdf (inline/attachment)
 * action=pages   → JSON con instancias ordenadas
 * action=preview → imagen de una instancia (preview/rendered)
 */
@ini_set('display_errors', '0');
@error_reporting(0);

$studyId = isset($_GET['study_id']) ? trim((string) $_GET['study_id']) : '';
$patientId = isset($_GET['patient_id']) ? trim((string) $_GET['patient_id']) : '';
$seriesId = isset($_GET['series_id']) ? trim((string) $_GET['series_id']) : '';
$instanceId = isset($_GET['instance_id']) ? trim((string) $_GET['instance_id']) : '';
$informeId = isset($_GET['informe_id']) ? (int) $_GET['informe_id'] : 0;
$action = isset($_GET['action']) ? strtolower(trim((string) $_GET['action'])) : 'pdf';
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
    require_once __DIR__ . '/OrthancPacsSender.php';
    require_once __DIR__ . '/OrthancClient.php';
    require_once __DIR__ . '/../config/database.php';
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Error de configuración';
    exit;
}

function pacsInformeJsonError(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function pacsInformePlainError(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

function pacsInformeApiGet(OrthancPacsSender $pacs, string $endpoint): ?array {
    $res = $pacs->makeRequestWithRetry($endpoint, 'GET', null, 12);
    if (!$res['success'] || !is_array($res['data'] ?? null)) {
        return null;
    }
    return $res['data'];
}

function pacsInformeVerifyStudyPatient(OrthancPacsSender $pacs, string $studyId, string $patientId): bool {
    $study = pacsInformeApiGet($pacs, '/studies/' . rawurlencode($studyId));
    if (!$study) {
        return false;
    }
    $pid = trim((string) ($study['PatientMainDicomTags']['PatientID'] ?? ''));
    return $pid === $patientId;
}

function pacsInformeVerifySeriesInStudy(OrthancPacsSender $pacs, string $seriesId, string $studyId): bool {
    $series = pacsInformeApiGet($pacs, '/series/' . rawurlencode($seriesId));
    if (!$series) {
        return false;
    }
    return (string) ($series['ParentStudy'] ?? '') === $studyId;
}

function pacsInformeResolveSeriesFromInforme(int $informeId, string $studyId, string $patientId): array {
    if ($informeId <= 0 || !class_exists('Database')) {
        return ['series_id' => '', 'instance_id' => ''];
    }
    try {
        $db = (new Database())->getConnection();
        if (!$db) {
            return ['series_id' => '', 'instance_id' => ''];
        }
        $stmt = $db->prepare(
            'SELECT pacs_series_id, pacs_instance_id, patient_id, study_id
             FROM informes WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$informeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['series_id' => '', 'instance_id' => ''];
        }
        if (trim((string) ($row['patient_id'] ?? '')) !== $patientId) {
            return ['series_id' => '', 'instance_id' => ''];
        }
        $linkedStudy = trim((string) ($row['study_id'] ?? ''));
        if ($linkedStudy !== '' && $linkedStudy !== $studyId) {
            return ['series_id' => '', 'instance_id' => ''];
        }
        return [
            'series_id' => trim((string) ($row['pacs_series_id'] ?? '')),
            'instance_id' => trim((string) ($row['pacs_instance_id'] ?? '')),
        ];
    } catch (Throwable $e) {
        return ['series_id' => '', 'instance_id' => ''];
    }
}

function pacsInformeGetBinary(OrthancClient $client, string $endpoint): ?string {
    $res = $client->getBinary($endpoint);
    if (($res['http_code'] ?? 0) === 200 && ($res['body'] ?? '') !== '') {
        return $res['body'];
    }
    return null;
}

$pacs = new OrthancPacsSender();
$client = new OrthancClient();

if (!pacsInformeVerifyStudyPatient($pacs, $studyId, $patientId)) {
    if ($action === 'pages') {
        pacsInformeJsonError(403, 'No autorizado o estudio no disponible');
    }
    pacsInformePlainError(403, 'No autorizado o estudio no disponible');
}

if ($informeId > 0) {
    $fromDb = pacsInformeResolveSeriesFromInforme($informeId, $studyId, $patientId);
    if ($seriesId === '' && $fromDb['series_id'] !== '') {
        $seriesId = $fromDb['series_id'];
    }
    if ($instanceId === '' && $fromDb['instance_id'] !== '') {
        $instanceId = $fromDb['instance_id'];
    }
}

if ($seriesId === '') {
    $doc = $pacs->getDocSeriesInStudy($studyId, null);
    if ($doc) {
        $seriesId = (string) ($doc['series_id'] ?? '');
        if ($instanceId === '') {
            $instanceId = trim((string) ($doc['instance_id'] ?? ''));
        }
    }
}

if ($seriesId !== '' && !pacsInformeVerifySeriesInStudy($pacs, $seriesId, $studyId)) {
    if ($action === 'pages') {
        pacsInformeJsonError(403, 'Serie no pertenece al estudio');
    }
    pacsInformePlainError(403, 'Serie no pertenece al estudio');
}

if ($action === 'pages') {
    if ($seriesId === '') {
        pacsInformeJsonError(404, 'No hay serie DOC disponible');
    }
    $series = pacsInformeApiGet($pacs, '/series/' . rawurlencode($seriesId));
    if (!$series) {
        pacsInformeJsonError(404, 'Serie no encontrada');
    }
    $instances = [];
    foreach ($series['Instances'] ?? [] as $instId) {
        $instId = (string) $instId;
        $num = 0;
        $tags = pacsInformeApiGet($pacs, '/instances/' . rawurlencode($instId) . '/simplified-tags');
        if ($tags) {
            $num = (int) ($tags['InstanceNumber'] ?? 0);
        }
        $instances[] = ['instance_id' => $instId, 'instance_number' => $num];
    }
    usort($instances, function ($a, $b) {
        return ($a['instance_number'] ?? 0) <=> ($b['instance_number'] ?? 0);
    });
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'study_id' => $studyId,
        'series_id' => $seriesId,
        'pages' => $instances,
        'page_count' => count($instances),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'preview') {
    if ($instanceId === '') {
        pacsInformePlainError(400, 'instance_id requerido');
    }
    $instMeta = pacsInformeApiGet($pacs, '/instances/' . rawurlencode($instanceId));
    if (!$instMeta || (string) ($instMeta['ParentStudy'] ?? '') !== $studyId) {
        pacsInformePlainError(403, 'Instancia no autorizada');
    }
    $encId = rawurlencode($instanceId);
    foreach (['/instances/' . $encId . '/preview', '/instances/' . $encId . '/rendered'] as $path) {
        $body = pacsInformeGetBinary($client, $path);
        if ($body !== null) {
            header('Content-Type: image/png');
            header('Content-Length: ' . strlen($body));
            header('Cache-Control: private, max-age=300');
            echo $body;
            exit;
        }
    }
    pacsInformePlainError(404, 'No se pudo obtener la imagen');
}

// action=pdf (default)
$targetInstance = $instanceId;
if ($targetInstance === '' && $seriesId !== '') {
    $series = pacsInformeApiGet($pacs, '/series/' . rawurlencode($seriesId));
    $instList = $series['Instances'] ?? [];
    if (!empty($instList[0])) {
        $targetInstance = (string) $instList[0];
    }
}

if ($targetInstance === '') {
    pacsInformePlainError(404, 'No hay instancia DOC disponible');
}

$encId = rawurlencode($targetInstance);
$pdfBinary = null;
foreach (['/instances/' . $encId . '/pdf', '/instances/' . $encId . '/file'] as $path) {
    $body = pacsInformeGetBinary($client, $path);
    if ($body !== null && strlen($body) > 4 && substr($body, 0, 4) === '%PDF') {
        $pdfBinary = $body;
        break;
    }
}

if ($pdfBinary === null) {
    pacsInformePlainError(404, 'No se pudo obtener un PDF válido para este informe');
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
