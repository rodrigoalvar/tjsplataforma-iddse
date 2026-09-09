-- Seguimiento de envío automático a PACS para informes_recibidos (autovincular / API).
-- Ejecutar una vez por instalación.

ALTER TABLE `informes_recibidos`
  ADD COLUMN `auto_pacs_estado` VARCHAR(20) DEFAULT NULL COMMENT 'pendiente|enviado|error' AFTER `fecha_vinculacion`,
  ADD COLUMN `auto_pacs_error` TEXT DEFAULT NULL,
  ADD COLUMN `auto_pacs_last_try_at` DATETIME DEFAULT NULL,
  ADD COLUMN `auto_pacs_try_count` INT UNSIGNED NOT NULL DEFAULT 0,
  ADD INDEX `idx_ir_auto_pacs_queue` (`estado`, `auto_pacs_estado`, `auto_pacs_last_try_at`);
