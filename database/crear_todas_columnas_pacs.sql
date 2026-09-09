-- Script para crear TODAS las columnas PACS necesarias en la tabla informes
-- Ejecutar este script si obtienes error: "Unknown column 'pacs_instance_id' in 'field list'"

-- 1. Verificar columnas existentes
SELECT 
    COLUMN_NAME,
    DATA_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'informes'
  AND (COLUMN_NAME LIKE 'pacs%' OR COLUMN_NAME LIKE 'fecha_enviado%')
ORDER BY ORDINAL_POSITION;

-- 2. Crear todas las columnas PACS necesarias
-- NOTA: Si alguna columna ya existe, dará error, pero puedes ignorarlo

-- 2.1. pacs_instance_id - ID de instancia DICOM en Orthanc
ALTER TABLE informes 
ADD COLUMN IF NOT EXISTS pacs_instance_id VARCHAR(255) DEFAULT NULL 
AFTER fecha_modificacion;

-- 2.2. pacs_study_id - ID de estudio DICOM en Orthanc
ALTER TABLE informes 
ADD COLUMN IF NOT EXISTS pacs_study_id VARCHAR(255) DEFAULT NULL 
AFTER pacs_instance_id;

-- 2.3. pacs_series_id - ID de serie DICOM en Orthanc (CRÍTICO - para eliminar serie anterior)
ALTER TABLE informes 
ADD COLUMN IF NOT EXISTS pacs_series_id VARCHAR(255) DEFAULT NULL 
AFTER pacs_study_id;

-- 2.4. fecha_enviado_pacs - Fecha del último envío a PACS (opcional pero útil)
ALTER TABLE informes 
ADD COLUMN IF NOT EXISTS fecha_enviado_pacs DATETIME DEFAULT NULL 
AFTER pacs_series_id;

-- 2.5. pdf_path - Ruta relativa del PDF generado para visualización/descarga desde portal del paciente
ALTER TABLE informes 
ADD COLUMN IF NOT EXISTS pdf_path VARCHAR(500) DEFAULT NULL 
COMMENT 'Ruta relativa del PDF del informe (ej: uploads/pdf_informes/informe_1_20250101_120000.pdf) para visualización/descarga desde portal del paciente'
AFTER fecha_enviado_pacs;

-- 3. Si tu versión de MySQL no soporta IF NOT EXISTS, usar este método alternativo:
-- Primero verificar, luego crear solo las que no existen

-- 3.1. Crear pacs_instance_id (si no existe)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 'informes' 
                   AND COLUMN_NAME = 'pacs_instance_id');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE informes ADD COLUMN pacs_instance_id VARCHAR(255) DEFAULT NULL AFTER fecha_modificacion',
    'SELECT "Columna pacs_instance_id ya existe"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3.2. Crear pacs_study_id (si no existe)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 'informes' 
                   AND COLUMN_NAME = 'pacs_study_id');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE informes ADD COLUMN pacs_study_id VARCHAR(255) DEFAULT NULL AFTER pacs_instance_id',
    'SELECT "Columna pacs_study_id ya existe"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3.3. Crear pacs_series_id (si no existe) - CRÍTICO
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 'informes' 
                   AND COLUMN_NAME = 'pacs_series_id');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE informes ADD COLUMN pacs_series_id VARCHAR(255) DEFAULT NULL AFTER pacs_study_id',
    'SELECT "Columna pacs_series_id ya existe"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3.4. Crear fecha_enviado_pacs (si no existe) - Opcional
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 'informes' 
                   AND COLUMN_NAME = 'fecha_enviado_pacs');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE informes ADD COLUMN fecha_enviado_pacs DATETIME DEFAULT NULL AFTER pacs_series_id',
    'SELECT "Columna fecha_enviado_pacs ya existe"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3.5. Crear pdf_path (si no existe) - Ruta del PDF para visualización/descarga
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

-- 4. Crear índices para mejor rendimiento (opcional pero recomendado)
CREATE INDEX IF NOT EXISTS idx_pacs_instance_id ON informes(pacs_instance_id);
CREATE INDEX IF NOT EXISTS idx_pacs_study_id ON informes(pacs_study_id);
CREATE INDEX IF NOT EXISTS idx_pacs_series_id ON informes(pacs_series_id);

-- Si tu versión de MySQL no soporta IF NOT EXISTS en CREATE INDEX:
-- Primero verificar si existe, luego crear solo si no existe

-- 5. Verificar que todas las columnas se crearon correctamente
SELECT 
    COLUMN_NAME,
    DATA_TYPE,
    CHARACTER_MAXIMUM_LENGTH,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    ORDINAL_POSITION
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'informes'
  AND (COLUMN_NAME LIKE 'pacs%' OR COLUMN_NAME LIKE 'fecha_enviado%' OR COLUMN_NAME LIKE 'pdf_path%')
ORDER BY ORDINAL_POSITION;

-- 6. Verificar estructura final de la tabla informes
SHOW COLUMNS FROM informes;

