-- Script SQL para Módulo PACS Manager
-- Sistema TJSMEDICAL - Portal de Estudios Médicos
-- Este script agrega los permisos necesarios para el módulo de administración de estudios PACS

USE tjsmedical;

-- =====================================================
-- 1. AGREGAR PERMISOS DEL SISTEMA
-- =====================================================

-- Permiso funcional para gestionar estudios en PACS
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('pacs_manager', 'Gestión PACS', 'Permite editar y eliminar estudios en el servidor PACS (Orthanc)', 'admin')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- Permiso de interfaz para mostrar el acceso en el sidebar
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('gui_pacs_manager', 'PACS Manager Visible', 'Controla la visibilidad y estado activo del acceso PACS Manager en el sidebar', 'interfaz')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- Verificar que los permisos se insertaron correctamente
SELECT * FROM system_permissions 
WHERE permission_key IN ('pacs_manager', 'gui_pacs_manager')
ORDER BY permission_key;

