<?php
/**
 * ¿Está permitido ingerir TXT de worklist según worklist_config?
 * CLI: php worklist-txt-ingest-allowed.php  → exit 0=sí, 1=no, 2=error
 * También usable como require: worklist_txt_ingest_allowed(PDO): bool
 */

require_once __DIR__ . '/../config/database.php';

if (!function_exists('worklist_txt_ingest_allowed')) {
    function worklist_txt_ingest_allowed(?PDO $db = null): bool
    {
        if (!$db) {
            $db = getDBConnection();
        }
        if (!$db) {
            return false;
        }
        $stmt = $db->query('SELECT worklist_ingest_mode, pull_enabled FROM worklist_config WHERE id = 1 LIMIT 1');
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        if (!$row) {
            return false;
        }
        $mode = strtolower(trim((string)($row['worklist_ingest_mode'] ?? '')));
        if ($mode === 'txt' || $mode === 'both') {
            return true;
        }
        if ($mode === 'hl7' || $mode === 'none') {
            return false;
        }
        // Compat: sin mode → pull_enabled
        return (int)($row['pull_enabled'] ?? 0) === 1;
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    try {
        exit(worklist_txt_ingest_allowed() ? 0 : 1);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(2);
    }
}
