-- Script SQL para migrar permisos desde portal_estudios a tjsplataforma
-- Sistema TJSMEDICAL - Plataforma
-- Este script agrega los permisos que están en portal_estudios pero no en tjsplataforma

-- =====================================================
-- PERMISOS DE ESTUDIOS
-- =====================================================

-- Asignar Prioridad
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('asignar_prioridad', 'Asignar Prioridad', 'Permite establecer la prioridad (urgente, promesa) de los estudios', 'estudios')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- Asignaciones
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('asignaciones', 'Asignaciones', 'Permite asignar estudios a usuarios', 'estudios')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- Derivaciones
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('derivaciones', 'Derivaciones', 'Permite derivar estudios a usuarios', 'estudios')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- Filtrar por Instituciones
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('filter_institutions', 'Filtrar por Instituciones', 'Permite filtrar estudios por instituciones', 'estudios')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- =====================================================
-- PERMISOS DE AUDIO
-- =====================================================

-- Dictado por Voz
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('dictado', 'Dictado por Voz', 'Permite usar dictado por voz para informes', 'audio')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- Grabación Sincronizada
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('grabacion_sincronizada', 'Grabación Sincronizada', 'Permite usar grabación sincronizada con transcripción', 'audio')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- Transcripción de Archivos de Audio
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('transcripcion_audio', 'Transcripción de Archivos de Audio', 'Permite transcribir archivos de audio', 'audio')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- Adjuntar Audios
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('adjuntar_audios', 'Adjuntar Audios', 'Permite adjuntar archivos de audio a informes', 'audio')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- =====================================================
-- PERMISOS DICOM
-- =====================================================

-- QUERY/RETRIEVE
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('dicom_query_retrieve', 'QUERY/RETRIEVE', 'Permite realizar consultas y recuperación de estudios DICOM desde el servidor PACS', 'dicom')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- DICOMWeb
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('dicom_web', 'DICOMWeb', 'Permite acceder a funcionalidades DICOMWeb para consulta y recuperación de estudios', 'dicom')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- =====================================================
-- PERMISOS DE INTERFAZ/GUI
-- =====================================================

-- Dashboard Visible
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('gui_dashboard', 'Dashboard Visible', 'Controla la visibilidad y estado activo del acceso Dashboard en el sidebar', 'interfaz')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- Estudios Visible
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('gui_estudios', 'Estudios Visible', 'Controla la visibilidad y estado activo del acceso Estudios en el sidebar', 'interfaz')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- Informes Visible
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('gui_informes', 'Informes Visible', 'Controla la visibilidad y estado activo del acceso Informes en el sidebar', 'interfaz')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- Gestión Informes Visible
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('gui_gestion_informes', 'Gestión Informes Visible', 'Controla la visibilidad y estado activo del acceso Gestión Informes en el sidebar', 'interfaz')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- Gestión Estudios Visible
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('gui_gestion_estudios', 'Gestión Estudios Visible', 'Controla la visibilidad y estado activo del acceso Gestión Estudios en el sidebar', 'interfaz')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- Gestión Pacientes Visible
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('gui_gestion_pacientes', 'Gestión Pacientes Visible', 'Controla la visibilidad y estado activo del acceso Gestión Pacientes en el sidebar', 'interfaz')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- Grabación Visible
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('gui_grabacion', 'Grabación Visible', 'Controla la visibilidad y estado activo del acceso Grabación en el sidebar', 'interfaz')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- Visor DICOM Visible
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('gui_visor_dicom', 'Visor DICOM Visible', 'Controla la visibilidad y estado activo del acceso Visor DICOM en el sidebar', 'interfaz')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- Gestión Usuarios Visible
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('gui_gestion_usuarios', 'Gestión Usuarios Visible', 'Controla la visibilidad y estado activo del acceso Gestión Usuarios en el sidebar', 'interfaz')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- =====================================================
-- VERIFICACIÓN
-- =====================================================

-- Mostrar todos los permisos agregados
SELECT permission_key, permission_name, category 
FROM system_permissions 
WHERE permission_key IN (
    'asignar_prioridad', 'asignaciones', 'derivaciones', 'filter_institutions',
    'dictado', 'grabacion_sincronizada', 'transcripcion_audio', 'adjuntar_audios',
    'dicom_query_retrieve', 'dicom_web',
    'gui_dashboard', 'gui_estudios', 'gui_informes', 'gui_gestion_informes',
    'gui_gestion_estudios', 'gui_gestion_pacientes', 'gui_grabacion',
    'gui_visor_dicom', 'gui_gestion_usuarios'
)
ORDER BY category, permission_key;
