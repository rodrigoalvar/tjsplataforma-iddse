<?php
/**
 * Registra el permiso estudios_mixed_search en system_permissions.
 * Ejecutar una vez: php api/users/add_estudios_mixed_search_permission.php
 */
require_once __DIR__ . '/../../config/database.php';

$pdo = getDBConnection();
if (!$pdo) {
    fwrite(STDERR, "Sin conexión BD\n");
    exit(1);
}

$sql = "INSERT INTO system_permissions (permission_key, permission_name, description, category)
VALUES ('estudios_mixed_search', 'Estudios: búsqueda mixta (local + remoto)',
'Permite en estudios-manager unir resultados del PACS local con C-FIND en nodos remotos.',
'estudios')
ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name), description = VALUES(description), category = VALUES(category)";
$pdo->exec($sql);
echo "OK: estudios_mixed_search\n";
