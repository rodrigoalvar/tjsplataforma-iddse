<?php
/**
 * Servicio común de ingesta/sincronización de Worklist.
 */

require_once __DIR__ . '/TxtWorklistParser.php';
require_once __DIR__ . '/OrthancWorklistManager.php';

class WorklistIngestionService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function ensureSchema(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS `worklist_ingests` (
                `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
                `accession_number` VARCHAR(64) NULL,
                `fingerprint` VARCHAR(64) NOT NULL,
                `source_type` ENUM('MANUAL_UI','PUSH_API','PULL_FOLDER','SYSTEM','HL7_MLLP') NOT NULL,
                `source_detail` VARCHAR(255) NULL,
                `status` ENUM('created','updated','no_change','error','deleted') NOT NULL,
                `before_json` JSON NULL,
                `after_json` JSON NULL,
                `diff_json` JSON NULL,
                `error_message` TEXT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_worklist_ingests_fingerprint_source` (`fingerprint`, `source_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS `worklist_api_keys` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `key_hash` VARCHAR(255) NOT NULL,
                `allowed_ips` TEXT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `last_used_at` DATETIME NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_worklist_api_keys_name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->addColumnIfMissing('worklist', 'orthanc_worklist_id', "ALTER TABLE `worklist` ADD COLUMN `orthanc_worklist_id` VARCHAR(128) NULL");
        $this->addColumnIfMissing('worklist', 'orthanc_sync_status', "ALTER TABLE `worklist` ADD COLUMN `orthanc_sync_status` VARCHAR(20) NULL DEFAULT 'pending'");
        $this->addColumnIfMissing('worklist', 'orthanc_sync_error', "ALTER TABLE `worklist` ADD COLUMN `orthanc_sync_error` TEXT NULL");
        $this->addColumnIfMissing('worklist', 'orthanc_synced_at', "ALTER TABLE `worklist` ADD COLUMN `orthanc_synced_at` DATETIME NULL");
        $this->addColumnIfMissing('worklist', 'last_payload_hash', "ALTER TABLE `worklist` ADD COLUMN `last_payload_hash` VARCHAR(64) NULL");
        $this->addColumnIfMissing('worklist', 'pacs_study_at', "ALTER TABLE `worklist` ADD COLUMN `pacs_study_at` DATETIME NULL");
        $this->addColumnIfMissing('worklist', 'pacs_study_instance_uid', "ALTER TABLE `worklist` ADD COLUMN `pacs_study_instance_uid` VARCHAR(128) NULL");
        $this->addColumnIfMissing('worklist', 'pacs_seen_at', "ALTER TABLE `worklist` ADD COLUMN `pacs_seen_at` DATETIME NULL");
        $this->addColumnIfMissing('worklist', 'pacs_reconcile_last_at', "ALTER TABLE `worklist` ADD COLUMN `pacs_reconcile_last_at` DATETIME NULL");
        $this->addColumnIfMissing('worklist', 'pacs_reconcile_node_id', "ALTER TABLE `worklist` ADD COLUMN `pacs_reconcile_node_id` INT NULL");

        // Ampliar ENUM si la tabla ya existía sin HL7_MLLP
        $this->ensureHl7IngestSourceType();
    }

    /**
     * Ingesta mensaje HL7 ORM^O01 (módulo modules/hl7-worklist).
     */
    public function ingestHl7Content(
        string $hl7Content,
        string $sourceDetail,
        ?int $userId = null,
        bool $syncOrthanc = true,
        ?string $prestadorField = null
    ): array {
        $parserPath = __DIR__ . '/../modules/hl7-worklist/php/bootstrap.php';
        if (!is_file($parserPath)) {
            throw new Exception('Módulo hl7-worklist no instalado (falta php/bootstrap.php)');
        }
        require_once $parserPath;

        if ($prestadorField === null || $prestadorField === '') {
            $prestadorField = Hl7WorklistModule::getPrestadorField($this->db);
        }

        $data = Hl7OrmWorklistParser::parseContent($hl7Content, $prestadorField);
        if (!empty($sourceDetail)) {
            $data['source_file'] = basename($sourceDetail);
        }
        if (Hl7OrmWorklistParser::isCancelOrderControl($data['order_control'] ?? null)) {
            return $this->cancelFromHl7($data, $sourceDetail, $userId, $syncOrthanc);
        }
        return $this->upsertFromData($data, 'HL7_MLLP', $sourceDetail, $userId, $syncOrthanc);
    }

    /**
     * ORM^O01 con ORC-1 = CA|OC|DC: quitar de Orthanc y borrar fila local
     * para liberar el accession a un NW posterior.
     */
    public function cancelFromHl7(
        array $data,
        string $sourceDetail,
        ?int $userId = null,
        bool $syncOrthanc = true
    ): array {
        $accession = trim((string)($data['accession_number'] ?? ''));
        if ($accession === '') {
            throw new Exception('Cancelación HL7 sin accession_number');
        }

        $orderControl = strtoupper(trim((string)($data['order_control'] ?? 'CA')));
        $msgId = trim((string)($data['message_control_id'] ?? ''));
        $fingerprint = hash(
            'sha256',
            'HL7_CANCEL|' . $orderControl . '|' . $accession . '|' . $msgId . '|' . basename($sourceDetail)
        );

        if ($this->ingestAlreadyProcessed($fingerprint, 'HL7_MLLP')) {
            return [
                'status' => 'no_change',
                'message' => 'Cancelación HL7 ya procesada',
                'accession_number' => $accession,
                'fingerprint' => $fingerprint,
                'order_control' => $orderControl,
            ];
        }

        $existing = $this->getWorklistByAccession($accession);
        if (!$existing) {
            $this->registerIngest(
                $accession,
                $fingerprint,
                'HL7_MLLP',
                $sourceDetail,
                'deleted',
                null,
                null,
                ['order_control' => $orderControl, 'result' => 'not_found'],
                null
            );
            return [
                'status' => 'deleted',
                'message' => 'Cancelación HL7: accession no estaba en worklist',
                'accession_number' => $accession,
                'fingerprint' => $fingerprint,
                'order_control' => $orderControl,
                'found' => false,
            ];
        }

        $orthancError = null;
        if ($syncOrthanc) {
            try {
                $this->removeFromOrthanc($existing);
            } catch (Throwable $e) {
                $orthancError = $e->getMessage();
                error_log('HL7 cancel removeFromOrthanc: ' . $orthancError);
            }
        }

        $stmt = $this->db->prepare('DELETE FROM worklist WHERE accession_number = ?');
        $stmt->execute([$accession]);

        $this->registerIngest(
            $accession,
            $fingerprint,
            'HL7_MLLP',
            $sourceDetail,
            'deleted',
            $existing,
            null,
            [
                'order_control' => $orderControl,
                'message_control_id' => $msgId !== '' ? $msgId : null,
                'orthanc_removed' => $syncOrthanc,
                'orthanc_error' => $orthancError,
            ],
            $orthancError
        );

        return [
            'status' => 'deleted',
            'message' => 'Worklist cancelado por HL7 ORC|' . $orderControl,
            'accession_number' => $accession,
            'fingerprint' => $fingerprint,
            'order_control' => $orderControl,
            'found' => true,
            'orthanc_error' => $orthancError,
            'data' => $existing,
        ];
    }

    private function ensureHl7IngestSourceType(): void
    {
        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM worklist_ingests LIKE 'source_type'");
            $col = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            if (!$col || empty($col['Type'])) {
                return;
            }
            if (stripos($col['Type'], 'HL7_MLLP') !== false) {
                return;
            }
            $this->db->exec(
                "ALTER TABLE worklist_ingests MODIFY COLUMN source_type ENUM('MANUAL_UI','PUSH_API','PULL_FOLDER','SYSTEM','HL7_MLLP') NOT NULL"
            );
        } catch (Exception $e) {
            error_log('WorklistIngestionService::ensureHl7IngestSourceType: ' . $e->getMessage());
        }
    }

    public function ingestTxtContent(
        string $txtContent,
        string $sourceType,
        string $sourceDetail,
        ?int $userId = null,
        bool $syncOrthanc = true
    ): array {
        $data = TxtWorklistParser::parseContent($txtContent);
        TxtWorklistParser::validate($data);
        return $this->upsertFromData($data, $sourceType, $sourceDetail, $userId, $syncOrthanc);
    }

    public function upsertFromData(
        array $data,
        string $sourceType,
        string $sourceDetail,
        ?int $userId = null,
        bool $syncOrthanc = true
    ): array {
        if (empty($data['accession_number'])) {
            throw new Exception('accession_number es requerido');
        }

        $normalized = $this->normalizePayload($data);
        $fingerprint = hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE));
        $accession = $normalized['accession_number'];

        // Clave de no-duplicación: accession_number (UNIQUE). TXT y HL7 alimentan la misma fila.
        $existing = $this->getWorklistByAccession($accession);
        $before = $existing ?: null;
        $beforeHash = $existing['last_payload_hash'] ?? null;

        if ($existing && $beforeHash && hash_equals($beforeHash, $fingerprint)) {
            $this->registerIngest(
                $accession,
                $fingerprint,
                $sourceType,
                $sourceDetail,
                'no_change',
                $before,
                $before,
                [],
                null
            );
            return [
                'status' => 'no_change',
                'message' => 'Accession ya en worklist con el mismo contenido (sin duplicar)',
                'accession_number' => $accession,
                'fingerprint' => $fingerprint,
                'data' => $existing,
            ];
        }

        if ($this->ingestAlreadyProcessed($fingerprint, $sourceType)) {
            return [
                'status' => 'no_change',
                'message' => 'Evento ya procesado para esta fuente',
                'accession_number' => $accession,
                'fingerprint' => $fingerprint
            ];
        }

        $this->db->beginTransaction();
        try {
            if ($existing) {
                $this->updateWorklist($normalized, $fingerprint, $accession);
            } else {
                try {
                    $this->insertWorklist($normalized, $fingerprint);
                } catch (PDOException $dupEx) {
                    // Carrera TXT+HL7 concurrente: UNIQUE(accession_number) → actualizar
                    if (stripos($dupEx->getMessage(), 'Duplicate') !== false) {
                        $this->updateWorklist($normalized, $fingerprint, $accession);
                        $existing = $this->getWorklistByAccession($accession) ?: true;
                        $before = is_array($existing) ? $existing : $before;
                    } else {
                        throw $dupEx;
                    }
                }
            }

            $after = $this->getWorklistByAccession($accession);
            if (!$after) {
                throw new Exception('No se pudo recuperar worklist después de guardar');
            }

            $diff = $this->buildDiff($before, $after);
            $status = $existing ? 'updated' : 'created';

            $this->registerIngest(
                $accession,
                $fingerprint,
                $sourceType,
                $sourceDetail,
                $status,
                $before,
                $after,
                $diff,
                null
            );

            $this->registerActionLog(
                (int)$after['id'],
                $existing ? 'UPDATE' : 'CREATE',
                $userId,
                $sourceType . ':' . $sourceDetail,
                [
                    'before' => $before,
                    'after' => $after,
                    'diff' => $diff
                ]
            );

            $orthanc = null;
            if ($syncOrthanc) {
                try {
                    $orthanc = $this->syncOneToOrthanc($after);
                } catch (Throwable $syncEx) {
                    // No revertir la ingesta en BD si solo falla la copia a Orthanc (permisos/ruta/red)
                    error_log('Worklist Orthanc sync: ' . $syncEx->getMessage());
                    $stmtErr = $this->db->prepare("
                        UPDATE worklist
                        SET orthanc_sync_status = 'error',
                            orthanc_sync_error = ?,
                            orthanc_synced_at = NULL
                        WHERE id = ?
                    ");
                    $stmtErr->execute([$syncEx->getMessage(), $after['id']]);
                    $orthanc = ['error' => $syncEx->getMessage()];
                }
            }

            $this->db->commit();

            $afterReload = $this->getWorklistByAccession($accession) ?: $after;

            return [
                'status' => $status,
                'message' => $existing ? 'Worklist actualizado' : 'Worklist creado',
                'data' => $afterReload,
                'diff' => $diff,
                'fingerprint' => $fingerprint,
                'orthanc' => $orthanc,
                'accession_number' => $accession,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->registerIngest(
                $accession,
                $fingerprint,
                $sourceType,
                $sourceDetail,
                'error',
                $before,
                null,
                [],
                $e->getMessage()
            );
            throw $e;
        }
    }

    public function syncOneToOrthanc(array $item): array
    {
        $config = $this->getWorklistConfig();
        $mode = strtolower((string)($config['orthanc_worklist_mode'] ?? 'filesystem'));

        // Modo REST: evitar duplicados en Orthanc al re-sincronizar (edición / sync masivo).
        if ($mode === 'rest' && !empty($item['orthanc_worklist_id'])) {
            try {
                $this->removeFromOrthanc($item);
            } catch (Throwable $ex) {
                error_log('Worklist Orthanc remove antes de re-publicar: ' . $ex->getMessage());
            }
        }

        $result = OrthancWorklistManager::publish($item, $config);
        $stmt = $this->db->prepare("
            UPDATE worklist
            SET orthanc_worklist_id = :orthanc_worklist_id,
                orthanc_sync_status = 'ok',
                orthanc_sync_error = NULL,
                orthanc_synced_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':orthanc_worklist_id' => $result['orthanc_worklist_id'] ?? null,
            ':id' => $item['id']
        ]);
        return $result;
    }

    public function removeFromOrthanc(array $item): void
    {
        $config = $this->getWorklistConfig();
        OrthancWorklistManager::remove($item, $config);
    }

    public function getWorklistConfig(): array
    {
        $stmt = $this->db->query("SELECT * FROM worklist_config WHERE id = 1");
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$config) {
            throw new Exception('Configuración de Worklist no encontrada');
        }
        return $config;
    }

    /**
     * TXTs de muchos RMS vienen en ISO-8859-1 / Windows-1252; con conexión utf8mb4
     * bytes como 0xD1 (Ñ en Latin-1) provocan SQLSTATE 1366 si no se convierten.
     */
    private function ensureUtf8ForDb(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        $utf8 = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
        if ($utf8 !== false && mb_check_encoding($utf8, 'UTF-8')) {
            return $utf8;
        }
        $utf8 = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        return $utf8 !== false ? $utf8 : $value;
    }

    private function normalizePayload(array $data): array
    {
        $allowed = [
            'accession_number', 'patient_name', 'patient_id', 'patient_birth_date', 'patient_sex',
            'modality', 'referring_physician', 'equipment_name', 'scheduled_date', 'scheduled_time',
            'procedure_description', 'reason_for_study', 'status', 'source_file'
        ];
        $normalized = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $value = is_string($data[$field]) ? trim($data[$field]) : $data[$field];
                if (is_string($value)) {
                    $value = $this->ensureUtf8ForDb($value);
                }
                $normalized[$field] = $value === '' ? null : $value;
            }
        }
        if (empty($normalized['status'])) {
            $normalized['status'] = 'pending';
        }
        return $normalized;
    }

    private function getWorklistByAccession(string $accession): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM worklist WHERE accession_number = ?");
        $stmt->execute([$accession]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function insertWorklist(array $data, string $fingerprint): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO worklist (
                accession_number, patient_name, patient_id, patient_birth_date, patient_sex,
                modality, referring_physician, equipment_name, scheduled_date, scheduled_time,
                procedure_description, reason_for_study, status, source_file, last_payload_hash,
                orthanc_sync_status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([
            $data['accession_number'],
            $data['patient_name'] ?? null,
            $data['patient_id'] ?? null,
            $data['patient_birth_date'] ?? null,
            $data['patient_sex'] ?? null,
            $data['modality'] ?? null,
            $data['referring_physician'] ?? null,
            $data['equipment_name'] ?? null,
            $data['scheduled_date'],
            $data['scheduled_time'],
            $data['procedure_description'] ?? null,
            $data['reason_for_study'] ?? null,
            $data['status'] ?? 'pending',
            $data['source_file'] ?? null,
            $fingerprint
        ]);
    }

    private function updateWorklist(array $data, string $fingerprint, string $accession): void
    {
        $fields = [
            'patient_name', 'patient_id', 'patient_birth_date', 'patient_sex',
            'modality', 'referring_physician', 'equipment_name', 'scheduled_date',
            'scheduled_time', 'procedure_description', 'reason_for_study', 'status', 'source_file'
        ];
        $sets = [];
        $bindings = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "$field = ?";
                $bindings[] = $data[$field];
            }
        }
        $sets[] = "last_payload_hash = ?";
        $bindings[] = $fingerprint;
        $sets[] = "orthanc_sync_status = 'pending'";
        $sets[] = "orthanc_sync_error = NULL";

        $bindings[] = $accession;
        $sql = "UPDATE worklist SET " . implode(', ', $sets) . " WHERE accession_number = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($bindings);
    }

    private function ingestAlreadyProcessed(string $fingerprint, string $sourceType): bool
    {
        $stmt = $this->db->prepare("
            SELECT id FROM worklist_ingests
            WHERE fingerprint = ? AND source_type = ? AND status IN ('created','updated','no_change','deleted')
            LIMIT 1
        ");
        $stmt->execute([$fingerprint, $sourceType]);
        return (bool)$stmt->fetchColumn();
    }

    private function registerIngest(
        ?string $accession,
        string $fingerprint,
        string $sourceType,
        string $sourceDetail,
        string $status,
        ?array $before,
        ?array $after,
        array $diff,
        ?string $errorMessage
    ): void {
        $stmt = $this->db->prepare("
            INSERT INTO worklist_ingests (
                accession_number, fingerprint, source_type, source_detail, status,
                before_json, after_json, diff_json, error_message
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                source_detail = VALUES(source_detail),
                before_json = VALUES(before_json),
                after_json = VALUES(after_json),
                diff_json = VALUES(diff_json),
                error_message = VALUES(error_message)
        ");
        $stmt->execute([
            $accession,
            $fingerprint,
            $sourceType,
            $sourceDetail,
            $status,
            $before ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
            $after ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
            !empty($diff) ? json_encode($diff, JSON_UNESCAPED_UNICODE) : null,
            $errorMessage
        ]);
    }

    private function registerActionLog(
        int $worklistId,
        string $action,
        ?int $userId,
        string $source,
        array $changes
    ): void {
        $stmt = $this->db->prepare("
            INSERT INTO worklist_logs (worklist_id, action, user_id, source, changes)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $worklistId,
            $action,
            $userId,
            $source,
            json_encode($changes, JSON_UNESCAPED_UNICODE)
        ]);
    }

    private function buildDiff(?array $before, ?array $after): array
    {
        if (!$before || !$after) {
            return [];
        }
        $fields = [
            'patient_name', 'patient_id', 'patient_birth_date', 'patient_sex',
            'modality', 'referring_physician', 'equipment_name', 'scheduled_date',
            'scheduled_time', 'procedure_description', 'reason_for_study', 'status'
        ];
        $diff = [];
        foreach ($fields as $field) {
            $from = $before[$field] ?? null;
            $to = $after[$field] ?? null;
            if ((string)$from !== (string)$to) {
                $diff[$field] = ['from' => $from, 'to' => $to];
            }
        }
        return $diff;
    }

    private function addColumnIfMissing(string $table, string $column, string $alterSql): void
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
        ");
        $stmt->execute([
            ':table_name' => $table,
            ':column_name' => $column
        ]);
        $exists = (int)$stmt->fetchColumn() > 0;
        if (!$exists) {
            $this->db->exec($alterSql);
        }
    }

    /**
     * Compara la tabla worklist del portal con Orthanc (REST o carpeta .wl).
     *
     * @return array{
     *   mode: string,
     *   orthanc_count: int,
     *   bd_rows: int,
     *   aligned_count: int,
     *   aligned: array,
     *   issues: array,
     *   issue_count: int,
     *   in_sync: bool,
     *   generated_at: string
     * }
     */
    public function reconcileWithOrthanc(): array
    {
        $config = $this->getWorklistConfig();
        $mode = strtolower((string)($config['orthanc_worklist_mode'] ?? 'filesystem'));

        $stmt = $this->db->query("
            SELECT id, accession_number, orthanc_worklist_id, orthanc_sync_status, orthanc_sync_error
            FROM worklist
            ORDER BY accession_number
        ");
        $bdRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($mode === 'rest') {
            $remote = OrthancWorklistManager::listRestWorklistsWithAccessions($config);
            return $this->buildReconcileReportRest($bdRows, $remote);
        }

        $files = OrthancWorklistManager::listFilesystemWorklistAccessions($config);
        return $this->buildReconcileReportFilesystem($bdRows, $files);
    }

    private function buildReconcileReportRest(array $bdRows, array $remote): array
    {
        $remoteById = [];
        foreach ($remote as $r) {
            $remoteById[$r['id']] = (string)($r['accession'] ?? '');
        }

        $aligned = [];
        $issues = [];

        foreach ($remote as $r) {
            $id = $r['id'];
            $linked = false;
            foreach ($bdRows as $br) {
                if (trim((string)($br['orthanc_worklist_id'] ?? '')) === $id) {
                    $linked = true;
                    break;
                }
            }
            if (!$linked) {
                $issues[] = [
                    'type' => 'orphan_in_orthanc',
                    'accession_number' => (string)($r['accession'] ?? ''),
                    'orthanc_worklist_id' => $id,
                    'detail' => 'Entrada en Orthanc que ninguna fila del portal referencia por ID.',
                ];
            }
        }

        foreach ($bdRows as $row) {
            $acc = (string)$row['accession_number'];
            $rid = trim((string)($row['orthanc_worklist_id'] ?? ''));
            $st = (string)($row['orthanc_sync_status'] ?? '');

            if ($st === 'ok' && $rid !== '') {
                if (!isset($remoteById[$rid])) {
                    $issues[] = [
                        'type' => 'bd_ok_missing_in_orthanc',
                        'accession_number' => $acc,
                        'orthanc_worklist_id' => $rid,
                        'detail' => 'La BD marca sincronizado pero ese UUID no existe en Orthanc.',
                    ];
                } else {
                    $rAcc = $remoteById[$rid];
                    if ($rAcc !== '' && $rAcc !== $acc) {
                        $issues[] = [
                            'type' => 'accession_mismatch',
                            'accession_number' => $acc,
                            'orthanc_worklist_id' => $rid,
                            'detail' => 'Accession en Orthanc (' . $rAcc . ') distinto al portal (' . $acc . ').',
                        ];
                    } else {
                        $aligned[] = [
                            'accession_number' => $acc,
                            'orthanc_worklist_id' => $rid,
                        ];
                    }
                }
            } elseif ($st === 'ok' && $rid === '') {
                $issues[] = [
                    'type' => 'bd_ok_without_remote_id',
                    'accession_number' => $acc,
                    'detail' => 'orthanc_sync_status=ok sin orthanc_worklist_id (anómalo en modo REST).',
                ];
            }
        }

        return [
            'mode' => 'rest',
            'orthanc_count' => count($remote),
            'bd_rows' => count($bdRows),
            'aligned_count' => count($aligned),
            'aligned' => $aligned,
            'issues' => $issues,
            'issue_count' => count($issues),
            'in_sync' => $issues === [],
            'generated_at' => date('c'),
        ];
    }

    /**
     * @param array<string, true> $filesSet
     */
    private function buildReconcileReportFilesystem(array $bdRows, array $filesSet): array
    {
        $aligned = [];
        $issues = [];

        foreach ($bdRows as $row) {
            $acc = (string)$row['accession_number'];
            $st = (string)($row['orthanc_sync_status'] ?? '');
            $exists = isset($filesSet[$acc]);

            if ($st === 'ok') {
                if (!$exists) {
                    $issues[] = [
                        'type' => 'bd_ok_missing_file',
                        'accession_number' => $acc,
                        'detail' => 'Marcado ok en BD pero no existe ' . $acc . '.wl en el directorio de Orthanc.',
                    ];
                } else {
                    $aligned[] = ['accession_number' => $acc];
                }
            }
        }

        foreach (array_keys($filesSet) as $acc) {
            $inBd = false;
            foreach ($bdRows as $r) {
                if ((string)$r['accession_number'] === $acc) {
                    $inBd = true;
                    break;
                }
            }
            if (!$inBd) {
                $issues[] = [
                    'type' => 'orphan_file_in_orthanc_dir',
                    'accession_number' => $acc,
                    'detail' => 'Archivo .wl en el directorio sin fila en el portal con ese accession.',
                ];
            }
        }

        return [
            'mode' => 'filesystem',
            'orthanc_count' => count($filesSet),
            'bd_rows' => count($bdRows),
            'aligned_count' => count($aligned),
            'aligned' => $aligned,
            'issues' => $issues,
            'issue_count' => count($issues),
            'in_sync' => $issues === [],
            'generated_at' => date('c'),
        ];
    }
}
