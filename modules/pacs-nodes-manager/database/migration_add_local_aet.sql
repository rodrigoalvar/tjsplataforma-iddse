-- =====================================================
-- Migración: Agregar campo local_aet para AET alternativo
-- Sistema TJSMEDICAL - Portal de Estudios Médicos
-- Fecha: 2026-03-07
-- =====================================================
-- 
-- Este campo permite configurar un AET alternativo (LocalAet) 
-- que Orthanc usará como Calling AET cuando actúe como SCU 
-- hacia este peer específico. Esto permite diferenciar en los 
-- logs del PACS remoto las operaciones iniciadas desde el 
-- módulo PACS Nodes Manager.
-- 
-- Ejemplo:
-- - DicomAet global de Orthanc: "IDNEOPACS"
-- - LocalAet para este nodo: "IDNEOPACS_QR"
-- - En los logs del peer remoto se verá "IDNEOPACS_QR" como Calling AET

-- Verificar si la columna ya existe antes de agregarla (compatible con MySQL < 8.0)
SET @dbname = DATABASE();
SET @tablename = 'pacs_nodes';
SET @columnname = 'local_aet';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  'SELECT 1', -- Columna ya existe, no hacer nada
  CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @columnname, '` VARCHAR(16) DEFAULT NULL COMMENT ''AET alternativo (LocalAet) que Orthanc usará como Calling AET para este peer. Permite diferenciar operaciones del PACS Nodes Manager en los logs del PACS remoto.'' AFTER `aet`')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Verificar si el índice ya existe antes de agregarlo
SET @indexname = 'idx_local_aet';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (INDEX_NAME = @indexname)
  ) > 0,
  'SELECT 1', -- Índice ya existe, no hacer nada
  CONCAT('ALTER TABLE `', @tablename, '` ADD INDEX `', @indexname, '` (`', @columnname, '`)')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;
