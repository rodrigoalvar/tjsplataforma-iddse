<?php
/**
 * Auditoría de eliminaciones de estudios desde PACS Manager.
 */

class PacsStudyDeleteLog {

    public static function ensureTable(PDO $db): bool {
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS `pacs_study_delete_log` (
              `id` BIGINT NOT NULL AUTO_INCREMENT,
              `user_id` INT NULL,
              `orthanc_study_id` VARCHAR(255) NOT NULL,
              `study_instance_uid` VARCHAR(255) NULL,
              `patient_id_pacs` VARCHAR(100) NULL,
              `patient_name_pacs` VARCHAR(255) NULL,
              `impact_snapshot` JSON NULL,
              `severity` VARCHAR(16) NULL,
              `orthanc_delete_success` TINYINT(1) NOT NULL DEFAULT 0,
              `pacs_refs_cleared` INT NOT NULL DEFAULT 0,
              `error_message` TEXT NULL,
              `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_psdl_orthanc` (`orthanc_study_id`),
              KEY `idx_psdl_suid` (`study_instance_uid`),
              KEY `idx_psdl_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return true;
        } catch (Exception $e) {
            error_log('[PacsStudyDeleteLog] ensureTable: ' . $e->getMessage());
            return false;
        }
    }

    public static function create(PDO $db, array $row): ?int {
        if (!self::ensureTable($db)) {
            return null;
        }
        $impactJson = null;
        if (!empty($row['impact_snapshot']) && is_array($row['impact_snapshot'])) {
            $impactJson = json_encode($row['impact_snapshot'], JSON_UNESCAPED_UNICODE);
        }
        $sql = 'INSERT INTO pacs_study_delete_log (
            user_id, orthanc_study_id, study_instance_uid, patient_id_pacs, patient_name_pacs,
            impact_snapshot, severity, orthanc_delete_success, pacs_refs_cleared, error_message
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $row['user_id'] ?? null,
            $row['orthanc_study_id'],
            $row['study_instance_uid'] ?? null,
            $row['patient_id_pacs'] ?? null,
            $row['patient_name_pacs'] ?? null,
            $impactJson,
            $row['severity'] ?? null,
            !empty($row['orthanc_delete_success']) ? 1 : 0,
            (int) ($row['pacs_refs_cleared'] ?? 0),
            $row['error_message'] ?? null,
        ]);
        return (int) $db->lastInsertId();
    }
}
