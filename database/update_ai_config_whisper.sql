-- Actualizar tabla ai_config para agregar campos de whisper.cpp
-- Sistema TJSMEDICAL - Portal de Estudios Médicos

USE tjsmedical_iddse;

-- Agregar columnas para whisper.cpp (verificar existencia primero)
-- Nota: Ejecutar manualmente o usar un script PHP que verifique antes de agregar

-- Verificar y agregar whisper_api_url
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'tjsmedical_iddse' 
    AND TABLE_NAME = 'ai_config' 
    AND COLUMN_NAME = 'whisper_api_url');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE ai_config ADD COLUMN whisper_api_url VARCHAR(255) DEFAULT ''http://localhost:8080'' AFTER ollama_base_url',
    'SELECT ''Column whisper_api_url already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verificar y agregar whisper_model (si no existe como columna separada)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'tjsmedical_iddse' 
    AND TABLE_NAME = 'ai_config' 
    AND COLUMN_NAME = 'whisper_model');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE ai_config ADD COLUMN whisper_model VARCHAR(100) DEFAULT ''base'' AFTER whisper_api_url',
    'SELECT ''Column whisper_model already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verificar y agregar whisper_language
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'tjsmedical_iddse' 
    AND TABLE_NAME = 'ai_config' 
    AND COLUMN_NAME = 'whisper_language');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE ai_config ADD COLUMN whisper_language VARCHAR(10) DEFAULT ''es'' AFTER whisper_model',
    'SELECT ''Column whisper_language already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verificar y agregar whisper_timeout
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'tjsmedical_iddse' 
    AND TABLE_NAME = 'ai_config' 
    AND COLUMN_NAME = 'whisper_timeout');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE ai_config ADD COLUMN whisper_timeout INT DEFAULT 300 AFTER whisper_language',
    'SELECT ''Column whisper_timeout already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Actualizar configuración existente si no tiene valores para whisper
UPDATE `ai_config` 
SET 
    `whisper_api_url` = COALESCE(`whisper_api_url`, 'http://localhost:8080'),
    `whisper_model` = COALESCE(`whisper_model`, 'base'),
    `whisper_language` = COALESCE(`whisper_language`, 'es'),
    `whisper_timeout` = COALESCE(`whisper_timeout`, 300)
WHERE `id` = 1;
