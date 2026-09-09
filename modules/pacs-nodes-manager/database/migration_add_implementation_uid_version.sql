-- Migración: Agregar campos para Implementation Class UID y Implementation Version Name
-- Fecha: 2026-03-12
-- Descripción: Campos para almacenar información de implementación DICOM desde asociaciones
-- Nota: Esta información solo está disponible en la negociación DICOM (A-ASSOCIATE-AC)
--       y requiere parsear logs de Orthanc o usar un plugin

-- Verificar si las columnas ya existen antes de agregarlas
SET @col_exists = 0;

-- Verificar implementation_class_uid
SELECT COUNT(*) INTO @col_exists
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'pacs_nodes'
  AND COLUMN_NAME = 'implementation_class_uid';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE pacs_nodes ADD COLUMN implementation_class_uid VARCHAR(255) DEFAULT NULL COMMENT ''Implementation Class UID del PACS remoto (desde A-ASSOCIATE-AC)''',
    'SELECT ''Column implementation_class_uid already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verificar implementation_version_name
SELECT COUNT(*) INTO @col_exists
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'pacs_nodes'
  AND COLUMN_NAME = 'implementation_version_name';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE pacs_nodes ADD COLUMN implementation_version_name VARCHAR(255) DEFAULT NULL COMMENT ''Implementation Version Name del PACS remoto (ej: dcm4che-1.4.34, OFFIS_DCMTK_368)''',
    'SELECT ''Column implementation_version_name already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verificar implementation_detected_from
SELECT COUNT(*) INTO @col_exists
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'pacs_nodes'
  AND COLUMN_NAME = 'implementation_detected_from';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE pacs_nodes ADD COLUMN implementation_detected_from VARCHAR(50) DEFAULT NULL COMMENT ''Fuente de detección: dicom_objects, orthanc_logs, manual''',
    'SELECT ''Column implementation_detected_from already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Mostrar resumen
SELECT 'Migración completada: Campos de Implementation Class UID y Version Name agregados' AS result;
