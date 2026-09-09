-- Agregar permisos DICOM en la categoría DICOM
-- Sistema TJSMEDICAL - Portal de Estudios Médicos

USE TJSMEDICAL;

-- Agregar permisos DICOM
INSERT INTO system_permissions (permission_key, permission_name, description, category) 
VALUES 
('dicom_query_retrieve', 'QUERY/RETRIEVE', 'Permite realizar consultas y recuperación de estudios DICOM desde el servidor PACS', 'dicom'),
('dicom_web', 'DICOMWeb', 'Permite acceder a funcionalidades DICOMWeb para consulta y recuperación de estudios', 'dicom')
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

-- Verificar que los permisos se insertaron correctamente
SELECT * FROM system_permissions 
WHERE category = 'dicom' 
ORDER BY permission_key;

