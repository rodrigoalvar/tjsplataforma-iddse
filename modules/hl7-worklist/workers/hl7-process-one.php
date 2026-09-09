<?php
/**
 * Procesa un único archivo .hl7 de worklist.
 * Uso: php hl7-process-one.php /ruta/al/archivo.hl7
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../utils/WorklistIngestionService.php';
require_once __DIR__ . '/../php/bootstrap.php';

function logHl7One(string $msg): void
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL);
}

$filePath = $argv[1] ?? '';
if ($filePath === '' || !is_file($filePath)) {
    fwrite(STDERR, "Uso: php hl7-process-one.php /ruta/al/archivo.hl7\n");
    exit(2);
}

$filePath = realpath($filePath);
$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
if ($filePath === false || $ext !== 'hl7') {
    logHl7One('No es un .hl7 válido o no existe');
    exit(2);
}

try {
    $db = getDBConnection();
    if (!$db) {
        throw new Exception('Sin conexión a base de datos');
    }

    $service = new WorklistIngestionService($db);
    $service->ensureSchema();
    Hl7WorklistModule::ensureConfigColumns($db);
    Hl7WorklistModule::ensureIngestSourceType($db);

    $config = Hl7WorklistModule::readConfigFromDb($db);
    $inbox = rtrim((string)($config['hl7_input_path'] ?? Hl7WorklistModule::defaultInboxPath()), '/');
    $processed = rtrim((string)($config['hl7_processed_path'] ?? ($inbox . '/processed')), '/');
    $failed = rtrim((string)($config['hl7_failed_path'] ?? ($inbox . '/failed')), '/');

    foreach ([$processed, $failed] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new Exception("No se pudo crear directorio: $dir");
        }
    }

    $basename = basename($filePath);
    if (strpos($filePath, '/processed/') !== false || strpos($filePath, '/failed/') !== false) {
        logHl7One("Ignorado (ruta processed/failed): $basename");
        exit(0);
    }

    $content = file_get_contents($filePath);
    if ($content === false) {
        throw new Exception('No se pudo leer el archivo');
    }

    $destName = date('Ymd_His') . '_' . $basename;
    $prestador = Hl7WorklistModule::getPrestadorField($db);

    try {
        $result = $service->ingestHl7Content($content, $filePath, null, true, $prestador);
        if (!@rename($filePath, $processed . '/' . $destName)) {
            logHl7One("Ingesta OK pero no se pudo mover a processed: $basename");
            exit(1);
        }
        logHl7One('OK ' . $basename . ' => ' . ($result['status'] ?? 'ok')
            . ' accession=' . ($result['accession_number'] ?? $result['data']['accession_number'] ?? ''));
        exit(0);
    } catch (Exception $e) {
        @rename($filePath, $failed . '/' . $destName);
        logHl7One('Error ' . $basename . ' => ' . $e->getMessage());
        exit(1);
    }
} catch (Exception $e) {
    logHl7One('FATAL: ' . $e->getMessage());
    exit(1);
}
