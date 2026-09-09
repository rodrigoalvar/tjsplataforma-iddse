-- Script SQL para agregar campo de rol del médico informante a la tabla informes
-- Sistema TJSMEDICAL - Portal de Estudios Médicos
-- Este script agrega el campo 'medico_informante_rol' para almacenar el rol del médico
-- que creó el informe, independientemente de quién lo transcriba

-- =====================================================
-- AGREGAR CAMPO MEDICO_INFORMANTE_ROL A LA TABLA INFORMES
-- =====================================================

-- Verificar si la columna existe antes de agregarla
SET @dbname = DATABASE();
SET @tablename = 'informes';
SET @columnname = 'medico_informante_rol';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  'SELECT "Columna medico_informante_rol ya existe" as mensaje', -- Columna ya existe, no hacer nada
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' ENUM(\'medico_informante\', \'transcriptor\', \'otro\') NULL DEFAULT NULL COMMENT \'Rol del médico informante que creó el informe. Se almacena al momento de creación para futuras funciones.\'')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Actualizar informes existentes con el rol del usuario que los creó
UPDATE informes i
INNER JOIN usuarios u ON i.usuario_id = u.id
SET i.medico_informante_rol = u.rol
WHERE i.medico_informante_rol IS NULL AND u.rol IS NOT NULL;

-- Agregar índice para optimizar búsquedas por rol del médico informante
SET @indexname = 'idx_medico_informante_rol';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (INDEX_NAME = @indexname)
  ) > 0,
  'SELECT "Índice idx_medico_informante_rol ya existe" as mensaje', -- Índice ya existe, no hacer nada
  CONCAT('ALTER TABLE ', @tablename, ' ADD INDEX ', @indexname, ' (', @columnname, ')')
));
PREPARE alterIndexIfNotExists FROM @preparedStatement;
EXECUTE alterIndexIfNotExists;
DEALLOCATE PREPARE alterIndexIfNotExists;

-- =====================================================
-- COMENTARIOS SOBRE EL CAMPO MEDICO_INFORMANTE_ROL
-- =====================================================

-- El campo 'medico_informante_rol' almacena el rol del médico que creó el informe.
-- Esto permite diferenciar entre:
-- - 'medico_informante': El médico que informa el estudio (dueño del informe)
-- - 'transcriptor': El transcriptor que solo transcribe el informe
-- - 'otro': Otros tipos de cuentas

-- Este campo se almacena al momento de creación del informe para mantener
-- un registro histórico del rol del usuario, incluso si el usuario cambia de rol después.

-- IMPORTANTE: Este campo es independiente del campo usuario_id y permite
-- identificar quién es el "dueño" del informe (médico informante) vs quién
-- lo transcribe (transcriptor).

-- =====================================================
-- VERIFICACIÓN
-- =====================================================

-- Verificar que el campo se agregó correctamente
SELECT 
    COLUMN_NAME, 
    COLUMN_TYPE, 
    IS_NULLABLE, 
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'informes' 
  AND COLUMN_NAME = 'medico_informante_rol';

-- Mostrar distribución de roles en informes (si hay datos)
SELECT 
    IFNULL(medico_informante_rol, 'Sin rol') as rol,
    COUNT(*) as cantidad_informes
FROM informes 
GROUP BY medico_informante_rol;
