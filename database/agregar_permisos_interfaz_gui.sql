-- Agregar permisos de INTERFAZ/GUI para controlar visibilidad y estado activo del sidebar
-- Sistema TJSMEDICAL - Portal de Estudios Médicos

USE TJSMEDICAL;

-- Agregar permisos de interfaz/GUI
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('gui_dashboard', 'Dashboard Visible', 'Controla la visibilidad y estado activo del acceso Dashboard en el sidebar', 'interfaz'),
('gui_estudios', 'Estudios Visible', 'Controla la visibilidad y estado activo del acceso Estudios en el sidebar', 'interfaz'),
('gui_informes', 'Informes Visible', 'Controla la visibilidad y estado activo del acceso Informes en el sidebar', 'interfaz'),
('gui_gestion_informes', 'Gestión Informes Visible', 'Controla la visibilidad y estado activo del acceso Gestión Informes en el sidebar', 'interfaz'),
('gui_gestion_estudios', 'Gestión Estudios Visible', 'Controla la visibilidad y estado activo del acceso Gestión Estudios en el sidebar', 'interfaz'),
('gui_gestion_pacientes', 'Gestión Pacientes Visible', 'Controla la visibilidad y estado activo del acceso Gestión Pacientes en el sidebar', 'interfaz'),
('gui_grabacion', 'Grabación Visible', 'Controla la visibilidad y estado activo del acceso Grabación en el sidebar', 'interfaz'),
('gui_visor_dicom', 'Visor DICOM Visible', 'Controla la visibilidad y estado activo del acceso Visor DICOM en el sidebar', 'interfaz'),
('gui_workspace', 'WorkSpace Visible', 'Controla la visibilidad y estado activo del acceso WorkSpace en el sidebar', 'interfaz'),
('gui_gestion_usuarios', 'Gestión Usuarios Visible', 'Controla la visibilidad y estado activo del acceso Gestión Usuarios en el sidebar', 'interfaz'),
('gui_antecedentes', 'Antecedentes Visible', 'Controla la visibilidad del botón de Antecedentes en estudios-manager', 'interfaz')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- Verificar que los permisos se insertaron correctamente
SELECT * FROM system_permissions 
WHERE category = 'interfaz' 
ORDER BY permission_key;

