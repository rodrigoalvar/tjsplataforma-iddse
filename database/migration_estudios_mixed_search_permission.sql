-- Permiso: búsqueda mixta (Orthanc local + nodo remoto) en estudios-manager
INSERT INTO system_permissions (permission_key, permission_name, description, category)
VALUES (
    'estudios_mixed_search',
    'Estudios: búsqueda mixta (local + remoto)',
    'Permite en estudios-manager unir resultados del PACS local con C-FIND en nodos remotos configurados en PACS Nodes Manager.',
    'estudios'
) ON DUPLICATE KEY UPDATE
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);
