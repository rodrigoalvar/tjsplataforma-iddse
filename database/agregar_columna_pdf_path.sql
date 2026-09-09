-- Script para agregar la columna pdf_path a la tabla informes
-- Ejecutar este script si la columna pdf_path no existe

-- Verificar si la columna existe
SELECT 
    COLUMN_NAME,
    DATA_TYPE,
    CHARACTER_MAXIMUM_LENGTH,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'informes'
  AND COLUMN_NAME = 'pdf_path';

-- Agregar la columna pdf_path si no existe
-- Método 1: Si tu MySQL/MariaDB soporta IF NOT EXISTS
ALTER TABLE informes 
ADD COLUMN IF NOT EXISTS pdf_path VARCHAR(500) DEFAULT NULL 
COMMENT 'Ruta relativa del PDF del informe (ej: uploads/pdf_informes/informe_1_20250101_120000.pdf) para visualización/descarga desde portal del paciente'
AFTER fecha_enviado_pacs;

-- Método 2: Si no soporta IF NOT EXISTS, usar este script con verificación manual
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 'informes' 
                   AND COLUMN_NAME = 'pdf_path');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE informes ADD COLUMN pdf_path VARCHAR(500) DEFAULT NULL COMMENT "Ruta relativa del PDF del informe (ej: uploads/pdf_informes/informe_1_20250101_120000.pdf) para visualización/descarga desde portal del paciente" AFTER fecha_enviado_pacs',
    'SELECT "Columna pdf_path ya existe"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Si fecha_enviado_pacs no existe, agregar después de pacs_series_id o fecha_modificacion
-- Si necesitas verificar primero qué columnas existen:
SELECT COLUMN_NAME, ORDINAL_POSITION 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'informes'
  AND (COLUMN_NAME LIKE 'pacs%' OR COLUMN_NAME LIKE 'fecha%')
ORDER BY ORDINAL_POSITION;

-- Verificar que la columna se creó correctamente
SHOW COLUMNS FROM informes WHERE Field = 'pdf_path';

