-- Migración: Agregar campos para almacenar información de implementación detectada del PACS
-- Fecha: 2026-03-09
-- Descripción: Campos para almacenar información detectada mediante C-ECHO + C-FIND

-- Verificar si las columnas ya existen antes de agregarlas
SET @col_exists = 0;

-- Verificar detected_manufacturer
SELECT COUNT(*) INTO @col_exists
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'pacs_nodes'
  AND COLUMN_NAME = 'detected_manufacturer';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE pacs_nodes ADD COLUMN detected_manufacturer VARCHAR(255) DEFAULT NULL COMMENT ''Fabricante detectado del PACS (ej: GE, Siemens, Philips)''',
    'SELECT ''Column detected_manufacturer already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verificar detected_manufacturer_model
SELECT COUNT(*) INTO @col_exists
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'pacs_nodes'
  AND COLUMN_NAME = 'detected_manufacturer_model';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE pacs_nodes ADD COLUMN detected_manufacturer_model VARCHAR(255) DEFAULT NULL COMMENT ''Modelo detectado del PACS''',
    'SELECT ''Column detected_manufacturer_model already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verificar detected_software_version
SELECT COUNT(*) INTO @col_exists
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'pacs_nodes'
  AND COLUMN_NAME = 'detected_software_version';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE pacs_nodes ADD COLUMN detected_software_version VARCHAR(255) DEFAULT NULL COMMENT ''Versión de software detectada''',
    'SELECT ''Column detected_software_version already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verificar detected_station_name
SELECT COUNT(*) INTO @col_exists
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'pacs_nodes'
  AND COLUMN_NAME = 'detected_station_name';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE pacs_nodes ADD COLUMN detected_station_name VARCHAR(255) DEFAULT NULL COMMENT ''Nombre de estación detectado''',
    'SELECT ''Column detected_station_name already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verificar detected_institution_name
SELECT COUNT(*) INTO @col_exists
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'pacs_nodes'
  AND COLUMN_NAME = 'detected_institution_name';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE pacs_nodes ADD COLUMN detected_institution_name VARCHAR(255) DEFAULT NULL COMMENT ''Nombre de institución detectado''',
    'SELECT ''Column detected_institution_name already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verificar detected_institution_address
SELECT COUNT(*) INTO @col_exists
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'pacs_nodes'
  AND COLUMN_NAME = 'detected_institution_address';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE pacs_nodes ADD COLUMN detected_institution_address VARCHAR(500) DEFAULT NULL COMMENT ''Dirección de institución detectada''',
    'SELECT ''Column detected_institution_address already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verificar implementation_detected_at
SELECT COUNT(*) INTO @col_exists
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'pacs_nodes'
  AND COLUMN_NAME = 'implementation_detected_at';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE pacs_nodes ADD COLUMN implementation_detected_at DATETIME DEFAULT NULL COMMENT ''Fecha y hora de última detección de implementación''',
    'SELECT ''Column implementation_detected_at already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Mostrar resumen
SELECT 'Migración completada: Campos de información de implementación detectada agregados' AS result;
