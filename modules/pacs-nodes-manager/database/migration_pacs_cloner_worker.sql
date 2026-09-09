-- Registro de ejecuciones del worker PACS Cloner (opcional pero recomendado)
CREATE TABLE IF NOT EXISTS `pacs_cloner_worker_runs` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` DATETIME DEFAULT NULL,
  `policies_touched` INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `studies_discovered` INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `studies_skipped_local` INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `studies_queued` INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `notes` VARCHAR(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_started` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Historial ejecuciones worker cloner';
