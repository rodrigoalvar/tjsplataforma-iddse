-- Agrega prioridad por modalidad por política PACS Cloner
ALTER TABLE `pacs_cloner_policies`
  ADD COLUMN `modality_priority` VARCHAR(128) DEFAULT NULL
  COMMENT 'Orden de prioridad para encolado (ej. CR,DX,CT,MR)'
  AFTER `modality_filter`;
