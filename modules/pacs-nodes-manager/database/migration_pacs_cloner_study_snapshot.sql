-- Snapshot por (nodo, StudyInstanceUID) para comparar conteos remotos entre pasadas del worker
CREATE TABLE IF NOT EXISTS `pacs_cloner_study_snapshot` (
  `node_id` INT(11) UNSIGNED NOT NULL,
  `study_instance_uid` VARCHAR(255) NOT NULL,
  `last_remote_instance_count` INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`node_id`, `study_instance_uid`),
  KEY `idx_updated` (`updated_at`),
  CONSTRAINT `fk_cloner_snapshot_node` FOREIGN KEY (`node_id`) REFERENCES `pacs_nodes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Último conteo remoto visto por el worker (estabilidad C-FIND)';
