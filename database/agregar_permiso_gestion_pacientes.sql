-- Script para agregar el permiso "Gestión Pacientes" en la tabla system_permissions
-- Sistema TJSMEDICAL - Portal de Estudios Médicos
-- Categoría: Administración (admin)

USE TJSMEDICAL;

-- Verificar si el permiso ya existe
SELECT COUNT(*) as count FROM system_permissions WHERE permission_key = 'pacientes';

-- Insertar el permiso si no existe
INSERT INTO system_permissions (permission_key, permission_name, description, category, created_at) 
VALUES 
('pacientes', 'Gestión Pacientes', 'Permite gestionar pacientes del sistema', 'admin', NOW())
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- Verificar que se insertó correctamente
SELECT * FROM system_permissions WHERE permission_key = 'pacientes';

-- Mostrar todos los permisos de la categoría Administración
SELECT permission_key, permission_name, description, category 
FROM system_permissions 
WHERE category = 'admin'
ORDER BY permission_key;


