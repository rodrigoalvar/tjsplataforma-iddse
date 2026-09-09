-- Tabla de trazabilidad de intentos de recepción de informes por API externa
-- Guarda tanto éxitos como fallos de validación/procesamiento.

CREATE TABLE IF NOT EXISTS `informes_recibidos_intentos` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `request_id` VARCHAR(64) NOT NULL COMMENT 'Identificador único del intento',
  `estado` ENUM('iniciado', 'exitoso', 'error') NOT NULL DEFAULT 'iniciado',
  `error_message` TEXT DEFAULT NULL COMMENT 'Error de validación/proceso cuando falla',
  `accession_number` VARCHAR(100) DEFAULT NULL COMMENT 'ACCNO parseado del TXT si se pudo obtener',
  `informe_recibido_id` INT DEFAULT NULL COMMENT 'FK lógica al registro en informes_recibidos si fue exitoso',
  `metodo_http` VARCHAR(16) DEFAULT NULL,
  `content_type` VARCHAR(255) DEFAULT NULL,
  `remote_ip` VARCHAR(64) DEFAULT NULL,
  `user_agent` TEXT DEFAULT NULL,
  `pdf_filename` VARCHAR(255) DEFAULT NULL,
  `pdf_mime` VARCHAR(120) DEFAULT NULL,
  `pdf_size_bytes` BIGINT DEFAULT NULL,
  `txt_filename` VARCHAR(255) DEFAULT NULL,
  `txt_mime` VARCHAR(120) DEFAULT NULL,
  `txt_size_bytes` BIGINT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_request_id` (`request_id`),
  INDEX `idx_estado` (`estado`),
  INDEX `idx_accession` (`accession_number`),
  INDEX `idx_created_at` (`created_at`),
  INDEX `idx_informe_recibido_id` (`informe_recibido_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
