<?php
/**
 * Worker de lectura automática de TXT desde carpeta (pull).
 * Ejecutar por cron, p.ej: * * * * * php /var/www/tjsiddse/workers/worklist-pull-worker.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/WorklistIngestionService.php';

function logLine(string $msg): void
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

    $stmt = $db->query("SELECT * FROM worklist_config WHERE id = 1");
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$config) {
        throw new Exception('No existe worklist_config');
    }

    if ((int)($config['pull_enabled'] ?? 0) !== 1) {
        logLine('Pull deshabilitado (canal worklist no incluye TXT). Saliendo.');
        exit(0);
    }

    $inbox = rtrim((string)($config['pull_input_path'] ?? ''), '/');
    $processed = rtrim((string)($config['pull_processed_path'] ?? ''), '/');
    $failed = rtrim((string)($config['pull_failed_path'] ?? ''), '/');

    if ($inbox === '') {
        throw new Exception('pull_input_path no configurado');
    }
    if ($processed === '') {
        $processed = $inbox . '/processed';
    }
    if ($failed === '') {
        $failed = $inbox . '/failed';
    }

    foreach ([$inbox, $processed, $failed] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new Exception("No se pudo crear directorio: $dir");
        }
    }

    $files = glob($inbox . '/*.txt') ?: [];
    if (empty($files)) {
        logLine('Sin archivos para procesar');
        exit(0);
    }

    $processedCount = 0;
    $errorCount = 0;

    foreach ($files as $filePath) {
        $basename = basename($filePath);
        $content = @file_get_contents($filePath);
        if ($content === false) {
            $errorCount++;
            @rename($filePath, $failed . '/' . date('Ymd_His') . '_' . $basename);
            logLine("No se pudo leer: $basename");
            continue;
        }

        try {
            $result = $service->ingestTxtContent(
                $content,
                'PULL_FOLDER',
                $filePath,
                null,
                true
            );
            $processedCount++;
            @rename($filePath, $processed . '/' . date('Ymd_His') . '_' . $basename);
            logLine("Procesado $basename => " . ($result['status'] ?? 'ok'));
        } catch (Exception $e) {
            $errorCount++;
            @rename($filePath, $failed . '/' . date('Ymd_His') . '_' . $basename);
            logLine("Error en $basename => " . $e->getMessage());
        }
    }

    logLine("Finalizado. OK=$processedCount ERROR=$errorCount");
    exit(0);
} catch (Exception $e) {
    logLine('FATAL: ' . $e->getMessage());
    exit(1);
}
