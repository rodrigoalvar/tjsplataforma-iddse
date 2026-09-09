-- Agregar permiso "Antecedentes" en la categoría Estudios
-- Sistema TJSMEDICAL - Portal de Estudios Médicos

USE TJSMEDICAL;

-- Agregar permiso "Antecedentes" en la categoría Estudios
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('antecedentes', 'Antecedentes', 'Permite gestionar antecedentes de pacientes', 'estudios')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- Verificar que el permiso se insertó correctamente
SELECT * FROM system_permissions 
WHERE category = 'estudios' 
ORDER BY 
    CASE permission_key
        WHEN 'estudios' THEN 1
        WHEN 'pacs_query' THEN 2
        WHEN 'enviar_pacs' THEN 3
        WHEN 'antecedentes' THEN 4
        ELSE 5
    END;

