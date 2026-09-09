-- Modo Study routing por usuario: NULL o '' = heredar global; local | r2 = forzar
SET @dbname = DATABASE();
SET @tablename = 'usuarios';
SET @columnname = 'study_routing_mode';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT ''Column study_routing_mode already exists'' AS info',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' VARCHAR(16) NULL DEFAULT NULL COMMENT ''study routing: inherit|local|r2''')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;
