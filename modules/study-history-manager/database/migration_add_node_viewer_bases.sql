-- URLs opcionales por nodo para abrir estudios remotos sin C-MOVE.
-- Ejecutar una vez. Si las columnas ya existen, omitir este script.

ALTER TABLE `pacs_nodes`
  ADD COLUMN `wado_uri_base` VARCHAR(500) DEFAULT NULL
    COMMENT 'Base WADO-URI (ej. DCM4CHEE) para UDV / enlaces directos' AFTER `dicomweb_auth_type`,
  ADD COLUMN `dicomweb_proxy_base` VARCHAR(500) DEFAULT NULL
    COMMENT 'Base URL del proxy DICOMweb (ej. Stone + knopkem)' AFTER `wado_uri_base`;
