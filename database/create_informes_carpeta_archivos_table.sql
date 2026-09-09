-- Cola y trazabilidad de archivos PDF/TXT leídos desde carpetas (p. ej. SMB Windows montado en Linux).
-- Ejecutar una vez en el servidor MySQL.

CREATE TABLE IF NOT EXISTS `informes_carpeta_archivos` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `tipo` ENUM('pdf','txt') NOT NULL,
  `ruta_absoluta` VARCHAR(1024) NOT NULL,
  `nombre_archivo` VARCHAR(512) NOT NULL,
  `idpaciente` VARCHAR(191) NOT NULL COMMENT 'Prefijo del nombre antes del primer _',
  `sufijo_nombre` VARCHAR(255) DEFAULT NULL COMMENT 'Tras _: ACCNO en txt o N° estudio informe en pdf (no es ACCNO DICOM)',
  `tamano_bytes` BIGINT DEFAULT NULL,
  `mtime_fs` DATETIME DEFAULT NULL,
  `sha256` CHAR(64) DEFAULT NULL,
  `estado` ENUM('detectado','pendiente_par','emparejado','ingresado','omitido_duplicado','error') NOT NULL DEFAULT 'detectado',
  `error_message` TEXT DEFAULT NULL,
  `fecha_deteccion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_actualizacion` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `parquet_con_id` BIGINT DEFAULT NULL COMMENT 'Otro informes_carpeta_archivos.id emparejado',
  `informe_recibido_id` INT DEFAULT NULL,
  `metadata_json` TEXT DEFAULT NULL COMMENT 'JSON: fechas, accession del txt, motivo emparejamiento, etc.',
  UNIQUE KEY `uq_sha256_tipo` (`sha256`,`tipo`),
  KEY `idx_tipo_estado` (`tipo`,`estado`),
  KEY `idx_idpaciente` (`idpaciente`),
  KEY `idx_informe_recibido` (`informe_recibido_id`),
  KEY `idx_fecha_deteccion` (`fecha_deteccion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
