-- Agregar columna audio_id a tablas AI para vincular con audios_informe
-- Sistema TJSMEDICAL - Portal de Estudios Médicos
-- Base de datos: tjsmedical_iddse

USE tjsmedical_iddse;

-- Agregar audio_id a ai_transcriptions
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'tjsmedical_iddse' 
    AND TABLE_NAME = 'ai_transcriptions' 
    AND COLUMN_NAME = 'audio_id');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE ai_transcriptions ADD COLUMN audio_id INT NULL AFTER study_id, ADD INDEX idx_audio_id (audio_id)',
    'SELECT ''Column audio_id already exists in ai_transcriptions'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Agregar audio_id a ai_reports
SET @col_exists2 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'tjsmedical_iddse' 
    AND TABLE_NAME = 'ai_reports' 
    AND COLUMN_NAME = 'audio_id');

SET @sql2 = IF(@col_exists2 = 0,
    'ALTER TABLE ai_reports ADD COLUMN audio_id INT NULL AFTER study_id, ADD INDEX idx_audio_id (audio_id)',
    'SELECT ''Column audio_id already exists in ai_reports'' AS message');
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- Verificar que se agregaron correctamente
SELECT 'Columnas audio_id verificadas/agregadas correctamente' AS resultado;
