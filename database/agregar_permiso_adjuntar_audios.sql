-- Agregar permiso "Adjuntar Audios" en la categoría Audio
-- Este permiso permite adjuntar archivos de audio a informes

-- Verificar si el permiso ya existe antes de insertarlo
INSERT INTO system_permissions (permission_key, permission_name, description, category)
SELECT 'adjuntar_audios', 'Adjuntar Audios', 'Permite adjuntar archivos de audio a informes', 'audio'
WHERE NOT EXISTS (
    SELECT 1 FROM system_permissions 
    WHERE permission_key = 'adjuntar_audios' 
    AND category = 'audio'
)
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- Verificar la inserción
SELECT * FROM system_permissions 
WHERE category = 'audio' 
ORDER BY permission_key;

