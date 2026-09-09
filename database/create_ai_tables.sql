-- Tablas para AI Informes (Whisper + Medgemma)
-- Sistema TJSMEDICAL - Portal de Estudios Médicos
-- Base de datos: tjsmedical_iddse

USE tjsmedical_iddse;

-- Tabla de transcripciones de audio (Whisper)
CREATE TABLE IF NOT EXISTS `ai_transcriptions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `study_id` INT NOT NULL,
  `audio_file_path` VARCHAR(500) NOT NULL,
  `transcription_text` TEXT,
  `status` ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
  `error_message` TEXT,
  `model_used` VARCHAR(50) DEFAULT 'whisper',
  `processing_time` DECIMAL(10,2),
  `created_by` INT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_study_id` (`study_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_created_at` (`created_at`),
  FOREIGN KEY (`study_id`) REFERENCES `estudios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de informes generados por AI (Medgemma)
CREATE TABLE IF NOT EXISTS `ai_reports` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `study_id` INT NOT NULL,
  `transcription_id` INT,
  `template_id` INT,
  `report_content` TEXT NOT NULL,
  `status` ENUM('draft', 'completed', 'approved', 'rejected') DEFAULT 'draft',
  `model_used` VARCHAR(50) DEFAULT 'medgemma',
  `prompt_used` TEXT,
  `processing_time` DECIMAL(10,2),
  `created_by` INT,
  `approved_by` INT,
  `approved_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_study_id` (`study_id`),
  INDEX `idx_transcription_id` (`transcription_id`),
  INDEX `idx_template_id` (`template_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_created_at` (`created_at`),
  FOREIGN KEY (`study_id`) REFERENCES `estudios`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`transcription_id`) REFERENCES `ai_transcriptions`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`template_id`) REFERENCES `plantillas`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de configuración de AI
CREATE TABLE IF NOT EXISTS `ai_config` (
  `id` INT PRIMARY KEY DEFAULT 1,
  `ollama_base_url` VARCHAR(255) DEFAULT 'http://localhost:11434',
  `whisper_model` VARCHAR(100) DEFAULT 'whisper',
  `medgemma_model` VARCHAR(100) DEFAULT 'medgemma',
  `timeout` INT DEFAULT 300,
  `max_audio_size_mb` INT DEFAULT 25,
  `default_prompt` TEXT,
  `enabled` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insertar configuración por defecto
INSERT INTO `ai_config` (`id`, `ollama_base_url`, `whisper_model`, `medgemma_model`, `timeout`, `max_audio_size_mb`, `default_prompt`, `enabled`)
VALUES (1, 'http://localhost:11434', 'whisper', 'medgemma', 300, 25, 'ROL: Radiólogo. PACIENTE: {patient} ESTUDIO: {study} TRANSCRIPCIÓN: {transcription} PLANTILLA: {template}\nInforme médico completo listo para firmar.', 1)
ON DUPLICATE KEY UPDATE 
  `ollama_base_url` = VALUES(`ollama_base_url`),
  `whisper_model` = VALUES(`whisper_model`),
  `medgemma_model` = VALUES(`medgemma_model`);
