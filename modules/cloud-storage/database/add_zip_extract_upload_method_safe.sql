-- =====================================================
-- Agregar método 'zip-extract-upload' al ENUM upload_method
-- Safe version: verifica si el valor existe antes de agregar
-- =====================================================

-- Verificar si el valor ya existe en el ENUM
SET @enum_exists = (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'r2_queue' 
      AND COLUMN_NAME = 'upload_method'
      AND COLUMN_TYPE LIKE '%zip-extract-upload%'
);

-- Si no existe, modificar el ENUM
SET @sql = IF(@enum_exists = 0,
    'ALTER TABLE r2_queue MODIFY COLUMN upload_method ENUM(\'instance\',\'zip\',\'pre-download\',\'zip-extract-upload\') DEFAULT \'instance\'',
    'SELECT \'zip-extract-upload ya existe en el ENUM\' AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'Método zip-extract-upload agregado/verificado correctamente' AS resultado;
