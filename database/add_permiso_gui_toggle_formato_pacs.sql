-- Permiso GUI: selector PDF/IMG en Gestión de Informes.
-- Preferí ejecutar: php api/users/add_gui_toggle_formato_pacs_permission.php
-- Si ejecutás SQL a mano y ya existe la fila, omití este INSERT.

INSERT INTO system_permissions (permission_key, permission_name, description, category)
VALUES (
    'gui_toggle_formato_pacs',
    'Selector formato PACS (PDF/IMG)',
    'En Gestión de Informes: muestra y permite cambiar el interruptor global PDF vs imagen al enviar a PACS. Sin este permiso, quien pueda enviar a PACS usa solo el formato de la configuración global.',
    'interfaz'
);
