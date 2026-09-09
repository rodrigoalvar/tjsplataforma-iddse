<?php
/**
 * Worker de cola FTP: procesa envíos pending y reintentos failed/retrying.
 *
 * Cron recomendado (cada minuto, igual que transcripción):
 * * * * * * php /var/www/tjsiddse/workers/ftp-retry-worker.php >> /var/www/tjsiddse/logs/ftp-queue-worker.log 2>&1
 */

set_time_limit(600);
ini_set('memory_limit', '256M');
chdir(__DIR__);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/FtpAudioSender.php';

$maxRetries = 3;
$maxAge = 24 * 60 * 60;
$batchLimit = 5;

function ftpQueueLog(string $message): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    $logFile = __DIR__ . '/../logs/ftp-queue-worker.log';
    $dir = dirname($logFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($logFile, $line, FILE_APPEND);
    if (php_sapi_name() === 'cli') {
        echo $line;
    }
}

function ftpRetryDelaySeconds(int $attempts): int {
    if ($attempts <= 0) {
        return 0;
    }
    if ($attempts === 1) {
        return 60;
    }
    if ($attempts === 2) {
        return 180;
    }
    return 600;
}

try {
    $db = getDBConnection();
    if (!$db) {
        throw new Exception('No se pudo conectar a la base de datos');
    }

    ftpEnsureLogTable($db);

    // Evitar procesar más de un envío pesado a la vez si ya hay uno en retrying reciente
    $busyStmt = $db->query("
        SELECT COUNT(*) FROM audios_ftp_log
        WHERE status = 'retrying'
          AND updated_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
    ");
    $busyCount = (int) $busyStmt->fetchColumn();
    if ($busyCount > 0) {
        ftpQueueLog('Ya hay envío FTP en proceso. Esperando.');
        exit(0);
    }

    $query = "
        SELECT * FROM audios_ftp_log
        WHERE created_at > DATE_SUB(NOW(), INTERVAL :maxAge SECOND)
          AND (
            status = 'pending'
            OR (
              status IN ('failed', 'retrying')
              AND attempts < :maxRetries
              AND TIMESTAMPDIFF(SECOND, COALESCE(updated_at, created_at), NOW()) >=
                CASE
                  WHEN attempts <= 0 THEN 0
                  WHEN attempts = 1 THEN 60
                  WHEN attempts = 2 THEN 180
                  ELSE 600
                END
            )
          )
        ORDER BY
          CASE WHEN status = 'pending' THEN 0 ELSE 1 END,
          created_at ASC
        LIMIT :batchLimit
    ";

    $stmt = $db->prepare($query);
    $stmt->bindValue(':maxRetries', $maxRetries, PDO::PARAM_INT);
    $stmt->bindValue(':maxAge', $maxAge, PDO::PARAM_INT);
    $stmt->bindValue(':batchLimit', $batchLimit, PDO::PARAM_INT);
    $stmt->execute();

    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        ftpQueueLog('No hay envíos FTP en cola.');
        exit(0);
    }

    ftpQueueLog('Procesando ' . count($items) . ' envío(s) FTP...');

    $successCount = 0;
    $errorCount = 0;
    $skippedCount = 0;

    foreach ($items as $send) {
        try {
            $result = FtpAudioSender::processLogEntry($db, $send);

            if (!empty($result['skipped'])) {
                $skippedCount++;
                ftpQueueLog("⏭️ Log ID {$send['id']} audio_id={$send['audio_id']} omitido");
                continue;
            }

            if (!empty($result['success'])) {
                $successCount++;
                ftpQueueLog("✅ Log ID {$send['id']} audio_id={$send['audio_id']} → {$result['remote_file']}");
            } else {
                $errorCount++;
                $nextDelay = ftpRetryDelaySeconds((int) $send['attempts'] + 1);
                ftpQueueLog("❌ Log ID {$send['id']}: " . ($result['message'] ?? 'error') . " (próximo >= {$nextDelay}s)");
            }
        } catch (Exception $e) {
            $errorCount++;
            $err = $db->prepare("UPDATE audios_ftp_log SET status = 'failed', error_message = ?, updated_at = NOW() WHERE id = ?");
            $err->execute([$e->getMessage(), $send['id']]);
            ftpQueueLog("❌ Log ID {$send['id']}: " . $e->getMessage());
        }
    }

    ftpQueueLog("Resumen: {$successCount} ok, {$errorCount} error(es), {$skippedCount} omitido(s)");
} catch (Exception $e) {
    ftpQueueLog('ERROR: ' . $e->getMessage());
    error_log('ftp-retry-worker.php: ' . $e->getMessage());
    exit(1);
}
