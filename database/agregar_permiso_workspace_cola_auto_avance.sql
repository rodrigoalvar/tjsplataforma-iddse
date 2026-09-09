-- Permiso: auto-avance en cola de estudios del WorkSpace
-- Sistema TJSMEDICAL - Portal de Estudios Médicos
-- Ejecutar contra la BD de la aplicación (ej. tjsmedical_iddse)

INSERT INTO system_permissions (permission_key, permission_name, description, category)
VALUES (
    'workspace_cola_auto_avance',
    'Cola WorkSpace — Auto-avance',
    'Permite activar el auto-avance al siguiente estudio tras finalizar el informe (Shift+clic en Siguiente estudio en workspace)',
    'interfaz'
)
ON DUPLICATE KEY UPDATE
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    category = VALUES(category);

SELECT * FROM system_permissions WHERE permission_key = 'workspace_cola_auto_avance';
