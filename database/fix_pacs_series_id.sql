-- Script para verificar y crear la columna pacs_series_id si no existe
-- Ejecutar este script si pacs_series_id está vacío en todos los informes

-- 1. Verificar si la columna existe
SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN 'La columna pacs_series_id ya existe'
        ELSE 'La columna pacs_series_id NO existe - será creada'
    END as estado_columna
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'informes'
  AND COLUMN_NAME = 'pacs_series_id';

-- 2. Mostrar estructura actual de columnas PACS
SELECT 
    COLUMN_NAME,
    DATA_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    ORDINAL_POSITION
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'informes'
  AND (COLUMN_NAME LIKE 'pacs%' OR COLUMN_NAME LIKE '%series%')
ORDER BY ORDINAL_POSITION;

-- 3. Crear la columna si no existe
-- IMPORTANTE: Si la columna ya existe, este comando dará error pero es seguro
ALTER TABLE informes 
ADD COLUMN IF NOT EXISTS pacs_series_id VARCHAR(255) DEFAULT NULL 
AFTER pacs_study_id;

-- Si tu versión de MySQL no soporta IF NOT EXISTS, usar este método:
-- Primero verificar, luego crear solo si no existe

-- 4. Verificar que se creó correctamente
SELECT 
    COLUMN_NAME,
    DATA_TYPE,
    CHARACTER_MAXIMUM_LENGTH,
    IS_NULLABLE,
    COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'informes'
  AND COLUMN_NAME = 'pacs_series_id';

-- 5. Opcional: Crear índice para mejor rendimiento
CREATE INDEX IF NOT EXISTS idx_pacs_series_id ON informes(pacs_series_id);

-- Si tu versión de MySQL no soporta IF NOT EXISTS en CREATE INDEX:
-- Primero verificar si existe el índice, luego crearlo solo si no existe

-- 6. Estadísticas de informes con y sin Series ID
SELECT 
    COUNT(*) as total_informes_con_instance_id,
    COUNT(pacs_instance_id) as con_instance_id,
    COUNT(pacs_study_id) as con_study_id,
    COUNT(pacs_series_id) as con_series_id,
    COUNT(*) - COUNT(pacs_series_id) as sin_series_id
FROM informes
WHERE pacs_instance_id IS NOT NULL;

-- 7. Mostrar algunos ejemplos de informes sin Series ID
SELECT 
    id,
    titulo,
    pacs_instance_id,
    pacs_study_id,
    pacs_series_id,
    fecha_enviado_pacs
FROM informes
WHERE pacs_instance_id IS NOT NULL 
  AND pacs_series_id IS NULL
ORDER BY fecha_enviado_pacs DESC
LIMIT 10;

