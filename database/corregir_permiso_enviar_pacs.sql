-- Corregir permiso "Enviar a PACS" a la categoría Informes
-- Sistema TJSMEDICAL - Portal de Estudios Médicos

USE TJSMEDICAL;

-- Actualizar el permiso "Enviar a PACS" para que esté en la categoría "informes" en lugar de "estudios"
UPDATE system_permissions 
SET category = 'informes',
    permission_name = 'Enviar a PACS',
    description = 'Permite enviar informes médicos a PACS'
WHERE permission_key = 'enviar_pacs';

-- Si el permiso no existe, crearlo en la categoría "informes"
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('enviar_pacs', 'Enviar a PACS', 'Permite enviar informes médicos a PACS', 'informes')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- Verificar que el permiso esté en la categoría correcta
SELECT permission_key, permission_name, category, description
FROM system_permissions 
WHERE permission_key = 'enviar_pacs';

-- Mostrar todos los permisos de la categoría "informes"
SELECT permission_key, permission_name, category, description
FROM system_permissions 
WHERE category = 'informes'
ORDER BY permission_name;
