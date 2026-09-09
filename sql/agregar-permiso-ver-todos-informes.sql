-- Script SQL para agregar el permiso "Ver Todos" en la categoría Informes
-- Sistema TJSMEDICAL - Portal de Estudios Médicos
-- Fecha: 2024

-- Insertar el nuevo permiso en la tabla system_permissions
INSERT INTO system_permissions (permission_key, permission_name, description, category, created_at, updated_at) 
VALUES (
    'verTodosInformes',
    'Ver Todos',
    'Permite ver todos los informes del sistema, no solo los creados por el usuario',
    'informes',
    NOW(),
    NOW()
);

-- Verificar que el permiso se insertó correctamente
SELECT * FROM system_permissions WHERE permission_key = 'verTodosInformes';

-- Mostrar todos los permisos de la categoría 'informes'
SELECT permission_key, permission_name, description, category 
FROM system_permissions 
WHERE category = 'informes' 
ORDER BY permission_name;
