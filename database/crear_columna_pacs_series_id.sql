-- Script para crear la columna pacs_series_id en la tabla informes
-- Esta columna almacena el Series ID que Orthanc devuelve al enviar un informe
-- Este Series ID se usa para eliminar la serie anterior cuando se reenvía un informe

-- Verificar si la columna existe antes de crearla
SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN 'La columna pacs_series_id ya existe'
        ELSE 'La columna pacs_series_id NO existe - será creada'
    END as estado_columna
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'informes'
  AND COLUMN_NAME = 'pacs_series_id';

-- Crear la columna si no existe
-- NOTA: Si la columna ya existe, este comando dará error, pero es seguro ejecutarlo
ALTER TABLE informes 
ADD COLUMN IF NOT EXISTS pacs_series_id VARCHAR(255) DEFAULT NULL 
AFTER pacs_study_id;

-- Verificar que se creó correctamente
SELECT 
    COLUMN_NAME,
    DATA_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_KEY
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'informes'
  AND COLUMN_NAME = 'pacs_series_id';

-- Opcional: Crear índice para mejor rendimiento en búsquedas
CREATE INDEX IF NOT EXISTS idx_pacs_series_id ON informes(pacs_series_id);

-- Mostrar estructura final de columnas PACS
SELECT 
    COLUMN_NAME,
    DATA_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'informes'
  AND (COLUMN_NAME LIKE 'pacs%' OR COLUMN_NAME LIKE '%series%')
ORDER BY ORDINAL_POSITION;

