-- Auditoría y trazabilidad de modificaciones de estudios en PACS Manager
-- Ejecutar una vez en la BD de la aplicación (idempotente con IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS `pacs_study_modify_log` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `user_id` INT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending'
    COMMENT 'pending|orthanc_running|orthanc_success|reconciling|success|partial|failed',
  `orthanc_job_id` VARCHAR(64) NULL,
  `modify_endpoint` VARCHAR(32) NULL COMMENT 'studies|patients|studies_two_step',
  `tags_requested` JSON NULL,
  `delete_original_requested` TINYINT(1) NOT NULL DEFAULT 1,
  `old_orthanc_study_id` VARCHAR(255) NOT NULL,
  `old_study_instance_uid` VARCHAR(255) NULL,
  `new_orthanc_study_id` VARCHAR(255) NULL,
  `new_study_instance_uid` VARCHAR(255) NULL,
  `estudios_id` INT NULL COMMENT 'PK local estudios si existía',
  `patient_id_pacs` VARCHAR(100) NULL,
  `patient_name_pacs` VARCHAR(255) NULL,
  `accession_number` VARCHAR(100) NULL,
  `modality` VARCHAR(16) NULL,
  `reconcile_status` VARCHAR(32) NULL COMMENT 'pending|success|partial|failed|skipped',
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
  KEY `idx_psml_new_orthanc` (`new_orthanc_study_id`),
  KEY `idx_psml_created` (`created_at`),
  KEY `idx_psml_job` (`orthanc_job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pacs_study_modify_log_details` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `log_id` BIGINT NOT NULL,
  `table_name` VARCHAR(64) NOT NULL,
  `column_name` VARCHAR(64) NOT NULL,
  `rows_updated` INT NOT NULL DEFAULT 0,
  `error_message` VARCHAR(512) NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_psmld_log` (`log_id`),
  CONSTRAINT `fk_psmld_log` FOREIGN KEY (`log_id`) REFERENCES `pacs_study_modify_log` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
