-- Agregar permiso para marcar informes como incompletos
-- Este permiso controla quién puede marcar/desmarcar estudios como incompletos desde informes-manager

INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('marcar_incompletos', 'Marcar Incompletos', 'Permite marcar y desmarcar estudios como informes incompletos', 'informes')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

