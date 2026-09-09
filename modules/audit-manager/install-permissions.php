<?php
/**
 * Registra permisos del módulo Audit Manager en system_permissions.
 * Uso: /modules/audit-manager/install-permissions.php
 */
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Permisos — Audit Manager</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 40px auto; padding: 20px; background: #f5f5f5; }
        .container { background: #fff; padding: 28px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
        .success { background: #d4edda; color: #155724; padding: 12px; border-radius: 6px; margin: 10px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin: 10px 0; }
        .btn { display: inline-block; padding: 10px 18px; background: #0d6efd; color: #fff; text-decoration: none; border-radius: 6px; margin: 8px 8px 0 0; }
    </style>
</head>
<body>
<div class="container">
    <h1>Permisos — Audit Manager</h1>
<?php
try {
    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        throw new Exception('Sin conexión a la base de datos');
    }

    $permissions = [
        [
            'permission_key' => 'audit_manager',
            'permission_name' => 'Auditoría del sistema',
            'description' => 'Consultar eventos, sesiones activas, estadísticas y revocar sesiones',
            'category' => 'administracion',
        ],
        [
            'permission_key' => 'gui_audit_manager',
            'permission_name' => 'Ver menú Auditoría',
            'description' => 'Mostrar el enlace Auditoría en el sidebar',
            'category' => 'interfaz',
        ],
    ];

    $inserted = 0;
    $updated = 0;

    foreach ($permissions as $perm) {
        $check = $db->prepare('SELECT id FROM system_permissions WHERE permission_key = ?');
        $check->execute([$perm['permission_key']]);
        if ($check->fetch()) {
            $u = $db->prepare('UPDATE system_permissions SET permission_name = ?, description = ?, category = ? WHERE permission_key = ?');
            $u->execute([$perm['permission_name'], $perm['description'], $perm['category'], $perm['permission_key']]);
            $updated++;
            echo '<div class="success">Actualizado: <strong>' . htmlspecialchars($perm['permission_key']) . '</strong></div>';
        } else {
            $i = $db->prepare('INSERT INTO system_permissions (permission_key, permission_name, description, category, created_at) VALUES (?,?,?,?,NOW())');
            $i->execute([$perm['permission_key'], $perm['permission_name'], $perm['description'], $perm['category']]);
            $inserted++;
            echo '<div class="success">Creado: <strong>' . htmlspecialchars($perm['permission_key']) . '</strong></div>';
        }
    }

    echo '<p><strong>Resumen:</strong> creados ' . $inserted . ', actualizados ' . $updated . '.</p>';
    echo '<p>Asigná <code>audit_manager</code> y <code>gui_audit_manager</code> en Gestión de usuarios. Los usuarios <code>root</code> o con permiso <code>all</code> ya tienen acceso.</p>';
} catch (Exception $e) {
    echo '<div class="error">' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>
    <a class="btn" href="install.php">Volver a instalación</a>
    <a class="btn" href="../../user-management.html" style="background:#198754;">Gestión usuarios</a>
    <a class="btn" href="../../dashboard-unified.html" style="background:#6c757d;">Dashboard</a>
</div>
</body>
</html>
