-- Agregar permisos para Papelera de Audios
-- Sistema TJSMEDICAL - Portal de Estudios Médicos

USE tjsmedical_iddse;

-- Insertar permisos si no existen
INSERT IGNORE INTO system_permissions (permission_key, permission_name, description, category) VALUES
('papelera_audios', 'Acceso Papelera de Audios', 'Permite acceder a la papelera de audios para ver y recuperar audios', 'audios'),
('papelera_audios_all', 'Ver Todos los Audios en Papelera', 'Permite ver audios de todos los usuarios en la papelera. Sin este permiso, solo verá sus propios audios.', 'audios'),
('papelera_audios_delete', 'Eliminar Permanentemente de Papelera', 'Permite eliminar permanentemente audios de la papelera. Solo usuarios ROOT tienen este permiso por defecto.', 'audios');

SELECT 'Permisos de papelera de audios agregados exitosamente' AS resultado;
