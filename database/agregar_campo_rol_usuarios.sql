-- Script SQL para agregar campo de rol/perfil a la tabla usuarios
-- Sistema TJSMEDICAL - Portal de Estudios Médicos
-- Este script agrega el campo 'rol' para diferenciar entre tipos de cuentas
-- como Médico Informante, Transcriptor, etc.
-- Compatible con MySQL 5.7+

-- =====================================================
-- AGREGAR CAMPO ROL A LA TABLA USUARIOS
-- =====================================================

-- Verificar si la columna existe antes de agregarla
SET @dbname = DATABASE();
SET @tablename = 'usuarios';
SET @columnname = 'rol';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  'SELECT "Columna rol ya existe" as mensaje', -- Columna ya existe, no hacer nada
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' ENUM(\'medico_informante\', \'transcriptor\', \'otro\') NULL DEFAULT NULL COMMENT \'Rol o perfil del usuario: Médico Informante, Transcriptor, u otro\'')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Verificar si el índice existe antes de agregarlo
SET @indexname = 'idx_rol';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (INDEX_NAME = @indexname)
  ) > 0,
  'SELECT "Índice idx_rol ya existe" as mensaje', -- Índice ya existe, no hacer nada
  CONCAT('ALTER TABLE ', @tablename, ' ADD INDEX ', @indexname, ' (', @columnname, ')')
));
PREPARE alterIndexIfNotExists FROM @preparedStatement;
EXECUTE alterIndexIfNotExists;
DEALLOCATE PREPARE alterIndexIfNotExists;

-- Verificar si el índice compuesto existe antes de agregarlo
SET @indexname2 = 'idx_nivel_rol';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (INDEX_NAME = @indexname2)
  ) > 0,
  'SELECT "Índice idx_nivel_rol ya existe" as mensaje', -- Índice ya existe, no hacer nada
  CONCAT('ALTER TABLE ', @tablename, ' ADD INDEX ', @indexname2, ' (nivel, ', @columnname, ')')
));
PREPARE alterIndex2IfNotExists FROM @preparedStatement;
EXECUTE alterIndex2IfNotExists;
DEALLOCATE PREPARE alterIndex2IfNotExists;

-- =====================================================
-- COMENTARIOS SOBRE EL CAMPO ROL
-- =====================================================

-- El campo 'rol' permite diferenciar tipos de cuentas:
-- - 'medico_informante': Cuentas de médicos que informan estudios
-- - 'transcriptor': Cuentas de transcriptores que transcriben informes
-- - 'otro': Otros tipos de cuentas o sin rol específico
-- - NULL: Sin rol asignado (para compatibilidad con usuarios existentes)

-- Este campo es independiente del campo 'nivel' (root, admin, user)
-- y permite una clasificación adicional de las cuentas según su función.

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
  AND TABLE_NAME = 'usuarios' 
  AND COLUMN_NAME = 'rol';

-- Mostrar distribución de roles (si hay datos)
SELECT 
    IFNULL(rol, 'Sin rol') as rol,
    COUNT(*) as cantidad,
    GROUP_CONCAT(CONCAT(nombre, ' ', apellido) SEPARATOR ', ') as usuarios
FROM usuarios 
WHERE activo = 1
GROUP BY rol;
