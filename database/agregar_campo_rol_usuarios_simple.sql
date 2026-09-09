-- Script SQL para agregar campo de rol/perfil a la tabla usuarios
-- Sistema TJSMEDICAL - Portal de Estudios Médicos
-- Versión simplificada compatible con MySQL 5.7+
-- Si la columna ya existe, el script mostrará un error pero continuará

-- =====================================================
-- AGREGAR CAMPO ROL A LA TABLA USUARIOS
-- =====================================================

-- Agregar columna rol con valores ENUM (ignorar error si ya existe)
ALTER TABLE usuarios 
ADD COLUMN rol ENUM('medico_informante', 'transcriptor', 'otro') NULL DEFAULT NULL 
COMMENT 'Rol o perfil del usuario: Médico Informante, Transcriptor, u otro';

-- Agregar índice para optimizar búsquedas por rol (ignorar error si ya existe)
ALTER TABLE usuarios 
ADD INDEX idx_rol (rol);

-- Agregar índice compuesto para búsquedas por nivel y rol (ignorar error si ya existe)
ALTER TABLE usuarios 
ADD INDEX idx_nivel_rol (nivel, rol);

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
