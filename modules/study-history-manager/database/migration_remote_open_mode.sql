-- Historial de estudios: cómo abrir UDV en nodos legacy (WADO por estudio vs manifest por instancia).
-- Tras importar: en Configuración → Visores nodos PACS asignar "UDV: manifest" en nodos DCM4CHEE 2.18.x u otros sin DICOMweb.

ALTER TABLE `pacs_nodes`
  ADD COLUMN `remote_open_mode` ENUM('dicomweb','wado_manifest') NOT NULL DEFAULT 'dicomweb'
  COMMENT 'UDV remoto: dicomweb=WADO nivel estudio; wado_manifest=JSON manifest vía DIMSE+C-FIND';
