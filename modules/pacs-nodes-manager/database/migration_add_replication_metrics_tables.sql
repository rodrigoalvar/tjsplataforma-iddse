-- Migración: sesiones on-demand de métricas de replicación (Fase 2)
-- Fecha: 2026-03-26

CREATE TABLE IF NOT EXISTS `pacs_replication_sessions` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) UNSIGNED DEFAULT NULL,
  `study_instance_uid` VARCHAR(255) NOT NULL,
  `started_at` DATETIME DEFAULT NULL,
  `ended_at` DATETIME DEFAULT NULL,
  `duration_sec` INT(11) DEFAULT 0,
  `status` VARCHAR(50) DEFAULT 'completed',
  `local_first_seen_at` DATETIME DEFAULT NULL,
  `local_last_instances` INT(11) DEFAULT 0,
  `avg_speed_inst_sec` DECIMAL(10,3) DEFAULT 0.000,
  `peak_speed_inst_sec` DECIMAL(10,3) DEFAULT 0.000,
  `ticks_count` INT(11) DEFAULT 0,
  `notes` VARCHAR(500) DEFAULT NULL,
  `meta_json` JSON DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_uid` (`study_instance_uid`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Sesiones on-demand de medición por StudyInstanceUID';

CREATE TABLE IF NOT EXISTS `pacs_replication_session_nodes` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` INT(11) UNSIGNED NOT NULL,
  `node_id` INT(11) UNSIGNED DEFAULT NULL,
  `node_name` VARCHAR(255) DEFAULT NULL,
  `remote_first_seen_at` DATETIME DEFAULT NULL,
  `lag_seconds` DECIMAL(12,3) DEFAULT NULL,
  `final_instances` INT(11) DEFAULT 0,
  `had_error` TINYINT(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_session` (`session_id`),
  KEY `idx_node` (`node_id`),
  CONSTRAINT `fk_replication_nodes_session` FOREIGN KEY (`session_id`) REFERENCES `pacs_replication_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Métricas por nodo dentro de una sesión de replicación';

