-- Script SQL para corregir la categoría del permiso pacs_manager
-- Sistema TJSMEDICAL - Portal de Estudios Médicos
-- Ejecutar este script si el permiso fue creado con categoría incorrecta

USE tjsmedical;

-- Actualizar la categoría del permiso pacs_manager a 'admin' (Administración)
UPDATE system_permissions 
SET category = 'admin',
    description = 'Permite editar y eliminar estudios en el servidor PACS (Orthanc)'
WHERE permission_key = 'pacs_manager';

-- Verificar que se actualizó correctamente
SELECT permission_key, permission_name, category, description 
FROM system_permissions 
WHERE permission_key = 'pacs_manager';



