-- =====================================================
-- Migración: PACS Cloner (políticas y órdenes de réplica)
-- PACS NODES MANAGER
-- Fecha: 2026-05-09
-- Ejecutar contra la misma base que pacs_nodes (ej. tjsmedical_iddse).
-- =====================================================

CREATE TABLE IF NOT EXISTS `pacs_cloner_policies` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL COMMENT 'Nombre descriptivo',
  `node_id` INT(11) UNSIGNED NOT NULL COMMENT 'Nodo origen (remoto)',
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Si el daemon futuro debe usar esta política',
  `mode` ENUM('paused','assisted','automatic') NOT NULL DEFAULT 'paused' COMMENT 'paused=solo manual; assisted/automatic reservado',
  `scan_window_hours` INT(11) UNSIGNED NOT NULL DEFAULT 24 COMMENT 'Ventana de descubrimiento (horas); futuro daemon',
  `date_from` DATE DEFAULT NULL,
  `date_to` DATE DEFAULT NULL,
  `max_concurrent` TINYINT(3) UNSIGNED NOT NULL DEFAULT 2,
  `modality_filter` VARCHAR(32) DEFAULT NULL COMMENT 'Ej. CT,MR o vacío=todas',
  `modality_priority` VARCHAR(128) DEFAULT NULL COMMENT 'Orden de prioridad para encolado (ej. CR,DX,CT,MR)',
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT(11) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_node_id` (`node_id`),
  KEY `idx_enabled` (`is_enabled`),
  CONSTRAINT `fk_cloner_policy_node` FOREIGN KEY (`node_id`) REFERENCES `pacs_nodes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Políticas PACS Cloner';

CREATE TABLE IF NOT EXISTS `pacs_cloner_orders` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `policy_id` INT(11) UNSIGNED DEFAULT NULL,
  `node_id` INT(11) UNSIGNED NOT NULL,
  `trigger_type` ENUM('ui','scheduled','worker') NOT NULL DEFAULT 'ui',
  `label` VARCHAR(255) DEFAULT NULL,
  `study_instance_uids` JSON NOT NULL,
  `pacs_node_job_id` INT(11) UNSIGNED DEFAULT NULL,
  `status` ENUM('pending','running','success','failed','cancelled') NOT NULL DEFAULT 'pending',
  `error_message` TEXT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `started_at` DATETIME DEFAULT NULL,
  `completed_at` DATETIME DEFAULT NULL,
  `created_by` INT(11) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_node_id` (`node_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created` (`created_at`),
  KEY `idx_policy` (`policy_id`),
  KEY `idx_pacs_node_job` (`pacs_node_job_id`),
  CONSTRAINT `fk_cloner_order_policy` FOREIGN KEY (`policy_id`) REFERENCES `pacs_cloner_policies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cloner_order_node` FOREIGN KEY (`node_id`) REFERENCES `pacs_nodes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cloner_order_job` FOREIGN KEY (`pacs_node_job_id`) REFERENCES `pacs_node_jobs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Órdenes de clonado (trazabilidad)';
