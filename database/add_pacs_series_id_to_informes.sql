-- Agregar columna pacs_series_id para implementar el flujo propuesto de gestión de informes engeal PACS
-- Este campo almacena el SeriesID que retorna Orthanc al crear el PDF como DICOM
-- Se usa para eliminar la serie completa cuando se actualiza el informe

USE TJSMEDICAL;

-- Verificar si la columna existe antes de agregarla (compatible con MySQL 5.7+)
SET @dbname = DATABASE();
SET @tablename = 'informes';
SET @columnname = 'pacs_series_id';

-- Verificar si la columna existe
SET @column_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE table_schema = @dbname
    AND table_name = @tablename
    AND column_name = @columnname
);

-- Agregar columna solo si no existe
SET @sql = IF(@column_exists = 0,
    CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' VARCHAR(255) NULL COMMENT ''ID de la serie en Orthanc donde se almacenó el PDF. Usado para eliminar la serie completa al actualizar el informe.'';'),
    'SELECT 1 as columna_ya_existe;'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verificar si el índice existe antes de crearlo
SET @indexname = 'idx_pacs_series_id';
SET @index_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE table_schema = @dbname
    AND table_name = @tablename
    AND index_name = @indexname
);

-- Crear índice solo si no existe
SET @sql = IF(@index_exists = 0,
    CONCAT('CREATE INDEX ', @indexname, ' ON ', @tablename, '(', @columnname, ');'),
    'SELECT 1 as indice_ya_existe;'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Notas de implementación:
-- - Este campo almacena el ParentSeries de la respuesta de Orthanc al crear DICOM
-- - Al actualizar un informe, se elimina la serie completa usando este ID
-- - Esto es más robusto que eliminar solo la instancia (elimina toda la serie si hay múltiples objetos)
-- - El flujo propuesto prefiere trabajar a nivel de serie en lugar de instancia
