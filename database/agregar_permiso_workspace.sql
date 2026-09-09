-- Agregar permiso de WorkSpace a la base de datos
-- Sistema TJSMEDICAL - Portal de Estudios Médicos

USE TJSMEDICAL;

-- Agregar permiso de WorkSpace
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('gui_workspace', 'WorkSpace Visible', 'Controla la visibilidad y estado activo del acceso WorkSpace en el sidebar', 'interfaz')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- Verificar que el permiso se insertó correctamente
SELECT * FROM system_permissions 
WHERE permission_key = 'gui_workspace';
