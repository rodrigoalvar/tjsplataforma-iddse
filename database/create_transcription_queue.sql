-- Tabla de cola para transcripciones automáticas
-- Sistema TJSMEDICAL - Portal de Estudios Médicos
-- Base de datos: tjsmedical_iddse

USE tjsmedical_iddse;

-- Tabla de cola de transcripciones
CREATE TABLE IF NOT EXISTS `ai_transcription_queue` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `audio_id` INT NOT NULL COMMENT 'ID del audio en audios_informe',
  `study_id` INT COMMENT 'ID del estudio local',
  `orthanc_study_id` VARCHAR(255) COMMENT 'ID del estudio en Orthanc',
  `status` ENUM('pending', 'processing', 'completed', 'failed', 'cancelled') DEFAULT 'pending',
  `priority` INT DEFAULT 0 COMMENT 'Prioridad: mayor número = mayor prioridad',
  `retry_count` INT DEFAULT 0 COMMENT 'Número de intentos fallidos',
  `max_retries` INT DEFAULT 3 COMMENT 'Máximo número de reintentos',
  `error_message` TEXT COMMENT 'Mensaje de error si falla',
  `created_by` INT COMMENT 'ID del usuario que creó la entrada',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `started_at` TIMESTAMP NULL COMMENT 'Cuándo comenzó el procesamiento',
  `completed_at` TIMESTAMP NULL COMMENT 'Cuándo se completó',
  INDEX `idx_status_created` (`status`, `created_at`),
  INDEX `idx_audio_id` (`audio_id`),
  INDEX `idx_priority_status` (`priority` DESC, `status`, `created_at`),
  FOREIGN KEY (`audio_id`) REFERENCES `audios_informe`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extender tabla ai_config con configuración de transcripción automática
-- Verificar si las columnas existen antes de agregarlas
SET @dbname = DATABASE();
SET @tablename = 'ai_config';
SET @columnname1 = 'auto_transcribe_enabled';
SET @columnname2 = 'max_concurrent_transcriptions';

SET @preparedStatement = (SELECT IF(
    (
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE
            (TABLE_SCHEMA = @dbname)
            AND (TABLE_NAME = @tablename)
            AND (COLUMN_NAME = @columnname1)
    ) > 0,
    'SELECT 1',
    CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname1, ' TINYINT(1) DEFAULT 0 COMMENT ''Activar transcripción automática''')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

SET @preparedStatement = (SELECT IF(
    (
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE
            (TABLE_SCHEMA = @dbname)
            AND (TABLE_NAME = @tablename)
            AND (COLUMN_NAME = @columnname2)
    ) > 0,
    'SELECT 1',
    CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname2, ' INT DEFAULT 1 COMMENT ''Máximo de transcripciones concurrentes''')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Actualizar configuración por defecto si no existe
UPDATE `ai_config` 
SET `auto_transcribe_enabled` = IFNULL(`auto_transcribe_enabled`, 0),
    `max_concurrent_transcriptions` = IFNULL(`max_concurrent_transcriptions`, 1)
WHERE `id` = 1;
