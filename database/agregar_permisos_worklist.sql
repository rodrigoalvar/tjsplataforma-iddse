-- Agregar permisos de Worklist
-- Sistema TJSMEDICAL - Portal de Estudios Médicos
-- Permisos: gui_worklist (GUI) y worklist (acceso)

USE TJSMEDICAL;

-- Agregar permiso GUI para mostrar/ocultar Worklist en el sidebar
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('gui_worklist', 'Worklist Visible', 'Controla la visibilidad y estado activo del acceso Worklist en el sidebar', 'interfaz')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- Agregar permiso de acceso para usar la sección Worklist
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('worklist', 'Acceso a Worklist', 'Permite acceder y usar la sección de Worklist', 'estudios')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- Verificar que los permisos se insertaron correctamente
SELECT * FROM system_permissions 
WHERE permission_key IN ('gui_worklist', 'worklist')
ORDER BY permission_key;
