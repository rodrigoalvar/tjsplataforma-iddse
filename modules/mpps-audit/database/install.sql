-- MPPS Audit — DDL idempotente (CREATE TABLE IF NOT EXISTS)
-- Sistema TJSMEDICAL — Portal de Estudios Médicos

CREATE TABLE IF NOT EXISTS `mpps_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `orthanc_mpps_id` VARCHAR(64) NOT NULL,
  `state` VARCHAR(32) NOT NULL DEFAULT 'UNKNOWN',
  `accession_number` VARCHAR(64) DEFAULT NULL,
  `patient_id` VARCHAR(64) DEFAULT NULL,
  `patient_name` VARCHAR(255) DEFAULT NULL,
  `modality` VARCHAR(16) DEFAULT NULL,
  `station_name` VARCHAR(64) DEFAULT NULL,
  `study_instance_uid` VARCHAR(128) DEFAULT NULL,
  `started_at` DATETIME DEFAULT NULL,
  `ended_at` DATETIME DEFAULT NULL,
  `worklist_id` INT DEFAULT NULL,
  `worklist_match` ENUM('matched','orphan','no_accession','unknown') NOT NULL DEFAULT 'unknown',
  `pacs_study_found` TINYINT(1) NOT NULL DEFAULT 0,
  `audit_status` ENUM('normal','orphan','ghost','no_show','pending_images','unknown') NOT NULL DEFAULT 'unknown',
  `raw_json` JSON DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_orthanc_mpps_id` (`orthanc_mpps_id`),
  KEY `idx_state` (`state`),
  KEY `idx_accession` (`accession_number`),
  KEY `idx_study_uid` (`study_instance_uid`),
  KEY `idx_audit_status` (`audit_status`),
  KEY `idx_started_at` (`started_at`),
  KEY `idx_worklist_id` (`worklist_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mpps_audit_config` (
  `id` INT NOT NULL DEFAULT 1,
  `poll_interval_seconds` INT NOT NULL DEFAULT 30,
  `ghost_threshold_minutes` INT NOT NULL DEFAULT 30,
  `use_mock_when_empty` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `chk_mpps_audit_config_single` CHECK (`id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `mpps_audit_config` (`id`, `poll_interval_seconds`, `ghost_threshold_minutes`, `use_mock_when_empty`)
VALUES (1, 30, 30, 1)
ON DUPLICATE KEY UPDATE `id` = `id`;

CREATE TABLE IF NOT EXISTS `mpps_poll_state` (
  `id` INT NOT NULL DEFAULT 1,
  `last_seq` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `last_poll_at` DATETIME DEFAULT NULL,
  `last_error` VARCHAR(512) DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `chk_mpps_poll_state_single` CHECK (`id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `mpps_poll_state` (`id`, `last_seq`)
VALUES (1, 0)
ON DUPLICATE KEY UPDATE `id` = `id`;
