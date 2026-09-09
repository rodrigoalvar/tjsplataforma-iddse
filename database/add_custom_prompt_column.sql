-- Agregar columna default_prompt a ai_config si no existe
-- Sistema TJSMEDICAL - Portal de Estudios Médicos

USE tjsmedical_iddse;

-- Verificar y agregar default_prompt si no existe
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'tjsmedical_iddse' 
    AND TABLE_NAME = 'ai_config' 
    AND COLUMN_NAME = 'default_prompt');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE ai_config ADD COLUMN default_prompt TEXT NULL AFTER max_audio_size_mb',
    'SELECT ''Column default_prompt already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verificar que se agregó correctamente
SELECT 'Columna default_prompt verificada/agregada correctamente' AS resultado;
