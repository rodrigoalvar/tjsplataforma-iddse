<?php
/**
 * api/informes/attach-audio.php
 *
 * Adjunta un archivo de audio a un informe existente (Variante A) o crea un
 * nuevo informe a partir de datos de estudio PACS y adjunta el audio (Variante B).
 * Tras el guardado, encola el audio en ai_transcription_queue si la
 * auto-transcripción global está activada. NO realiza envío FTP.
 *
 * Permiso requerido: adjuntar_audios  o  all
 *
 * POST multipart/form-data
 *   audio_file           — archivo de audio (requerido)
 *   titulo               — string, opcional
 *
 *   Variante A — adjuntar a informe existente:
 *     informe_id         — int
 *
 *   Variante B — crear nuevo informe desde estudio PACS:
 *     estudio_id         — string (StudyInstanceUID / Orthanc Study ID)
 *     patient_id         — string
 *     patient_name       — string
 *     modality           — string
 *     study_description  — string
 *     study_instance_uid — string
 *     study_id           — string
 *     accession_number   — string
 */

ini_set('upload_max_filesize', '50M');
ini_set('post_max_size', '50M');
ini_set('memory_limit', '128M');
ini_set('max_execution_time', '300');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) === 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}

require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../config/database.php';

// ─── Helpers ────────────────────────────────────────────────────────────────

function attachAudio_jsonError(int $code, string $message): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit();
}

function attachAudio_getDurationFfprobe(string $filePath): ?float {
    if (!is_readable($filePath)) return null;
    $ffprobe = trim((string)shell_exec('command -v ffprobe 2>/dev/null || which ffprobe 2>/dev/null'));
    if ($ffprobe === '') return null;
    $out = trim((string)shell_exec(
        escapeshellarg($ffprobe) .
        ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' .
        escapeshellarg($filePath) . ' 2>/dev/null'
    ));
    if ($out === '' || !is_numeric($out)) return null;
    $sec = (float)$out;
    return (is_finite($sec) && $sec > 0) ? round($sec, 2) : null;
}

function attachAudio_getDurationFfmpeg(string $filePath): ?float {
    $ff = trim((string)shell_exec('which ffmpeg 2>/dev/null'));
    if ($ff === '') return null;
    $out = (string)shell_exec('ffmpeg -i ' . escapeshellarg($filePath) . ' 2>&1 | grep Duration | head -1');
    if (preg_match('/Duration:\s*(\d{2}):(\d{2}):(\d{2}\.?\d*)/', $out, $m)) {
        $total = (int)$m[1] * 3600 + (int)$m[2] * 60 + (float)$m[3];
        return ($total > 0) ? round($total, 2) : null;
    }
    return null;
}

// ─── Auth ────────────────────────────────────────────────────────────────────

$sessionToken = null;
if (function_exists('getallheaders')) {
    $hdrs = getallheaders();
    $sessionToken = $hdrs['Authorization'] ?? null;
}
if (!$sessionToken) $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
if (!$sessionToken) $sessionToken = $_POST['session_token'] ?? null;
if ($sessionToken && stripos($sessionToken, 'Bearer ') === 0) {
    $sessionToken = substr($sessionToken, 7);
}
if (!$sessionToken) attachAudio_jsonError(401, 'Token de sesión requerido');

$user     = new User();
$userData = $user->validateSession($sessionToken);
if (!$userData) attachAudio_jsonError(401, 'Sesión inválida');

$userPermisos = json_decode($userData['permisos'] ?? '[]', true);
if (!is_array($userPermisos)) $userPermisos = [];

$canAttach = in_array('all', $userPermisos, true)
    || in_array('adjuntar_audios', $userPermisos, true);

if (!$canAttach) {
    attachAudio_jsonError(403, 'No tiene permisos para adjuntar audios');
}

// ─── Validar archivo ─────────────────────────────────────────────────────────

if (!isset($_FILES['audio_file']) || $_FILES['audio_file']['error'] !== UPLOAD_ERR_OK) {
    $errMap = [
        UPLOAD_ERR_INI_SIZE   => 'El archivo excede el límite del servidor (' . ini_get('upload_max_filesize') . ')',
        UPLOAD_ERR_FORM_SIZE  => 'El archivo excede el tamaño del formulario',
        UPLOAD_ERR_PARTIAL    => 'El archivo se subió parcialmente',
        UPLOAD_ERR_NO_FILE    => 'No se recibió ningún archivo',
        UPLOAD_ERR_NO_TMP_DIR => 'Falta la carpeta temporal del servidor',
        UPLOAD_ERR_CANT_WRITE => 'No se pudo escribir el archivo en disco',
    ];
    $errCode = $_FILES['audio_file']['error'] ?? UPLOAD_ERR_NO_FILE;
    attachAudio_jsonError(400, $errMap[$errCode] ?? 'Error al recibir el archivo de audio');
}

$file = $_FILES['audio_file'];

$allowedMime = [
    'audio/wav', 'audio/x-wav', 'audio/wave',
    'audio/mp3', 'audio/mpeg', 'audio/mpeg3', 'audio/x-mpeg-3',
    'audio/ogg', 'audio/vorbis',
    'audio/webm',
    'audio/mp4', 'audio/m4a', 'audio/x-m4a',
    'audio/aac', 'audio/x-aac',
    'audio/flac', 'audio/x-flac',
];
$allowedExt = ['wav', 'mp3', 'm4a', 'webm', 'mp4', 'ogg', 'aac', 'flac'];

$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($mimeType, $allowedMime, true) && !in_array($ext, $allowedExt, true)) {
    attachAudio_jsonError(400, 'Tipo de archivo no permitido. Solo se aceptan: MP3, WAV, M4A, WebM, OGG, AAC, FLAC');
}

$maxSize = 50 * 1024 * 1024;
if ($file['size'] > $maxSize) {
    attachAudio_jsonError(400, 'El archivo es demasiado grande. Máximo 50 MB');
}

// ─── Determinar variante (A: informe_id / B: datos PACS) ─────────────────────

$db = getDBConnection();

$informeId    = isset($_POST['informe_id']) ? (int)$_POST['informe_id'] : 0;
$titulo       = trim($_POST['titulo'] ?? '');

// Variante A: informe existente
if ($informeId > 0) {
    $chk = $db->prepare("SELECT id, estudio_id, patient_name, patient_id FROM informes WHERE id = ?");
    $chk->execute([$informeId]);
    $informeRow = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$informeRow) {
        attachAudio_jsonError(404, 'El informe seleccionado no existe');
    }
    $estudioId   = $informeRow['estudio_id'];
    $patientName = $informeRow['patient_name'] ?? '';
    $patientId   = $informeRow['patient_id']   ?? '';
    $modoCreado  = false;
} else {
    // Variante B: crear nuevo informe desde estudio PACS
    $estudioId          = trim($_POST['estudio_id']          ?? '');
    $patientId          = trim($_POST['patient_id']          ?? '');
    $patientName        = trim($_POST['patient_name']        ?? '');
    $modality           = trim($_POST['modality']            ?? '');
    $studyDescription   = trim($_POST['study_description']   ?? '');
    $studyInstanceUID   = trim($_POST['study_instance_uid']  ?? '');
    $studyIdValue       = trim($_POST['study_id']            ?? '');
    $accessionNumber    = trim($_POST['accession_number']    ?? '');

    if (empty($estudioId)) {
        attachAudio_jsonError(400, 'Se requiere informe_id o los datos del estudio PACS');
    }

    if (empty($titulo)) {
        $titulo = 'Audio Adjunto - ' . ($patientName ?: 'Paciente') . ' - ' . date('d/m/Y');
    }

    // Verificar columna es_adjunto
    $colChk = $db->query("SHOW COLUMNS FROM informes LIKE 'es_adjunto'");
    $hasEsAdjunto = ($colChk && $colChk->rowCount() > 0);

    if ($hasEsAdjunto) {
        $ins = $db->prepare("INSERT INTO informes (
            estudio_id, study_instance_uid, study_id, usuario_id, patient_id, patient_name,
            modality, study_description, titulo, contenido_html, contenido_texto,
            estado, version, notas_revision, es_adjunto, accession_number
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'borrador', 1, ?, 0, ?)");
        $ins->execute([
            $estudioId, $studyInstanceUID ?: null, $studyIdValue ?: null,
            $userData['id'], $patientId, $patientName,
            $modality, $studyDescription, $titulo,
            '', 'Informe con audio adjunto',
            'Informe creado al adjuntar audio',
            $accessionNumber ?: null,
        ]);
    } else {
        $ins = $db->prepare("INSERT INTO informes (
            estudio_id, study_instance_uid, study_id, usuario_id, patient_id, patient_name,
            modality, study_description, titulo, contenido_html, contenido_texto,
            estado, version, notas_revision, accession_number
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'borrador', 1, ?, ?)");
        $ins->execute([
            $estudioId, $studyInstanceUID ?: null, $studyIdValue ?: null,
            $userData['id'], $patientId, $patientName,
            $modality, $studyDescription, $titulo,
            '', 'Informe con audio adjunto',
            'Informe creado al adjuntar audio',
            $accessionNumber ?: null,
        ]);
    }

    $informeId  = (int)$db->lastInsertId();
    $modoCreado = true;
    error_log("attach-audio.php: Informe creado ID=$informeId para estudio $estudioId");
}

// ─── Guardar archivo en disco ─────────────────────────────────────────────────

$base    = realpath(__DIR__ . '/../../');
$audDir  = $base . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'audios';

if (!is_dir($audDir)) {
    if (!@mkdir($audDir, 0775, true) && !is_dir($audDir)) {
        attachAudio_jsonError(500, 'No se pudo crear el directorio de audios');
    }
    @chmod($audDir, 0775);
}
if (!is_writable($audDir)) {
    attachAudio_jsonError(500, 'El directorio de audios no tiene permisos de escritura');
}

if (!$ext || !in_array($ext, $allowedExt, true)) {
    $extMap = [
        'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/wave' => 'wav',
        'audio/mp3' => 'mp3', 'audio/mpeg' => 'mp3', 'audio/mpeg3' => 'mp3',
        'audio/ogg' => 'ogg', 'audio/vorbis' => 'ogg',
        'audio/webm' => 'webm',
        'audio/mp4' => 'mp4', 'audio/m4a' => 'm4a', 'audio/x-m4a' => 'm4a',
        'audio/aac' => 'aac', 'audio/x-aac' => 'aac',
        'audio/flac' => 'flac', 'audio/x-flac' => 'flac',
    ];
    $ext = $extMap[$mimeType] ?? 'mp3';
}

$fileName    = 'audio_adj_' . $informeId . '_' . time() . '_' . uniqid() . '.' . $ext;
$savedPath   = $audDir . DIRECTORY_SEPARATOR . $fileName;
$relPath     = 'uploads/audios/' . $fileName;

if (!move_uploaded_file($file['tmp_name'], $savedPath)) {
    attachAudio_jsonError(500, 'No se pudo guardar el archivo de audio en el servidor');
}
@chmod($savedPath, 0644);

// ─── Duración ─────────────────────────────────────────────────────────────────

$duracion = null;
$realPath = realpath($savedPath) ?: $savedPath;

$dur = attachAudio_getDurationFfprobe($realPath);
if ($dur !== null && $dur > 0) {
    $duracion = $dur;
}
if (!$duracion) {
    $dur2 = attachAudio_getDurationFfmpeg($realPath);
    if ($dur2 !== null && $dur2 > 0) $duracion = $dur2;
}
// Hint del cliente como último recurso
if (!$duracion) {
    $hint = isset($_POST['client_duration']) ? (float)$_POST['client_duration'] : 0;
    if ($hint > 0 && $hint < 21600) $duracion = round($hint, 2);
}

// ─── INSERT audios_informe ────────────────────────────────────────────────────

$colEstado = $db->query("SHOW COLUMNS FROM audios_informe LIKE 'estado'");
$hasEstado = ($colEstado && $colEstado->rowCount() > 0);

if ($hasEstado) {
    $insAud = $db->prepare("INSERT INTO audios_informe (
        informe_id, estudio_id, usuario_id, nombre_archivo, ruta_archivo,
        nombre_original, tipo_mime, tamano_bytes, duracion_segundos,
        tipo_grabacion, transcripcion_texto, estado
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'simple', NULL, 'guardado_informe')");
    $insAud->execute([
        $informeId, $estudioId, $userData['id'],
        $fileName, $relPath, $file['name'],
        $mimeType, $file['size'], $duracion,
    ]);
} else {
    $insAud = $db->prepare("INSERT INTO audios_informe (
        informe_id, estudio_id, usuario_id, nombre_archivo, ruta_archivo,
        nombre_original, tipo_mime, tamano_bytes, duracion_segundos,
        tipo_grabacion, transcripcion_texto
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'simple', NULL)");
    $insAud->execute([
        $informeId, $estudioId, $userData['id'],
        $fileName, $relPath, $file['name'],
        $mimeType, $file['size'], $duracion,
    ]);
}

$audioId = (int)$db->lastInsertId();
error_log("attach-audio.php: Audio guardado ID=$audioId, informe=$informeId, archivo=$fileName");

// ─── Log de estado (si existe la tabla) ───────────────────────────────────────

try {
    $logChk = $db->query("SHOW TABLES LIKE 'audios_estado_log'");
    if ($logChk && $logChk->rowCount() > 0) {
        $logIns = $db->prepare("INSERT INTO audios_estado_log
            (audio_id, estado_anterior, estado_nuevo, accion, usuario_id, estudio_id)
            VALUES (?, NULL, 'guardado_informe', 'adjuntar_audio', ?, ?)");
        $logIns->execute([$audioId, $userData['id'], $estudioId]);
    }
} catch (Exception $e) {
    error_log("attach-audio.php: Error en log de estado (ignorado): " . $e->getMessage());
}

// ─── Encolar en ai_transcription_queue ───────────────────────────────────────

$enqueued = false;
$enqueueReason = '';

try {
    $cfgChk = $db->query("SHOW TABLES LIKE 'ai_config'");
    if ($cfgChk && $cfgChk->rowCount() > 0) {
        $cfgStmt = $db->prepare("SELECT auto_transcribe_enabled FROM ai_config WHERE id = 1");
        $cfgStmt->execute();
        $cfg = $cfgStmt->fetch(PDO::FETCH_ASSOC);

        if ($cfg && !empty($cfg['auto_transcribe_enabled'])) {
            require_once __DIR__ . '/../transcription_health.php';
            $txHealth = transcriptionCheckHealth($db, null, 4);
            if (empty($txHealth['allow_enqueue'])) {
                $enqueueReason = 'Whisper no listo: ' . ($txHealth['message'] ?? 'down');
                error_log("attach-audio.php: No se encola audio ID=$audioId — " . $enqueueReason);
            } else {
            $qChk = $db->query("SHOW TABLES LIKE 'ai_transcription_queue'");
            if ($qChk && $qChk->rowCount() > 0) {
                // Resolver study_id local
                $studyIdLocal = null;
                if ($estudioId) {
                    $stStmt = $db->prepare("SELECT id FROM estudios WHERE orthanc_study_id = ? LIMIT 1");
                    $stStmt->execute([$estudioId]);
                    $stRow = $stStmt->fetch(PDO::FETCH_ASSOC);
                    if ($stRow) $studyIdLocal = (int)$stRow['id'];
                }

                // Verificar que no esté ya en cola
                $dupChk = $db->prepare("
                    SELECT id FROM ai_transcription_queue
                    WHERE audio_id = ? AND status IN ('pending','processing') LIMIT 1
                ");
                $dupChk->execute([$audioId]);
                if (!$dupChk->fetch()) {
                    $qIns = $db->prepare("INSERT INTO ai_transcription_queue
                        (audio_id, study_id, orthanc_study_id, status, priority, created_by, created_at)
                        VALUES (?, ?, ?, 'pending', 0, ?, NOW())");
                    $qIns->execute([$audioId, $studyIdLocal, $estudioId, $userData['id']]);
                    $enqueued = true;
                    error_log("attach-audio.php: Audio ID=$audioId encolado en ai_transcription_queue");
                }
            } else {
                $enqueueReason = 'Tabla ai_transcription_queue no existe';
            }
            } // end allow_enqueue
        } else {
            $enqueueReason = 'auto_transcribe_enabled desactivado';
        }
    } else {
        $enqueueReason = 'Tabla ai_config no existe';
    }
} catch (Exception $e) {
    error_log("attach-audio.php: Error al encolar (ignorado): " . $e->getMessage());
    $enqueueReason = 'Error: ' . $e->getMessage();
}

// ─── Respuesta ────────────────────────────────────────────────────────────────

echo json_encode([
    'success'  => true,
    'message'  => $modoCreado
        ? 'Audio adjuntado y nuevo informe creado exitosamente'
        : 'Audio adjuntado al informe existente exitosamente',
    'data' => [
        'audio_id'         => $audioId,
        'informe_id'       => $informeId,
        'informe_creado'   => $modoCreado,
        'estudio_id'       => $estudioId,
        'nombre_archivo'   => $fileName,
        'ruta_archivo'     => $relPath,
        'tipo_mime'        => $mimeType,
        'tamano_bytes'     => $file['size'],
        'duracion_segundos'=> $duracion,
        'enqueued'         => $enqueued,
        'enqueue_reason'   => $enqueueReason ?: null,
    ],
], JSON_UNESCAPED_UNICODE);
