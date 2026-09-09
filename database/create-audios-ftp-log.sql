-- Tabla para registrar envíos de audios a servidor FTP
-- Sistema TJSMEDICAL - Portal de Estudios Médicos

CREATE TABLE IF NOT EXISTS `audios_ftp_log` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `audio_id` INT(11) NOT NULL COMMENT 'ID del audio en audios_informe',
  `ftp_host` VARCHAR(255) NOT NULL COMMENT 'Servidor FTP donde se envió',
  `remote_path` VARCHAR(500) NOT NULL COMMENT 'Ruta remota donde se guardó el archivo',
  `file_name` VARCHAR(255) NOT NULL COMMENT 'Nombre del archivo enviado',
  `status` ENUM('pending', 'success', 'failed', 'retrying') NOT NULL DEFAULT 'pending' COMMENT 'Estado del envío',
  `error_message` TEXT NULL COMMENT 'Mensaje de error si falló',
  `attempts` INT(11) NOT NULL DEFAULT 0 COMMENT 'Número de intentos realizados',
  `sent_at` DATETIME NULL COMMENT 'Fecha y hora en que se envió exitosamente',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Fecha de creación del registro',
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Fecha de última actualización',
  PRIMARY KEY (`id`),
  INDEX `idx_audio_id` (`audio_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_created_at` (`created_at`),
  CONSTRAINT `fk_audios_ftp_log_audio` FOREIGN KEY (`audio_id`) REFERENCES `audios_informe` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registro de envíos de audios a servidor FTP';
