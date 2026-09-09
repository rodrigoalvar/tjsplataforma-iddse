-- Agregar permiso "Plantillas Globales" en la categoría Plantillas
-- Sistema TJSMEDICAL - Portal de Estudios Médicos

USE tjsmedical;

-- Agregar permiso "Plantillas Globales" en la categoría Plantillas
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('plantillas_globales', 'Plantillas Globales', 'Permite crear plantillas globales del sistema que todos los usuarios pueden usar', 'plantillas')
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
        WHEN 'plantillas_globales' THEN 3
        ELSE 4
    END;





