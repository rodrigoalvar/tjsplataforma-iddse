-- Script para agregar campo de instituciones permitidas a usuarios
-- Sistema TJSMEDICAL - Portal de Estudios Médicos

-- Agregar campo para almacenar instituciones permitidas (JSON)
-- Verificar si la columna existe antes de agregarla
SET @dbname = DATABASE();
SET @tablename = 'usuarios';
SET @columnname = 'instituciones_permitidas';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' JSON NULL COMMENT ''Array JSON con nombres de instituciones permitidas para filtrar estudios''')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Crear índice para búsquedas rápidas (aunque JSON no se indexa directamente, ayuda con consultas)
-- Nota: MySQL 5.7+ soporta índices virtuales generados para JSON, pero por simplicidad
-- usaremos búsquedas directas en el JSON

-- Agregar permiso al sistema de permisos
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('filter_institutions', 'Filtrar por Instituciones', 'Permite restringir la visualización de estudios a instituciones específicas', 'estudios')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

