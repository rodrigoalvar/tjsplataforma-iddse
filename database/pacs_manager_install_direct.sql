-- Script SQL Directo para Instalar Módulo PACS Manager
-- Sistema TJSMEDICAL - Portal de Estudios Médicos
-- 
-- INSTRUCCIONES:
-- 1. Ejecutar este script directamente en MySQL/MariaDB
-- 2. O usar: mysql -u root -p tjsmedical < database/pacs_manager_install_direct.sql

USE tjsmedical;

-- =====================================================
-- ELIMINAR PERMISOS EXISTENTES (si existen)
-- =====================================================
DELETE FROM system_permissions WHERE permission_key = 'pacs_manager';
DELETE FROM system_permissions WHERE permission_key = 'gui_pacs_manager';

-- =====================================================
-- CREAR PERMISOS CON CATEGORÍAS CORRECTAS
-- =====================================================

-- Permiso funcional - CATEGORÍA: admin (aparecerá en sección "Administración")
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('pacs_manager', 'Gestión PACS', 'Permite editar y eliminar estudios en el servidor PACS (Orthanc)', 'admin');

-- Permiso de interfaz - CATEGORÍA: interfaz (aparecerá en sección "Interfaz/GUI")
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('gui_pacs_manager', 'PACS Manager Visible', 'Controla la visibilidad y estado activo del acceso PACS Manager en el sidebar', 'interfaz');

-- =====================================================
-- VERIFICAR QUE SE CREARON CORRECTAMENTE
-- =====================================================
SELECT 
    permission_key,
    permission_name,
    category,
    CASE 
        WHEN category = 'admin' THEN 'Administración'
        WHEN category = 'interfaz' THEN 'Interfaz/GUI'
        ELSE category
    END as categoria_display,
    description
FROM system_permissions 
WHERE permission_key IN ('pacs_manager', 'gui_pacs_manager')
ORDER BY permission_key;

-- =====================================================
-- VERIFICAR QUE ESTÁ EN LA CATEGORÍA CORRECTA
-- =====================================================
SELECT 
    COUNT(*) as total_permisos,
    SUM(CASE WHEN permission_key = 'pacs_manager' AND category = 'admin' THEN 1 ELSE 0 END) as pacs_manager_ok,
    SUM(CASE WHEN permission_key = 'gui_pacs_manager' AND category = 'interfaz' THEN 1 ELSE 0 END) as gui_pacs_manager_ok
FROM system_permissions 
WHERE permission_key IN ('pacs_manager', 'gui_pacs_manager');



