-- Agregar columna ffmpeg_rest_url a ai_config si no existe
-- Sistema TJSMEDICAL - Portal de Estudios Médicos

USE tjsmedical_iddse;

-- Verificar y agregar ffmpeg_rest_url si no existe
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'tjsmedical_iddse' 
    AND TABLE_NAME = 'ai_config' 
    AND COLUMN_NAME = 'ffmpeg_rest_url');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE ai_config ADD COLUMN ffmpeg_rest_url VARCHAR(255) DEFAULT ''http://localhost:3000'' AFTER whisper_timeout',
    'SELECT ''Column ffmpeg_rest_url already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verificar que se agregó correctamente
SELECT 'Columna ffmpeg_rest_url verificada/agregada correctamente' AS resultado;
