-- Script SQL para crear tablas de Worklist
-- Sistema TJSMEDICAL - Portal de Estudios Médicos

-- =====================================================
-- 1. TABLA WORKLIST
-- =====================================================

CREATE TABLE IF NOT EXISTS `worklist` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `accession_number` VARCHAR(64) UNIQUE NOT NULL,
  `patient_name` VARCHAR(255) NOT NULL,
  `patient_id` VARCHAR(64),
  `patient_birth_date` DATE,
  `patient_sex` ENUM('M','F','O'),
  `modality` VARCHAR(16),
  `referring_physician` VARCHAR(64),
  `equipment_name` VARCHAR(255),
  `scheduled_date` DATE NOT NULL,
  `scheduled_time` TIME NOT NULL,
  `procedure_description` VARCHAR(255),
  `reason_for_study` VARCHAR(255),
  `status` ENUM('pending','scheduled','in_progress','completed','cancelled') DEFAULT 'pending',
  `source_file` VARCHAR(255),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_accession` (`accession_number`),
  INDEX `idx_date_modality` (`scheduled_date`,`modality`),
  INDEX `idx_status` (`status`),
  INDEX `idx_scheduled_date` (`scheduled_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 2. TABLA WORKLIST_LOGS
-- =====================================================

CREATE TABLE IF NOT EXISTS `worklist_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `worklist_id` INT,
  `action` ENUM('CREATE','UPDATE','DELETE','IMPORT'),
  `user_id` INT,
  `source` VARCHAR(100),
  `changes` JSON,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_worklist_id` (`worklist_id`),
  INDEX `idx_action` (`action`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_created_at` (`created_at`),
  FOREIGN KEY (`worklist_id`) REFERENCES `worklist`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`user_id`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 3. TABLA WORKLIST_CONFIG
-- =====================================================

CREATE TABLE IF NOT EXISTS `worklist_config` (
  `id` INT PRIMARY KEY DEFAULT 1,
  `orthanc_worklist_path` VARCHAR(500) NOT NULL DEFAULT '/var/lib/orthanc/db/WorklistsDatabase',
  `orthanc_host` VARCHAR(255) DEFAULT 'localhost',
  `sftp_host` VARCHAR(255),
  `sftp_port` INT DEFAULT 22,
  `sftp_user` VARCHAR(255),
  `sftp_pass` VARCHAR(255),
  `sync_interval` INT DEFAULT 300,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `chk_single_config` CHECK (`id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insertar configuración inicial si no existe
INSERT INTO `worklist_config` (`id`, `orthanc_worklist_path`, `orthanc_host`, `sync_interval`)
VALUES (1, '/var/lib/orthanc/db/WorklistsDatabase', 'localhost', 300)
ON DUPLICATE KEY UPDATE `id` = `id`;
