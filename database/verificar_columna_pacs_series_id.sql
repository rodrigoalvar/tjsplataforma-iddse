-- Script para verificar y crear la columna pacs_series_id en la tabla informes
-- Si la columna no existe, este script la creará

-- Verificar si la columna existe
SELECT 
    COLUMN_NAME,
    DATA_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'informes'
  AND COLUMN_NAME = 'pacs_series_id';

-- Si la columna NO existe, ejecutar este comando:
-- (Descomentar si es necesario)

/*
ALTER TABLE informes 
ADD COLUMN pacs_series_id VARCHAR(255) DEFAULT NULL 
AFTER pacs_study_id;

-- Crear índice para mejor rendimiento
CREATE INDEX idx_pacs_series_id ON informes(pacs_series_id);

-- Verificar que se creó correctamente
SELECT 
    COLUMN_NAME,
    DATA_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'informes'
  AND COLUMN_NAME = 'pacs_series_id';
*/

