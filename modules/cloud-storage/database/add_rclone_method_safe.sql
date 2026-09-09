-- =====================================================
-- Agregar método 'rclone' al ENUM upload_method
-- Safe version: verifica si el valor existe antes de agregar
-- Compatible con MySQL 5.7+
-- =====================================================

-- Verificar si el valor ya existe en el ENUM
SET @enum_exists = (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'r2_queue' 
      AND COLUMN_NAME = 'upload_method'
      AND COLUMN_TYPE LIKE '%rclone%'
);

-- Si no existe, agregar 'rclone' al ENUM
-- Construir el ENUM completo manualmente para evitar problemas de escape
SET @sql = IF(@enum_exists = 0,
    'ALTER TABLE r2_queue MODIFY COLUMN upload_method ENUM(\'instance\',\'zip\',\'pre-download\',\'zip-extract-upload\',\'rclone\') DEFAULT \'instance\'',
    'SELECT ''rclone ya existe en el ENUM'' AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'Método rclone agregado/verificado correctamente' AS resultado;
