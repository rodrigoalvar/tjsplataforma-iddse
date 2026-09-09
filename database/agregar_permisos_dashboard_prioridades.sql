-- Agregar permisos de control de visibilidad de estudios prioritarios en Dashboard
-- Sistema TJSMEDICAL - Portal de Estudios Médicos

USE tjsmedical_iddse;

-- Agregar permisos de dashboard
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('ver_incompletos_otros', 'Ver Informes Incompletos de Otros', 'Permite ver estudios marcados como incompletos por otros usuarios en el dashboard', 'dashboard'),
('ver_urgentes_otros', 'Ver Prioridades de Estudios de Otros', 'Permite ver estudios con prioridad (urgente, promesa, pendiente) marcada por otros usuarios, aunque no esté asignada ni derivada a la cuenta', 'dashboard')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- Verificar que los permisos se insertaron correctamente
SELECT * FROM system_permissions 
WHERE category = 'dashboard' 
ORDER BY permission_key;
