<?php
/**
 * Worker stub: polling Orthanc /changes para eventos MPPS.
 *
 * Entrega 1: no realiza polling real; documenta el contrato.
 * Entrega 2: implementar lectura incremental + GET /mpps/{id} + upsert.
 *
 * Cron sugerido (entrega 2):
 *   * * * * * php /var/www/tjsiddse/modules/mpps-audit/workers/mpps-poll-orthanc.php
 *
 * Uso CLI: php mpps-poll-orthanc.php [--dry-run]
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(403);
    echo "Solo ejecución CLI\n";
    exit(1);
}

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../MppsAuditService.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);

echo "[mpps-poll] MPPS Audit worker stub v" . MppsAuditService::MODULE_VERSION . "\n";
echo "[mpps-poll] dry-run=" . ($dryRun ? 'yes' : 'no') . "\n";
echo "[mpps-poll] Entrega 1: sin polling real. Ver docs/INTEGRACION_ORTHANC.md\n";

try {
    $db = getDBConnection();
    if (!MppsAuditService::tableExists($db, 'mpps_poll_state')) {
        echo "[mpps-poll] ERROR: ejecutar install.php primero\n";
        exit(1);
    }
    $row = $db->query('SELECT last_seq, last_poll_at FROM mpps_poll_state WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    echo '[mpps-poll] last_seq=' . (int) ($row['last_seq'] ?? 0)
        . ' last_poll_at=' . ($row['last_poll_at'] ?? 'null') . "\n";
    echo "[mpps-poll] OK (stub)\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[mpps-poll] ' . $e->getMessage() . "\n");
    exit(1);
}
