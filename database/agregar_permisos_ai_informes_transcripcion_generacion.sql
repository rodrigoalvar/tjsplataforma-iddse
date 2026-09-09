-- Agregar permisos para AI Informes: Transcribir con AI y Generar Informe con AI
-- Sistema TJSMEDICAL - Portal de Estudios Médicos
-- Base de datos: tjsmedical_iddse

USE tjsmedical_iddse;

-- Agregar permisos en la nueva categoría "ai_informes"
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('transcribir_ai', 'Transcribir con AI', 'Permite transcribir audios usando Whisper.cpp (AI)', 'ai_informes'),
('generar_informe_ai', 'Generar Informe con AI', 'Permite generar informes médicos usando Medgemma (AI)', 'ai_informes')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- Verificar que se agregaron correctamente
SELECT 'Permisos de AI Informes agregados correctamente' AS resultado;
SELECT permission_key, permission_name, category FROM system_permissions WHERE category = 'ai_informes';
