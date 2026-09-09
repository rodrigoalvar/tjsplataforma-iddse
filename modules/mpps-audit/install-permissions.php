<?php
/**
 * Registra permisos del módulo MPPS Audit en system_permissions.
 * Uso: /modules/mpps-audit/install-permissions.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permisos — MPPS Audit</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 40px auto; padding: 20px; background: #f5f5f5; }
        .container { background: #fff; padding: 28px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
        h1 { color: #8B0D32; }
        .success { background: #d4edda; color: #155724; padding: 12px; border-radius: 6px; margin: 8px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin: 8px 0; }
        .info { background: #d1ecf1; color: #0c5460; padding: 12px; border-radius: 6px; margin: 8px 0; }
        .btn { display: inline-block; padding: 10px 18px; background: #8B0D32; color: #fff; text-decoration: none; border-radius: 6px; margin: 8px 8px 0 0; }
        .btn-secondary { background: #6c757d; }
        .btn-success { background: #198754; }
        code { background: #f1f1f1; padding: 2px 6px; border-radius: 4px; }
    </style>
</head>
<body>
<div class="container">
    <h1>Permisos — MPPS Audit</h1>
<?php
try {
    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        throw new Exception('Sin conexión a la base de datos');
    }

    $permissions = [
        [
            'permission_key' => 'mpps_audit',
            'permission_name' => 'Auditoría MPPS',
            'description' => 'Acceso a APIs y datos de eventos MPPS (equipos DICOM)',
            'category' => 'auditoria',
        ],
        [
            'permission_key' => 'gui_mpps_audit',
            'permission_name' => 'Ver menú MPPS Audit',
            'description' => 'Reservado para sidebar propio (entrega futura); la pestaña en Auditoría usa audit_manager',
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
    echo '<div class="info">';
    echo '<p>La pestaña <strong>MPPS / Equipos</strong> en Auditoría es visible con permiso <code>audit_manager</code>.</p>';
    echo '<p>Las APIs aceptan <code>mpps_audit</code> <strong>o</strong> <code>audit_manager</code> (también <code>root</code> / <code>all</code>).</p>';
    echo '<p>Asigná <code>mpps_audit</code> si querés acceso a las APIs sin el resto de Auditoría.</p>';
    echo '</div>';
} catch (Exception $e) {
    echo '<div class="error">' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>
    <a class="btn" href="install.php">Volver a instalación</a>
    <a class="btn btn-success" href="../../user-management.html">Gestión usuarios</a>
    <a class="btn btn-secondary" href="../../audit-manager.html">Auditoría</a>
</div>
</body>
</html>
