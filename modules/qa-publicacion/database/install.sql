-- QA / Control de Calidad del Portal — tablas del módulo

CREATE TABLE IF NOT EXISTS `qa_config` (
  `config_key` VARCHAR(64) NOT NULL,
  `config_value` TEXT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_study_status` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `orthanc_id` VARCHAR(255) NULL COMMENT 'Orthanc study ID',
  `study_instance_uid` VARCHAR(255) NOT NULL,
  `patient_id_pacs` VARCHAR(100) NULL,
  `accession_number` VARCHAR(100) NULL,
  `estado` ENUM('pendiente','publicado','bloqueado') NOT NULL DEFAULT 'pendiente',
  `motivo` VARCHAR(255) NULL,
  `motivo_detalle` TEXT NULL,
  `revisado_por` INT NULL,
  `revisado_en` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_qa_study_uid` (`study_instance_uid`),
  KEY `idx_qa_study_orthanc` (`orthanc_id`),
  KEY `idx_qa_study_patient` (`patient_id_pacs`),
  KEY `idx_qa_study_estado` (`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_informe_status` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `informe_id` INT NOT NULL,
  `study_instance_uid` VARCHAR(255) NULL,
  `estado` ENUM('pendiente','publicado','bloqueado') NOT NULL DEFAULT 'pendiente',
  `bajado_de_pacs` TINYINT(1) NOT NULL DEFAULT 0,
  `motivo` VARCHAR(255) NULL,
  `motivo_detalle` TEXT NULL,
  `revisado_por` INT NULL,
  `revisado_en` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_qa_informe_id` (`informe_id`),
  KEY `idx_qa_informe_estado` (`estado`),
  KEY `idx_qa_informe_study_uid` (`study_instance_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_action_log` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `accion` VARCHAR(32) NOT NULL COMMENT 'publicar|bloquear|bajar_pacs|republicar',
  `target_type` ENUM('estudio','informe') NOT NULL,
  `orthanc_id` VARCHAR(255) NULL,
  `study_instance_uid` VARCHAR(255) NULL,
  `informe_id` INT NULL,
  `pacs_series_id` VARCHAR(255) NULL,
  `pacs_instance_id` VARCHAR(255) NULL,
  `usuario_id` INT NULL,
  `motivo` VARCHAR(255) NULL,
  `resultado` VARCHAR(32) NULL,
  `detalle` JSON NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_qa_log_created` (`created_at`),
  KEY `idx_qa_log_study_uid` (`study_instance_uid`),
  KEY `idx_qa_log_informe` (`informe_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `qa_config` (`config_key`, `config_value`) VALUES
  ('qa_enabled', '0'),
  ('qa_mode', 'lista_negra'),
  ('qa_hybrid_require_informe', '0'),
  ('qa_hybrid_block_mixed', '1'),
  ('qa_module_version', '1.0.0')
ON DUPLICATE KEY UPDATE `config_key` = `config_key`;
