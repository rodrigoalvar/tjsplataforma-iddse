-- Script SQL para agregar campos de médico informante y transcriptor a la tabla informes_historial
-- Sistema TJSMEDICAL - Portal de Estudios Médicos
-- Este script agrega campos para almacenar información del médico informante (dueño) y del transcriptor

-- =====================================================
-- AGREGAR CAMPOS A LA TABLA INFORMES_HISTORIAL
-- =====================================================

-- Verificar si las columnas existen antes de agregarlas
SET @dbname = DATABASE();
SET @tablename = 'informes_historial';

-- Agregar campos del médico informante (dueño del informe)
SET @columnname1 = 'medico_informante_id';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname1)
  ) > 0,
  'SELECT "Columna medico_informante_id ya existe" as mensaje',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname1, ' INT NULL COMMENT \'ID del médico informante (dueño del informe)\'')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

SET @columnname2 = 'medico_informante_nombre';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname2)
  ) > 0,
  'SELECT "Columna medico_informante_nombre ya existe" as mensaje',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname2, ' VARCHAR(100) NULL COMMENT \'Nombre del médico informante\'')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

SET @columnname3 = 'medico_informante_apellido';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname3)
  ) > 0,
  'SELECT "Columna medico_informante_apellido ya existe" as mensaje',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname3, ' VARCHAR(100) NULL COMMENT \'Apellido del médico informante\'')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

SET @columnname4 = 'medico_informante_rol';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname4)
  ) > 0,
  'SELECT "Columna medico_informante_rol ya existe" as mensaje',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname4, ' ENUM(\'medico_informante\', \'transcriptor\', \'otro\') NULL COMMENT \'Rol del médico informante\'')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Agregar campos del transcriptor (quien modificó/transcribió)
SET @columnname5 = 'transcriptor_id';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname5)
  ) > 0,
  'SELECT "Columna transcriptor_id ya existe" as mensaje',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname5, ' INT NULL COMMENT \'ID del transcriptor que modificó esta versión\'')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

SET @columnname6 = 'transcriptor_nombre';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname6)
  ) > 0,
  'SELECT "Columna transcriptor_nombre ya existe" as mensaje',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname6, ' VARCHAR(100) NULL COMMENT \'Nombre del transcriptor\'')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

SET @columnname7 = 'transcriptor_apellido';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname7)
  ) > 0,
  'SELECT "Columna transcriptor_apellido ya existe" as mensaje',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname7, ' VARCHAR(100) NULL COMMENT \'Apellido del transcriptor\'')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

SET @columnname8 = 'transcriptor_rol';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname8)
  ) > 0,
  'SELECT "Columna transcriptor_rol ya existe" as mensaje',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname8, ' ENUM(\'medico_informante\', \'transcriptor\', \'otro\') NULL COMMENT \'Rol del transcriptor\'')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Actualizar registros existentes del historial con datos del médico informante desde el informe original
UPDATE informes_historial ih
INNER JOIN informes i ON ih.informe_id = i.id
LEFT JOIN usuarios u_medico ON i.usuario_id = u_medico.id
SET 
    ih.medico_informante_id = i.usuario_id,
    ih.medico_informante_nombre = u_medico.nombre,
    ih.medico_informante_apellido = u_medico.apellido,
    ih.medico_informante_rol = COALESCE(i.medico_informante_rol, u_medico.rol)
WHERE ih.medico_informante_id IS NULL;

-- Actualizar registros existentes del historial con datos del transcriptor desde usuario_modificacion
UPDATE informes_historial ih
LEFT JOIN usuarios u_transcriptor ON ih.usuario_modificacion = u_transcriptor.id
SET 
    ih.transcriptor_id = ih.usuario_modificacion,
    ih.transcriptor_nombre = u_transcriptor.nombre,
    ih.transcriptor_apellido = u_transcriptor.apellido,
    ih.transcriptor_rol = u_transcriptor.rol
WHERE ih.transcriptor_id IS NULL;

-- Agregar índices para optimizar búsquedas
SET @indexname1 = 'idx_medico_informante_id';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (INDEX_NAME = @indexname1)
  ) > 0,
  'SELECT "Índice idx_medico_informante_id ya existe" as mensaje',
  CONCAT('ALTER TABLE ', @tablename, ' ADD INDEX ', @indexname1, ' (medico_informante_id)')
));
PREPARE alterIndexIfNotExists FROM @preparedStatement;
EXECUTE alterIndexIfNotExists;
DEALLOCATE PREPARE alterIndexIfNotExists;

SET @indexname2 = 'idx_transcriptor_id';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (INDEX_NAME = @indexname2)
  ) > 0,
  'SELECT "Índice idx_transcriptor_id ya existe" as mensaje',
  CONCAT('ALTER TABLE ', @tablename, ' ADD INDEX ', @indexname2, ' (transcriptor_id)')
));
PREPARE alterIndexIfNotExists FROM @preparedStatement;
EXECUTE alterIndexIfNotExists;
DEALLOCATE PREPARE alterIndexIfNotExists;

-- =====================================================
-- COMENTARIOS SOBRE LOS CAMPOS
-- =====================================================

-- Los campos del médico informante almacenan información del dueño del informe:
-- - medico_informante_id: ID del usuario que creó el informe originalmente
-- - medico_informante_nombre/apellido: Nombre completo del médico informante
-- - medico_informante_rol: Rol del médico informante (medico_informante, transcriptor, otro)

-- Los campos del transcriptor almacenan información de quién modificó/transcribió esta versión:
-- - transcriptor_id: ID del usuario que modificó esta versión específica
-- - transcriptor_nombre/apellido: Nombre completo del transcriptor
-- - transcriptor_rol: Rol del transcriptor (medico_informante, transcriptor, otro)

-- Esto permite diferenciar claramente entre:
-- - El médico informante (dueño del informe) que puede ser el mismo o diferente en cada versión
-- - El transcriptor que modificó cada versión específica

-- =====================================================
-- VERIFICACIÓN
-- =====================================================

-- Verificar que los campos se agregaron correctamente
SELECT 
    COLUMN_NAME, 
    COLUMN_TYPE, 
    IS_NULLABLE, 
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'informes_historial' 
  AND COLUMN_NAME IN ('medico_informante_id', 'medico_informante_nombre', 'medico_informante_apellido', 
                       'medico_informante_rol', 'transcriptor_id', 'transcriptor_nombre', 
                       'transcriptor_apellido', 'transcriptor_rol')
ORDER BY COLUMN_NAME;

-- Mostrar resumen de datos actualizados
SELECT 
    COUNT(*) as total_registros,
    COUNT(medico_informante_id) as con_medico_informante,
    COUNT(transcriptor_id) as con_transcriptor
FROM informes_historial;
