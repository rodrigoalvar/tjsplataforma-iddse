-- Orden de descubrimiento de estudios por política (worker v1).
-- Ejecutar contra la misma base que pacs_cloner_policies.

ALTER TABLE `pacs_cloner_policies`
  ADD COLUMN `discovery_sort` ENUM('modality','oldest_study','newest_study') NOT NULL DEFAULT 'modality'
  COMMENT 'Cómo ordenar resultados C-FIND antes de encolar: modalidad; estudio más antiguo primero; más reciente primero'
  AFTER `alignment_strategy`;
