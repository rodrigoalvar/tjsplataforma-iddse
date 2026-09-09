-- Agregar campos para método ZIP upload a la tabla r2_queue
-- Ejecutar: mysql -uroot -p tjsmedical_iddse < add_zip_upload_fields.sql

USE tjsmedical_iddse;

-- Agregar campo upload_method (si no existe)
ALTER TABLE r2_queue 
ADD COLUMN IF NOT EXISTS upload_method ENUM('instance', 'zip') DEFAULT 'instance' AFTER status;

-- Agregar campo zip_path (si no existe)
ALTER TABLE r2_queue 
ADD COLUMN IF NOT EXISTS zip_path VARCHAR(500) NULL COMMENT 'Ruta del ZIP en R2 (si método=zip)' AFTER upload_method;

-- Crear índice para búsquedas por método (solo si no existe)
SET @dbname = DATABASE();
SET @tablename = 'r2_queue';
SET @indexname = 'idx_upload_method';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (INDEX_NAME = @indexname)
  ) > 0,
  'SELECT 1',
  CONCAT('CREATE INDEX ', @indexname, ' ON ', @tablename, '(upload_method)')
));
PREPARE createIndexIfNotExists FROM @preparedStatement;
EXECUTE createIndexIfNotExists;
DEALLOCATE PREPARE createIndexIfNotExists;

-- Crear índice para búsquedas por zip_path (solo si no existe)
SET @indexname = 'idx_zip_path';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (INDEX_NAME = @indexname)
  ) > 0,
  'SELECT 1',
  CONCAT('CREATE INDEX ', @indexname, ' ON ', @tablename, '(zip_path)')
));
PREPARE createIndexIfNotExists FROM @preparedStatement;
EXECUTE createIndexIfNotExists;
DEALLOCATE PREPARE createIndexIfNotExists;
