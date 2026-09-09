<?php
/**
 * Registra los permisos del módulo Multi-Monitor WorkSpace en system_permissions.
 * Uso: /modules/multimonitor/install-permissions.php
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permisos — Multi-Monitor WorkSpace</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 40px auto; padding: 20px; background: #f5f5f5; }
        .container { background: #fff; padding: 28px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
        h1 { color: #8B0D32; }
        .success { background: #d4edda; color: #155724; padding: 12px; border-radius: 6px; margin: 8px 0; }
        .error   { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin: 8px 0; }
        .info    { background: #d1ecf1; color: #0c5460; padding: 12px; border-radius: 6px; margin: 8px 0; }
        .btn { display: inline-block; padding: 10px 18px; background: #8B0D32; color: #fff; text-decoration: none; border-radius: 6px; margin: 8px 8px 0 0; font-weight: bold; }
        .btn-secondary { background: #6c757d; }
        .btn-success   { background: #198754; }
        code { background: #f1f1f1; padding: 2px 6px; border-radius: 4px; font-size: 0.9em; }
    </style>
</head>
<body>
<div class="container">
    <h1>🖥️ Permisos — Multi-Monitor WorkSpace</h1>
<?php
try {
    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        throw new Exception('Sin conexión a la base de datos');
    }

    $permissions = [
        [
            'permission_key'  => 'feature_multimonitor',
            'permission_name' => 'Multi-Monitor: WorkSpace en segundo monitor',
            'description'     => 'Permite abrir WorkSpace en un monitor secundario detectado. El icono de multi-monitor aparece en el header del dashboard cuando hay más de un monitor conectado. El enlace a WorkSpace se oculta del sidebar mientras la ventana secundaria está activa.',
            'category'        => 'interfaz',
        ],
    ];

    $inserted = 0;
    $updated  = 0;

    foreach ($permissions as $perm) {
        $check = $db->prepare('SELECT id FROM system_permissions WHERE permission_key = ?');
        $check->execute([$perm['permission_key']]);
        if ($check->fetch()) {
            $u = $db->prepare('UPDATE system_permissions SET permission_name = ?, description = ?, category = ? WHERE permission_key = ?');
            $u->execute([$perm['permission_name'], $perm['description'], $perm['category'], $perm['permission_key']]);
            $updated++;
            echo '<div class="success">Actualizado: <strong>' . htmlspecialchars($perm['permission_key']) . '</strong> — ' . htmlspecialchars($perm['permission_name']) . '</div>';
        } else {
            $i = $db->prepare('INSERT INTO system_permissions (permission_key, permission_name, description, category, created_at) VALUES (?,?,?,?,NOW())');
            $i->execute([$perm['permission_key'], $perm['permission_name'], $perm['description'], $perm['category']]);
            $inserted++;
            echo '<div class="success">Creado: <strong>' . htmlspecialchars($perm['permission_key']) . '</strong> — ' . htmlspecialchars($perm['permission_name']) . '</div>';
        }
    }

    echo '<p><strong>Resumen:</strong> creados ' . $inserted . ', actualizados ' . $updated . '.</p>';
    echo '<div class="info">';
    echo '<p>El permiso <code>feature_multimonitor</code> ahora aparece en el modal de <strong>Gestión de Usuarios</strong> dentro de la categoría <em>Interfaz/GUI</em>.</p>';
    echo '<p>Asignalo a las cuentas de prueba para activar la funcionalidad. Los usuarios <code>root</code> o con permiso <code>all</code> lo tienen automáticamente.</p>';
    echo '<p><strong>Requisito de hardware:</strong> la funcionalidad solo se activa visualmente cuando el equipo tiene más de un monitor conectado (<code>screen.isExtended === true</code>). En monitores únicos, el permiso existe pero el ícono no se muestra.</p>';
    echo '</div>';

} catch (Exception $e) {
    echo '<div class="error">' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>
    <a class="btn" href="install.php">← Instalación</a>
    <a class="btn btn-success" href="../../user-management.html">Gestión de Usuarios</a>
    <a class="btn btn-secondary" href="../../dashboard-unified.html">Dashboard</a>
</div>
</body>
</html>
