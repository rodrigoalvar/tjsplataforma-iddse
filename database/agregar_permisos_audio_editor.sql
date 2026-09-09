-- Agregar permisos de audio específicos para editor.html
-- Sistema TJSMEDICAL - Portal de Estudios Médicos

USE TJSMEDICAL;

-- Agregar permisos de audio específicos
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('grabacion', 'Grabación de Audio', 'Permite grabar audios para informes', 'audio'),
('dictado', 'Dictado por Voz', 'Permite usar dictado por voz para informes', 'audio'),
('grabacion_sincronizada', 'Grabación Sincronizada', 'Permite usar grabación sincronizada con transcripción', 'audio'),
('transcripcion_audio', 'Transcripción de Archivos de Audio', 'Permite transcribir archivos de audio', 'audio')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- Verificar que los permisos se insertaron correctamente
SELECT * FROM system_permissions 
WHERE category = 'audio' 
ORDER BY permission_key;

