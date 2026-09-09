-- Agregar estados detallados para las etapas del upload
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

-- Si la columna existe, modificarla para incluir todos los nuevos estados
SET @sql = IF(@col_exists > 0,
    CONCAT('ALTER TABLE `', @tablename, '` MODIFY COLUMN `', @columnname, 
           '` ENUM(\'pending\', \'preparando_estudio\', \'descargando_instancias\', \'generando_zip\', \'generando_manifest\', \'guardando_manifest_en_zip\', \'subiendo\', \'enviando_a_r2\', \'guardado_en_r2\', \'uploading\', \'done\', \'error\', \'cancelled\', \'pending_extraction\', \'extraccion_en_curso\', \'estudio_online\') DEFAULT \'pending\' COMMENT \'Estado del procesamiento\''),
    'SELECT "Columna status no existe" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
