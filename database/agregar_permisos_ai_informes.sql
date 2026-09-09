-- Agregar permisos de AI Informes
-- Sistema TJSMEDICAL - Portal de Estudios Médicos
-- Permisos: gui_ai_informes (GUI) y ai_informes (acceso)

USE tjsmedical_iddse;

-- Agregar permiso GUI para mostrar/ocultar AI Informes en el sidebar
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('gui_ai_informes', 'AI Informes Visible', 'Controla la visibilidad y estado activo del acceso AI Informes en el sidebar', 'interfaz')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- Agregar permiso de acceso para usar la sección AI Informes
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('ai_informes', 'Acceso a AI Informes', 'Permite acceder y usar la sección de AI Informes (Whisper + Medgemma)', 'informes')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- Verificar que los permisos se insertaron correctamente
SELECT * FROM system_permissions 
WHERE permission_key IN ('gui_ai_informes', 'ai_informes')
ORDER BY permission_key;
