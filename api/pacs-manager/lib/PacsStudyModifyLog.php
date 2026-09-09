<?php
/**
 * Registro de modificaciones de estudios (PACS Manager).
 */

class PacsStudyModifyLog {

    public static function ensureTables(PDO $db): bool {
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS `pacs_study_modify_log` (
              `id` BIGINT NOT NULL AUTO_INCREMENT,
              `user_id` INT NULL,
              `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
              `orthanc_job_id` VARCHAR(64) NULL,
              `modify_endpoint` VARCHAR(32) NULL,
              `tags_requested` JSON NULL,
              `delete_original_requested` TINYINT(1) NOT NULL DEFAULT 1,
              `old_orthanc_study_id` VARCHAR(255) NOT NULL,
              `old_study_instance_uid` VARCHAR(255) NULL,
              `new_orthanc_study_id` VARCHAR(255) NULL,
              `new_study_instance_uid` VARCHAR(255) NULL,
              `estudios_id` INT NULL,
              `patient_id_pacs` VARCHAR(100) NULL,
              `patient_name_pacs` VARCHAR(255) NULL,
              `accession_number` VARCHAR(100) NULL,
              `modality` VARCHAR(16) NULL,
              `reconcile_status` VARCHAR(32) NULL,
              `reconcile_summary` JSON NULL,
              `reconcile_error` TEXT NULL,
              `original_deleted` TINYINT(1) NULL,
              `original_delete_error` TEXT NULL,
              `error_message` TEXT NULL,
              `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `completed_at` TIMESTAMP NULL,
              PRIMARY KEY (`id`),
              KEY `idx_psml_status` (`status`),
              KEY `idx_psml_old_orthanc` (`old_orthanc_study_id`),
              KEY `idx_psml_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS `pacs_study_modify_log_details` (
              `id` BIGINT NOT NULL AUTO_INCREMENT,
              `log_id` BIGINT NOT NULL,
              `table_name` VARCHAR(64) NOT NULL,
              `column_name` VARCHAR(64) NOT NULL,
              `rows_updated` INT NOT NULL DEFAULT 0,
              `error_message` VARCHAR(512) NULL,
              `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_psmld_log` (`log_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return true;
        } catch (Exception $e) {
            error_log('[PacsStudyModifyLog] ensureTables: ' . $e->getMessage());
            return false;
        }
    }

    public static function create(PDO $db, array $row): ?int {
        if (!self::ensureTables($db)) {
            return null;
        }
        $tagsJson = null;
        if (!empty($row['tags_requested']) && is_array($row['tags_requested'])) {
            $tagsJson = json_encode($row['tags_requested'], JSON_UNESCAPED_UNICODE);
        }
        $sql = "INSERT INTO pacs_study_modify_log (
            user_id, status, old_orthanc_study_id, old_study_instance_uid,
            estudios_id, patient_id_pacs, patient_name_pacs, accession_number, modality,
            tags_requested, delete_original_requested
        ) VALUES (?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $row['user_id'] ?? null,
            $row['old_orthanc_study_id'],
            $row['old_study_instance_uid'] ?? null,
            $row['estudios_id'] ?? null,
            $row['patient_id_pacs'] ?? null,
            $row['patient_name_pacs'] ?? null,
            $row['accession_number'] ?? null,
            $row['modality'] ?? null,
            $tagsJson,
            !empty($row['delete_original_requested']) ? 1 : 0,
        ]);
        return (int) $db->lastInsertId();
    }

    public static function update(PDO $db, int $logId, array $fields): bool {
        if ($logId <= 0) {
            return false;
        }
        $allowed = [
            'status', 'orthanc_job_id', 'modify_endpoint', 'new_orthanc_study_id', 'new_study_instance_uid',
            'reconcile_status', 'reconcile_summary', 'reconcile_error', 'original_deleted', 'original_delete_error',
            'error_message', 'completed_at',
        ];
        $sets = [];
        $params = [];
        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            if ($key === 'reconcile_summary' && is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $sets[] = "`$key` = ?";
            $params[] = $value;
        }
        if (empty($sets)) {
            return false;
        }
        $params[] = $logId;
        $stmt = $db->prepare('UPDATE pacs_study_modify_log SET ' . implode(', ', $sets) . ' WHERE id = ?');
        return $stmt->execute($params);
    }

    public static function getById(PDO $db, int $logId): ?array {
        if (!self::ensureTables($db)) {
            return null;
        }
        $stmt = $db->prepare('SELECT * FROM pacs_study_modify_log WHERE id = ? LIMIT 1');
        $stmt->execute([$logId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function saveDetails(PDO $db, int $logId, array $details): void {
        if ($logId <= 0 || empty($details)) {
            return;
        }
        self::ensureTables($db);
        try {
            $db->prepare('DELETE FROM pacs_study_modify_log_details WHERE log_id = ?')->execute([$logId]);
        } catch (Exception $e) {
            // ignore
        }
        self::insertDetails($db, $logId, $details);
    }

    /** Reemplaza solo filas de reconciliación de metadatos (column_name metadata:*). */
    public static function replaceMetadataDetails(PDO $db, int $logId, array $metadataDetails): void {
        if ($logId <= 0) {
            return;
        }
        self::ensureTables($db);
        try {
            $db->prepare(
                "DELETE FROM pacs_study_modify_log_details WHERE log_id = ? AND column_name LIKE 'metadata%'"
            )->execute([$logId]);
        } catch (Exception $e) {
            // ignore
        }
        if (!empty($metadataDetails)) {
            self::insertDetails($db, $logId, $metadataDetails);
        }
    }

    /** Reemplaza solo filas de re-vinculación DOC (column_name doc:*). */
    public static function replaceDocDetails(PDO $db, int $logId, array $docDetails): void {
        if ($logId <= 0) {
            return;
        }
        self::ensureTables($db);
        try {
            $db->prepare(
                "DELETE FROM pacs_study_modify_log_details WHERE log_id = ? AND column_name LIKE 'doc:%'"
            )->execute([$logId]);
        } catch (Exception $e) {
            // ignore
        }
        if (!empty($docDetails)) {
            self::insertDetails($db, $logId, $docDetails);
        }
    }

    private static function insertDetails(PDO $db, int $logId, array $details): void {
        $stmt = $db->prepare(
            'INSERT INTO pacs_study_modify_log_details (log_id, table_name, column_name, rows_updated, error_message)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($details as $d) {
            $tableName = mb_substr((string) ($d['table_name'] ?? ''), 0, 64);
            $columnName = mb_substr((string) ($d['column_name'] ?? ''), 0, 64);
            $errorMsg = isset($d['error_message']) ? mb_substr((string) $d['error_message'], 0, 512) : null;
            $stmt->execute([
                $logId,
                $tableName,
                $columnName,
                (int) ($d['rows_updated'] ?? 0),
                $errorMsg,
            ]);
        }
    }

    public static function listRecent(
        PDO $db,
        int $limit = 50,
        int $offset = 0,
        ?string $statusFilter = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        if (!self::ensureTables($db)) {
            return ['items' => [], 'total' => 0];
        }
        $where = '1=1';
        $params = [];
        if ($statusFilter) {
            $where .= ' AND l.status = ?';
            $params[] = $statusFilter;
        }
        if ($dateFrom) {
            $where .= ' AND DATE(l.created_at) >= ?';
            $params[] = $dateFrom;
        }
        if ($dateTo) {
            $where .= ' AND DATE(l.created_at) <= ?';
            $params[] = $dateTo;
        }
        $countStmt = $db->prepare("SELECT COUNT(*) FROM pacs_study_modify_log l WHERE $where");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $params[] = $limit;
        $params[] = $offset;
        $stmt = $db->prepare(
            "SELECT l.*, u.nombre AS user_nombre, u.apellido AS user_apellido
             FROM pacs_study_modify_log l
             LEFT JOIN usuarios u ON u.id = l.user_id
             WHERE $where
             ORDER BY l.created_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute($params);
        return ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    /**
     * Buscar registros de auditoría por StudyInstanceUID (viejo o nuevo).
     * Usado por Cross Sync para detectar pares editados en PACS local.
     *
     * @param string[] $uids
     */
    public static function findByStudyUids(
        PDO $db,
        array $uids,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        int $limit = 500
    ): array {
        if (!self::ensureTables($db)) {
            return [];
        }
        $uids = array_values(array_unique(array_filter(array_map(static function ($u) {
            return trim((string) $u);
        }, $uids))));
        if (empty($uids)) {
            return [];
        }
        if (count($uids) > 500) {
            $uids = array_slice($uids, 0, 500);
        }

        $placeholders = implode(',', array_fill(0, count($uids), '?'));
        $params = array_merge($uids, $uids);
        $where = "(l.old_study_instance_uid IN ($placeholders) OR l.new_study_instance_uid IN ($placeholders))";
        $where .= " AND l.status NOT IN ('failed', 'pending')";

        if ($dateFrom !== null && $dateFrom !== '') {
            $where .= ' AND DATE(l.created_at) >= ?';
            $params[] = $dateFrom;
        }
        if ($dateTo !== null && $dateTo !== '') {
            $where .= ' AND DATE(l.created_at) <= ?';
            $params[] = $dateTo;
        }

        $params[] = max(1, min(500, $limit));

        $stmt = $db->prepare(
            "SELECT l.id, l.status, l.old_orthanc_study_id, l.old_study_instance_uid,
                    l.new_orthanc_study_id, l.new_study_instance_uid,
                    l.patient_id_pacs, l.patient_name_pacs, l.accession_number, l.modality,
                    l.tags_requested, l.original_deleted, l.delete_original_requested,
                    l.created_at, l.completed_at,
                    u.nombre AS user_nombre, u.apellido AS user_apellido
             FROM pacs_study_modify_log l
             LEFT JOIN usuarios u ON u.id = l.user_id
             WHERE $where
             ORDER BY l.created_at DESC
             LIMIT ?"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            if (!empty($row['tags_requested']) && is_string($row['tags_requested'])) {
                $decoded = json_decode($row['tags_requested'], true);
                $row['tags_requested'] = is_array($decoded) ? $decoded : null;
            }
        }
        unset($row);
        return $rows;
    }
}
