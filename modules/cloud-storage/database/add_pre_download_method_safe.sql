-- Agregar 'pre-download' al ENUM de upload_method en r2_queue (versión segura)
-- Verifica el tipo actual y solo modifica si es necesario
-- Ejecutar: mysql -uroot -p tjsmedical_iddse < add_pre_download_method_safe.sql

USE tjsmedical_iddse;

-- Verificar si 'pre-download' ya está en el ENUM
SET @dbname = DATABASE();
SET @tablename = 'r2_queue';
SET @columnname = 'upload_method';

-- Obtener la definición actual de la columna
SET @current_definition = (
    SELECT COLUMN_TYPE 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE 
        TABLE_SCHEMA = @dbname 
        AND TABLE_NAME = @tablename 
        AND COLUMN_NAME = @columnname
);

-- Si la definición no contiene 'pre-download', actualizar
SET @preparedStatement = (SELECT IF(
    @current_definition LIKE '%pre-download%',
    'SELECT 1 AS "pre-download ya existe en ENUM"',
    CONCAT('ALTER TABLE ', @tablename, ' MODIFY COLUMN ', @columnname, ' ENUM(\'instance\', \'zip\', \'pre-download\') DEFAULT \'instance\'')
));

PREPARE alterIfNeeded FROM @preparedStatement;
EXECUTE alterIfNeeded;
DEALLOCATE PREPARE alterIfNeeded;
