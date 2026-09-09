-- Agregar permiso "Ver Todas" en la categoría Plantillas
-- Sistema TJSMEDICAL - Portal de Estudios Médicos

USE TJSMEDICAL;

-- Agregar permiso "Ver Todas" después de "Gestión de Plantillas" en la categoría Plantillas
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('ver_todas_plantillas', 'Ver Todas', 'Permite ver las plantillas de cualquier usuario en el sistema', 'plantillas')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- Verificar que el permiso se insertó correctamente
SELECT * FROM system_permissions 
WHERE category = 'plantillas' 
ORDER BY 
    CASE permission_key
        WHEN 'plantillas' THEN 1
        WHEN 'ver_todas_plantillas' THEN 2
        ELSE 3
    END;

