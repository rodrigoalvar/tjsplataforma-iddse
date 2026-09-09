-- Agregar columna whisper_response a ai_transcriptions para debug
-- Sistema TJSMEDICAL - Portal de Estudios Médicos

USE tjsmedical_iddse;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'tjsmedical_iddse' 
    AND TABLE_NAME = 'ai_transcriptions' 
    AND COLUMN_NAME = 'whisper_response');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE ai_transcriptions ADD COLUMN whisper_response JSON NULL COMMENT ''Respuesta completa de whisper (segments, timings, etc.)'' AFTER transcription_text',
    'SELECT ''Column whisper_response already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'Columna whisper_response verificada/agregada correctamente' AS resultado;
