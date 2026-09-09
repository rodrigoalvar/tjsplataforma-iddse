<?php
/**
 * Completa audios_informe.duracion_segundos con ffprobe/ffmpeg sobre el archivo en disco.
 * Solo auditores; no borra ni altera otros flujos.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/_common.php';
auditManagerRequireAuditor();

ini_set('max_execution_time', '180');

$raw = file_get_contents('php://input');
$input = json_decode($raw ?: '{}', true);
$idsIn = $input['audio_ids'] ?? [];
if (!is_array($idsIn)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'audio_ids debe ser un array'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ids = [];
foreach ($idsIn as $v) {
    $n = (int) $v;
    if ($n > 0) {
        $ids[$n] = true;
    }
}
$ids = array_keys($ids);
if (count($ids) > 40) {
    $ids = array_slice($ids, 0, 40);
}

if ($ids === []) {
    echo json_encode([
        'success' => true,
        'updated' => [],
        'skipped' => [],
        'failed' => [],
        'message' => 'Sin IDs válidos',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = getDBConnection();

/**
 * @return float|null
 */
function auditFillProbeDuration(string $absolutePath): ?float {
    if (!is_readable($absolutePath)) {
        return null;
    }

    $ffprobe = trim((string) shell_exec('command -v ffprobe 2>/dev/null || which ffprobe 2>/dev/null'));
    if ($ffprobe !== '') {
        $cmd = escapeshellarg($ffprobe)
            . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 '
            . escapeshellarg($absolutePath)
            . ' 2>/dev/null';
        $out = trim((string) shell_exec($cmd));
        if ($out !== '' && strcasecmp($out, 'N/A') !== 0 && is_numeric($out)) {
            $sec = (float) $out;
            if (is_finite($sec) && $sec > 0) {
                return round($sec, 2);
            }
        }
    }

    $ffmpegPath = trim((string) shell_exec('command -v ffmpeg 2>/dev/null || which ffmpeg 2>/dev/null'));
    if ($ffmpegPath === '') {
        return null;
    }

    $command = 'ffmpeg -i ' . escapeshellarg($absolutePath) . ' 2>&1 | grep \'Duration\' | head -1';
    $output = shell_exec($command);
    if (empty($output)) {
        return null;
    }

    if (preg_match('/Duration:\s*(\d{2}):(\d{2}):(\d{2}\.?\d*)/', $output, $matches)) {
        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];
        $seconds = (float) $matches[3];
        $total = $hours * 3600 + $minutes * 60 + $seconds;

        return $total > 0 ? round($total, 2) : null;
    }

    return null;
}

$projectRoot = realpath(__DIR__ . '/../../..');
if ($projectRoot === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'No se pudo resolver la raíz del proyecto'], JSON_UNESCAPED_UNICODE);
    exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $db->prepare("SELECT id, ruta_archivo, duracion_segundos FROM audios_informe WHERE id IN ($placeholders)");
$stmt->execute($ids);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$updated = [];
$skipped = [];
$failed = [];

$updateStmt = $db->prepare(
    'UPDATE audios_informe SET duracion_segundos = ? WHERE id = ? AND (duracion_segundos IS NULL OR duracion_segundos <= 0)'
);

foreach ($rows as $row) {
    $id = (int) $row['id'];
    $dbDur = $row['duracion_segundos'] !== null ? (float) $row['duracion_segundos'] : null;
    if ($dbDur !== null && $dbDur > 0) {
        $skipped[] = ['id' => $id, 'reason' => 'ya_tiene_duracion'];
        continue;
    }

    $rel = trim((string) ($row['ruta_archivo'] ?? ''));
    if ($rel === '') {
        $failed[] = ['id' => $id, 'reason' => 'sin_ruta_archivo'];
        continue;
    }

    $rel = str_replace('\\', '/', $rel);
    $rel = ltrim($rel, '/');

    $candidates = [
        $projectRoot . '/' . $rel,
        $projectRoot . '/public/' . $rel,
        realpath(__DIR__ . '/../../../' . $rel),
    ];

    $abs = null;
    foreach ($candidates as $c) {
        if ($c && is_readable($c) && is_file($c)) {
            $abs = $c;
            break;
        }
    }

    if ($abs === null) {
        $failed[] = ['id' => $id, 'reason' => 'archivo_no_legible', 'ruta' => $rel];
        continue;
    }

    $sec = auditFillProbeDuration($abs);
    if ($sec === null || $sec <= 0) {
        $failed[] = ['id' => $id, 'reason' => 'sin_duracion_ffprobe'];
        continue;
    }

    $updateStmt->execute([$sec, $id]);
    if ($updateStmt->rowCount() > 0) {
        $updated[] = ['id' => $id, 'duracion_segundos' => $sec];
    } else {
        $skipped[] = ['id' => $id, 'reason' => 'update_sin_cambio'];
    }
}

echo json_encode([
    'success' => true,
    'updated' => $updated,
    'skipped' => $skipped,
    'failed' => $failed,
], JSON_UNESCAPED_UNICODE);
