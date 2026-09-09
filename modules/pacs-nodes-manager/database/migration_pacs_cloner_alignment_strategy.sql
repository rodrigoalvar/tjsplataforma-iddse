-- Alineación / reanudación: C-MOVE a nivel estudio (default), serie o instancia (C-FIND fino en el origen).
-- Ejecutar contra la misma base que pacs_cloner_policies.

ALTER TABLE `pacs_cloner_policies`
  ADD COLUMN `alignment_strategy` ENUM('study','series','instance') NOT NULL DEFAULT 'study'
    COMMENT 'study=C-MOVE estudio; series=C-FIND serie + MOVE series incompletas; instance=diff SOPInstanceUID'
    AFTER `modality_priority`;
