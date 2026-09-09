-- Agregar estado 'descargando_instancias' al ENUM de status en r2_queue (versión segura)
-- Verifica si el estado ya existe antes de modificar
-- Ejecutar: mysql -uroot -p tjsmedical_iddse < add_descargando_instancias_status_safe.sql

USE tjsmedical_iddse;

SET @dbname = DATABASE();
SET @tablename = 'r2_queue';
SET @columnname = 'status';

-- Obtener la definición actual de la columna
SET @current_definition = (
    SELECT COLUMN_TYPE 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE 
        TABLE_SCHEMA = @dbname 
        AND TABLE_NAME = @tablename 
        AND COLUMN_NAME = @columnname
);

-- Si la definición no contiene 'descargando_instancias', actualizar
SET @preparedStatement = (SELECT IF(
    @current_definition LIKE '%descargando_instancias%',
    'SELECT 1 AS "descargando_instancias ya existe en ENUM"',
    CONCAT('ALTER TABLE ', @tablename, ' MODIFY COLUMN ', @columnname, 
           ' ENUM(\'pending\', \'preparando_estudio\', \'descargando_instancias\', \'generando_zip\', \'generando_manifest\', \'guardando_manifest_en_zip\', \'subiendo\', \'enviando_a_r2\', \'guardado_en_r2\', \'uploading\', \'done\', \'error\', \'cancelled\', \'pending_extraction\', \'extraccion_en_curso\', \'estudio_online\') DEFAULT \'pending\' COMMENT \'Estado del procesamiento\'')
));

PREPARE alterIfNeeded FROM @preparedStatement;
EXECUTE alterIfNeeded;
DEALLOCATE PREPARE alterIfNeeded;
