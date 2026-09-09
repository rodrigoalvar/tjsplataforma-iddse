<?php
/**
 * Fachada del módulo HL7 Worklist: config snapshot, paths, helpers.
 */

require_once __DIR__ . '/Hl7OrmWorklistParser.php';

class Hl7WorklistModule
{
    public const MODULE_VERSION = '1.0.0';

    public static function moduleRoot(): string
    {
        return dirname(__DIR__);
    }

    public static function runtimeConfigPath(): string
    {
        return self::moduleRoot() . '/runtime/config.json';
    }

    public static function heartbeatPath(): string
    {
        return self::moduleRoot() . '/runtime/listener.heartbeat';
    }

    public static function defaultInboxPath(): string
    {
        $projectRoot = dirname(self::moduleRoot(), 2);
        return $projectRoot . '/uploads/inbox-hl7';
    }

    /**
     * Asegura columnas hl7_* en worklist_config (idempotente).
     */
    public static function ensureConfigColumns(PDO $db): void
    {
        $defaults = self::defaultInboxPath();
        self::addColumnIfMissing($db, 'worklist_config', 'hl7_enabled',
            "ALTER TABLE worklist_config ADD COLUMN hl7_enabled TINYINT(1) NOT NULL DEFAULT 0");
        self::addColumnIfMissing($db, 'worklist_config', 'hl7_port',
            "ALTER TABLE worklist_config ADD COLUMN hl7_port INT NOT NULL DEFAULT 2575");
        self::addColumnIfMissing($db, 'worklist_config', 'hl7_bind_host',
            "ALTER TABLE worklist_config ADD COLUMN hl7_bind_host VARCHAR(64) NOT NULL DEFAULT '0.0.0.0'");
        self::addColumnIfMissing($db, 'worklist_config', 'hl7_input_path',
            "ALTER TABLE worklist_config ADD COLUMN hl7_input_path VARCHAR(500) DEFAULT NULL");
        self::addColumnIfMissing($db, 'worklist_config', 'hl7_processed_path',
            "ALTER TABLE worklist_config ADD COLUMN hl7_processed_path VARCHAR(500) DEFAULT NULL");
        self::addColumnIfMissing($db, 'worklist_config', 'hl7_failed_path',
            "ALTER TABLE worklist_config ADD COLUMN hl7_failed_path VARCHAR(500) DEFAULT NULL");
        self::addColumnIfMissing($db, 'worklist_config', 'hl7_prestador_field',
            "ALTER TABLE worklist_config ADD COLUMN hl7_prestador_field VARCHAR(16) NOT NULL DEFAULT 'PV1-8'");
        self::addColumnIfMissing($db, 'worklist_config', 'hl7_spawn_worker_on_receive',
            "ALTER TABLE worklist_config ADD COLUMN hl7_spawn_worker_on_receive TINYINT(1) NOT NULL DEFAULT 1");
        self::addColumnIfMissing($db, 'worklist_config', 'worklist_ingest_mode',
            "ALTER TABLE worklist_config ADD COLUMN worklist_ingest_mode VARCHAR(16) NOT NULL DEFAULT 'none'");

        // Defaults de rutas si están NULL
        $stmt = $db->query("SELECT hl7_input_path, worklist_ingest_mode, pull_enabled, hl7_enabled FROM worklist_config WHERE id = 1");
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if ($row && empty($row['hl7_input_path'])) {
            $upd = $db->prepare("
                UPDATE worklist_config SET
                    hl7_input_path = ?,
                    hl7_processed_path = COALESCE(hl7_processed_path, ?),
                    hl7_failed_path = COALESCE(hl7_failed_path, ?)
                WHERE id = 1
            ");
            $upd->execute([
                $defaults,
                $defaults . '/processed',
                $defaults . '/failed',
            ]);
        }
        // Backfill modo desde flags si quedó en none con algún canal activo
        if ($row) {
            $mode = strtolower((string)($row['worklist_ingest_mode'] ?? 'none'));
            $t = (int)($row['pull_enabled'] ?? 0) === 1;
            $h = (int)($row['hl7_enabled'] ?? 0) === 1;
            if (($mode === '' || $mode === 'none') && ($t || $h)) {
                $derived = ($t && $h) ? 'both' : ($h ? 'hl7' : 'txt');
                $db->prepare('UPDATE worklist_config SET worklist_ingest_mode = ? WHERE id = 1')->execute([$derived]);
            }
        }
    }

    /**
     * Amplía ENUM source_type con HL7_MLLP si falta.
     */
    public static function ensureIngestSourceType(PDO $db): void
    {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM worklist_ingests LIKE 'source_type'");
            $col = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            if (!$col || empty($col['Type'])) {
                return;
            }
            $type = $col['Type'];
            if (stripos($type, 'HL7_MLLP') !== false) {
                return;
            }
            // Extraer valores actuales del ENUM
            if (preg_match("/^enum\((.*)\)$/i", $type, $m)) {
                $db->exec("ALTER TABLE worklist_ingests MODIFY COLUMN source_type ENUM('MANUAL_UI','PUSH_API','PULL_FOLDER','SYSTEM','HL7_MLLP') NOT NULL");
            }
        } catch (Exception $e) {
            error_log('Hl7WorklistModule::ensureIngestSourceType: ' . $e->getMessage());
        }
    }

    public static function ensureDirectories(?array $config = null): void
    {
        $inbox = rtrim((string)($config['hl7_input_path'] ?? self::defaultInboxPath()), '/');
        $processed = rtrim((string)($config['hl7_processed_path'] ?? ($inbox . '/processed')), '/');
        $failed = rtrim((string)($config['hl7_failed_path'] ?? ($inbox . '/failed')), '/');
        foreach ([$inbox, $processed, $failed, self::moduleRoot() . '/logs', self::moduleRoot() . '/runtime'] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
    }

    /**
     * Escribe runtime/config.json para el listener Node.
     */
    public static function writeConfigSnapshot(PDO $db): string
    {
        self::ensureConfigColumns($db);
        $stmt = $db->query('SELECT * FROM worklist_config WHERE id = 1 LIMIT 1');
        $cfg = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : [];
        if (!$cfg) {
            $cfg = [];
        }

        $inbox = rtrim((string)($cfg['hl7_input_path'] ?? self::defaultInboxPath()), '/');
        $snapshot = [
            'module_version' => self::MODULE_VERSION,
            'updated_at' => date('c'),
            'hl7_enabled' => (int)($cfg['hl7_enabled'] ?? 0),
            'hl7_port' => (int)($cfg['hl7_port'] ?? 2575),
            'hl7_bind_host' => (string)($cfg['hl7_bind_host'] ?? '0.0.0.0'),
            'hl7_input_path' => $inbox,
            'hl7_processed_path' => rtrim((string)($cfg['hl7_processed_path'] ?? ($inbox . '/processed')), '/'),
            'hl7_failed_path' => rtrim((string)($cfg['hl7_failed_path'] ?? ($inbox . '/failed')), '/'),
            'hl7_prestador_field' => (string)($cfg['hl7_prestador_field'] ?? 'PV1-8'),
            'hl7_spawn_worker_on_receive' => (int)($cfg['hl7_spawn_worker_on_receive'] ?? 1),
            'worklist_ingest_mode' => (string)($cfg['worklist_ingest_mode'] ?? 'none'),
            'php_bin' => self::resolveCliPhpBinary(),
            'process_one_script' => self::moduleRoot() . '/workers/hl7-process-one.php',
            'heartbeat_path' => self::heartbeatPath(),
        ];

        self::ensureDirectories($snapshot);
        $path = self::runtimeConfigPath();
        $json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (@file_put_contents($path, $json) === false) {
            throw new Exception('No se pudo escribir config snapshot: ' . $path);
        }
        return $path;
    }

    /**
     * Binario PHP CLI para spawn del worker (nunca php-fpm).
     */
    public static function resolveCliPhpBinary(): string
    {
        $candidates = [
            '/usr/bin/php',
            '/usr/bin/php8.1',
            '/usr/bin/php8.2',
            '/usr/bin/php8.3',
        ];
        $bin = PHP_BINARY ?: '';
        // Si el proceso actual ya es CLI (no fpm), preferirlo
        if ($bin !== '' && is_executable($bin) && stripos($bin, 'php-fpm') === false) {
            array_unshift($candidates, $bin);
        }
        foreach ($candidates as $c) {
            if ($c !== '' && is_executable($c) && stripos($c, 'php-fpm') === false) {
                return $c;
            }
        }
        return 'php';
    }

    public static function readConfigFromDb(PDO $db): array
    {
        self::ensureConfigColumns($db);
        $stmt = $db->query('SELECT * FROM worklist_config WHERE id = 1 LIMIT 1');
        $cfg = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        return $cfg ?: [];
    }

    public static function getPrestadorField(PDO $db): string
    {
        $cfg = self::readConfigFromDb($db);
        $f = strtoupper((string)($cfg['hl7_prestador_field'] ?? 'PV1-8'));
        return in_array($f, Hl7OrmWorklistParser::PRESTADOR_FIELDS, true) ? $f : 'PV1-8';
    }

    /**
     * ¿El listener está vivo? (heartbeat < 60s)
     */
    public static function isListenerAlive(): bool
    {
        $path = self::heartbeatPath();
        if (!is_file($path)) {
            return false;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return false;
        }
        $data = json_decode($raw, true);
        $ts = isset($data['ts']) ? (int)$data['ts'] : (int)@filemtime($path);
        return $ts > 0 && (time() - $ts) < 60;
    }

    private static function addColumnIfMissing(PDO $db, string $table, string $column, string $alterSql): void
    {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        if ((int)$stmt->fetchColumn() === 0) {
            $db->exec($alterSql);
        }
    }
}
