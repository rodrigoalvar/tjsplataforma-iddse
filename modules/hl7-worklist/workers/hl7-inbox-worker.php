<?php
/**
 * Worker cron: procesa *.hl7 en inbox HL7.
 * * * * * * php /var/www/tjsiddse/modules/hl7-worklist/workers/hl7-inbox-worker.php
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../utils/WorklistIngestionService.php';
require_once __DIR__ . '/../php/bootstrap.php';

function logHl7Inbox(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
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
    if ((int)($config['hl7_enabled'] ?? 0) !== 1) {
        logHl7Inbox('HL7 deshabilitado. Saliendo.');
        exit(0);
    }

    $inbox = rtrim((string)($config['hl7_input_path'] ?? Hl7WorklistModule::defaultInboxPath()), '/');
    $processed = rtrim((string)($config['hl7_processed_path'] ?? ($inbox . '/processed')), '/');
    $failed = rtrim((string)($config['hl7_failed_path'] ?? ($inbox . '/failed')), '/');
    $prestador = Hl7WorklistModule::getPrestadorField($db);

    if ($inbox === '') {
        throw new Exception('hl7_input_path no configurado');
    }

    foreach ([$inbox, $processed, $failed] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new Exception("No se pudo crear directorio: $dir");
        }
    }

    $files = array_merge(glob($inbox . '/*.hl7') ?: [], glob($inbox . '/*.HL7') ?: []);
    $files = array_values(array_unique($files));
    if (empty($files)) {
        logHl7Inbox('Sin archivos para procesar');
        exit(0);
    }

    $ok = 0;
    $err = 0;
    foreach ($files as $filePath) {
        $basename = basename($filePath);
        $content = @file_get_contents($filePath);
        if ($content === false) {
            $err++;
            @rename($filePath, $failed . '/' . date('Ymd_His') . '_' . $basename);
            logHl7Inbox("No se pudo leer: $basename");
            continue;
        }
        $destName = date('Ymd_His') . '_' . $basename;
        try {
            $result = $service->ingestHl7Content($content, $filePath, null, true, $prestador);
            @rename($filePath, $processed . '/' . $destName);
            $ok++;
            logHl7Inbox("OK $basename => " . ($result['status'] ?? 'ok'));
        } catch (Exception $e) {
            @rename($filePath, $failed . '/' . $destName);
            $err++;
            logHl7Inbox("Error $basename => " . $e->getMessage());
        }
    }

    logHl7Inbox("Fin. ok=$ok err=$err");
} catch (Exception $e) {
    logHl7Inbox('FATAL: ' . $e->getMessage());
    exit(1);
}
