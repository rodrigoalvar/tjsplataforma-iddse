<?php
/**
 * Procesa un único archivo TXT de worklist (para inotify u otros disparadores).
 * Uso: php worklist-process-one.php /ruta/al/archivo.txt
 *
 * Lee rutas processed/failed desde worklist_config (mismas que el pull worker).
 * Respeta Canal de ingesta: solo procesa si mode es txt|both (o pull_enabled sin mode).
 * Con solo HL7 / none: no ingesta; deja el archivo en el inbox (exit 0).
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/WorklistIngestionService.php';
require_once __DIR__ . '/worklist-txt-ingest-allowed.php';

function logOne(string $msg): void
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL);
}

$filePath = $argv[1] ?? '';
if ($filePath === '' || !is_file($filePath)) {
    fwrite(STDERR, "Uso: php worklist-process-one.php /ruta/al/archivo.txt\n");
    exit(2);
}

$filePath = realpath($filePath);
if ($filePath === false || strcasecmp(substr($filePath, -4), '.txt') !== 0) {
    logOne('No es un .txt válido o no existe');
    exit(2);
}

try {
    $db = getDBConnection();
    if (!$db) {
        throw new Exception('Sin conexión a base de datos');
    }

    $service = new WorklistIngestionService($db);
    $service->ensureSchema();

    $stmt = $db->query("SELECT * FROM worklist_config WHERE id = 1");
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$config) {
        throw new Exception('No existe worklist_config');
    }

    if (!worklist_txt_ingest_allowed($db)) {
        $mode = (string)($config['worklist_ingest_mode'] ?? '');
        logOne('TXT worklist omitido (canal=' . ($mode !== '' ? $mode : 'legacy') . ', pull_enabled=' . (int)($config['pull_enabled'] ?? 0) . '): ' . basename($filePath));
        exit(0);
    }

    $inbox = rtrim((string)($config['pull_input_path'] ?? ''), '/');
    $processed = rtrim((string)($config['pull_processed_path'] ?? ''), '/');
    $failed = rtrim((string)($config['pull_failed_path'] ?? ''), '/');

    if ($inbox !== '') {
        $inboxReal = realpath($inbox);
        if ($inboxReal !== false && strpos($filePath, $inboxReal) !== 0) {
            logOne("Advertencia: el archivo está fuera de pull_input_path ($inbox)");
        }
    }

    if ($processed === '' && $inbox !== '') {
        $processed = $inbox . '/processed';
    }
    if ($failed === '' && $inbox !== '') {
        $failed = $inbox . '/failed';
    }
    if ($processed === '') {
        $processed = dirname($filePath) . '/processed';
    }
    if ($failed === '') {
        $failed = dirname($filePath) . '/failed';
    }

    foreach ([$processed, $failed] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new Exception("No se pudo crear directorio: $dir");
        }
    }

    $basename = basename($filePath);

    // Evitar procesar archivos ya en processed/failed
    if (strpos($filePath, '/processed/') !== false || strpos($filePath, '/failed/') !== false) {
        logOne("Ignorado (ruta processed/failed): $basename");
        exit(0);
    }

    $content = file_get_contents($filePath);
    if ($content === false) {
        throw new Exception('No se pudo leer el archivo');
    }

    $destName = date('Ymd_His') . '_' . $basename;

    try {
        $result = $service->ingestTxtContent(
            $content,
            'PULL_FOLDER',
            $filePath,
            null,
            true
        );
        if (!@rename($filePath, $processed . '/' . $destName)) {
            logOne("Ingesta OK pero no se pudo mover a processed: $basename");
            exit(1);
        }
        logOne("OK $basename => " . ($result['status'] ?? 'ok'));
        exit(0);
    } catch (Exception $e) {
        if (@rename($filePath, $failed . '/' . $destName)) {
            logOne("Error $basename => " . $e->getMessage());
        } else {
            logOne("Error $basename => " . $e->getMessage() . ' (no se pudo mover a failed)');
        }
        exit(1);
    }
} catch (Exception $e) {
    logOne('FATAL: ' . $e->getMessage());
    exit(1);
}
