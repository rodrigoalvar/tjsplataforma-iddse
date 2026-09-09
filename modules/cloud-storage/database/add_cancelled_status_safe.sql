-- Agregar 'cancelled' al ENUM de status en r2_queue de forma segura
-- Compatible con MySQL 5.7 y 8.0

SET @dbname = DATABASE();
SET @tablename = 'r2_queue';
SET @columnname = 'status';

-- Verificar si la columna existe
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = @dbname 
    AND TABLE_NAME = @tablename 
    AND COLUMN_NAME = @columnname
);

-- Si la columna existe, modificarla para incluir 'cancelled' y 'pending_extraction'
SET @sql = IF(@col_exists > 0,
    CONCAT('ALTER TABLE `', @tablename, '` MODIFY COLUMN `', @columnname, 
           '` ENUM(\'pending\', \'uploading\', \'done\', \'error\', \'cancelled\', \'pending_extraction\') DEFAULT \'pending\' COMMENT \'Estado del procesamiento\''),
    'SELECT "Columna status no existe" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
