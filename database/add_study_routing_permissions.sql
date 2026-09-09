-- Permisos Study routing (ejecutar en BD del portal)
INSERT IGNORE INTO system_permissions (permission_key, permission_name, description, category) VALUES
('gui_study_routing', 'Study routing (config visible)', 'Muestra la sección Study routing en Configuración del sistema', 'interfaz'),
('study_routing', 'Study routing (uso R2)', 'Permite que se apliquen enlaces de visor/descarga vía R2 cuando la política lo indique', 'estudios'),
('study_routing_manage', 'Study routing (administrar)', 'Puede cambiar la política global y el modo por usuario (root/admin según asignación)', 'administracion');
