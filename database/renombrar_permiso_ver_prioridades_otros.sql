-- Renombrar permiso ver_urgentes_otros en user-management (clave técnica sin cambios)
-- Sistema TJSMEDICAL - Portal de Estudios Médicos

USE tjsmedical_iddse;

UPDATE system_permissions
SET
    permission_name = 'Ver Prioridades de Estudios de Otros',
    description = 'Permite ver estudios con prioridad (urgente, promesa, pendiente) marcada por otros usuarios, aunque no esté asignada ni derivada a la cuenta'
WHERE permission_key = 'ver_urgentes_otros';

SELECT permission_key, permission_name, description, category
FROM system_permissions
WHERE permission_key = 'ver_urgentes_otros';
