-- Clave del ZIP en R2 cuando el estudio se subió solo como ZIP (sin manifest.json en bucket)
-- Ejecutar en MySQL si aún no existe la columna.

SET @dbname = DATABASE();
SET @tablename = 'r2_studies';
SET @columnname = 'r2_zip_key';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT ''Column r2_zip_key already exists'' AS info',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' VARCHAR(500) NULL DEFAULT NULL COMMENT ''Key S3/R2 del study.zip si no hay manifest'' AFTER r2_manifest_path')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;
