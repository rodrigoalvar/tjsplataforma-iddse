-- Agregar columnas para whisper-cli (aditivo, no destructivo)
-- Sistema TJSMEDICAL - Portal de Estudios Médicos

USE tjsmedical_iddse;

-- Verificar y agregar whisper_method
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'tjsmedical_iddse' 
    AND TABLE_NAME = 'ai_config' 
    AND COLUMN_NAME = 'whisper_method');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE ai_config ADD COLUMN whisper_method VARCHAR(20) DEFAULT ''whisper-server'' AFTER whisper_api_url',
    'SELECT ''Column whisper_method already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verificar y agregar whisper_cli_api_url
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'tjsmedical_iddse' 
    AND TABLE_NAME = 'ai_config' 
    AND COLUMN_NAME = 'whisper_cli_api_url');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE ai_config ADD COLUMN whisper_cli_api_url VARCHAR(255) DEFAULT ''http://localhost:3001'' AFTER whisper_method',
    'SELECT ''Column whisper_cli_api_url already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'Columnas whisper_method y whisper_cli_api_url verificadas/agregadas correctamente' AS resultado;
