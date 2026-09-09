<?php
/**
 * Worker watchdog para la cola de transcripción AI.
 *
 * Objetivo:
 * - Detectar jobs en status=processing colgados por timeout.
 * - Reencolar con retry_count++ (y backoff implícito) o marcar failed al agotar reintentos.
 *
 * Ejecución sugerida (cron, cada 2 minutos):
 * cron: cada2min php /var/www/tjsiddse/workers/transcription-watchdog.php
 */

set_time_limit(120);
ini_set('memory_limit', '256M');

require_once __DIR__ . '/../config/database.php';

function wdLog(string $message, string $level = 'INFO'): void {
    $timestamp = date('Y-m-d H:i:s');
    $logFile = __DIR__ . '/../logs/transcription-watchdog.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $line = "[$timestamp] [$level] $message\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    if (php_sapi_name() === 'cli') {
        echo $line;
    }
}

function wdDelaySeconds(int $retryCount): int {
    if ($retryCount <= 0) return 0;
    if ($retryCount === 1) return 60;
    if ($retryCount === 2) return 180;
    if ($retryCount === 3) return 600;
    return 1800;
}

try {
    $db = getDBConnection();
    if (!$db) {
        throw new Exception('No se pudo conectar a la base de datos');
    }

    $checkTable = $db->query("SHOW TABLES LIKE 'ai_transcription_queue'");
    if ($checkTable->rowCount() === 0) {
        wdLog('Tabla ai_transcription_queue no existe. Finaliza watchdog.', 'WARNING');
        exit(0);
    }

    $processingTimeoutSeconds = 20 * 60;
    $staleStmt = $db->prepare("
        SELECT id, audio_id, retry_count, max_retries, started_at
        FROM ai_transcription_queue
        WHERE status = 'processing'
          AND started_at IS NOT NULL
          AND TIMESTAMPDIFF(SECOND, started_at, NOW()) >= ?
        ORDER BY started_at ASC
        LIMIT 100
    ");
    $staleStmt->execute([$processingTimeoutSeconds]);
    $stale = $staleStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($stale)) {
        wdLog('Sin jobs colgados en processing.');
        exit(0);
    }

    $requeued = 0;
    $failed = 0;

    foreach ($stale as $row) {
        $id = (int)$row['id'];
        $retry = ((int)($row['retry_count'] ?? 0)) + 1;
        $maxRetry = (int)($row['max_retries'] ?? 3);

        if ($retry < $maxRetry) {
            $backoff = wdDelaySeconds($retry);
            $upd = $db->prepare("
                UPDATE ai_transcription_queue
                SET status = 'pending',
                    retry_count = ?,
                    error_message = ?,
                    started_at = NOW()
                WHERE id = ? AND status = 'processing'
            ");
            $upd->execute([$retry, 'Watchdog: timeout en processing', $id]);
            if ($upd->rowCount() > 0) {
                $requeued++;
                wdLog("Reencolado queue_id=$id audio_id={$row['audio_id']} retry=$retry backoff={$backoff}s");
            }
        } else {
            $upd = $db->prepare("
                UPDATE ai_transcription_queue
                SET status = 'failed',
                    error_message = ?,
                    completed_at = NOW()
                WHERE id = ? AND status = 'processing'
            ");
            $upd->execute(['Watchdog: timeout en processing (max_retries alcanzado)', $id]);
            if ($upd->rowCount() > 0) {
                $failed++;
                wdLog("Marcado failed queue_id=$id audio_id={$row['audio_id']} por max_retries", 'ERROR');
            }
        }
    }

    wdLog("Resumen watchdog: requeued=$requeued failed=$failed total=" . count($stale));
} catch (Exception $e) {
    wdLog('Error fatal watchdog: ' . $e->getMessage(), 'ERROR');
    exit(1);
}

