-- Script para limpiar transcripciones bloqueadas
-- Ejecutar: mysql -u usuario -p nombre_base_datos < limpiar_transcripcion_bloqueada.sql

USE tjsmedical_iddse;

-- Ver transcripciones en proceso para el audio_id 35
SELECT 
    id, 
    audio_id, 
    status, 
    updated_at, 
    TIMESTAMPDIFF(MINUTE, updated_at, NOW()) as minutes_ago,
    error_message
FROM ai_transcriptions 
WHERE audio_id = 35 AND status = 'processing';

-- Marcar como fallida la transcripción bloqueada (ajustar audio_id si es necesario)
UPDATE ai_transcriptions 
SET status = 'failed', 
    error_message = 'Transcripción bloqueada - limpiada manualmente',
    updated_at = NOW()
WHERE audio_id = 35 
  AND status = 'processing';

-- Verificar que se actualizó
SELECT 
    id, 
    audio_id, 
    status, 
    updated_at,
    error_message
FROM ai_transcriptions 
WHERE audio_id = 35
ORDER BY created_at DESC
LIMIT 5;
