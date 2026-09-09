<?php
/**
 * Instala permisos del módulo Study History Manager.
 * Ejecutar una vez desde el navegador (usuario con acceso a BD).
 */
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Permisos — Historial de estudios</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 720px; margin: 2rem auto; padding: 1rem; }
        .ok { background: #d4edda; color: #155724; padding: 12px; border-radius: 6px; margin: 8px 0; }
        .err { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin: 8px 0; }
        a.btn { display: inline-block; margin-top: 1rem; padding: 10px 16px; background: #0d6efd; color: #fff; text-decoration: none; border-radius: 6px; }
    </style>
</head>
<body>
    <h1>Historial de estudios — permisos</h1>
<?php
try {
    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        throw new Exception('Sin conexión a la base de datos');
    }

    $permissions = [
        [
            'permission_key' => 'study_history_manager',
            'permission_name' => 'Historial de estudios (API)',
            'description' => 'Buscar estudios por paciente en nodos PACS y generar enlaces de visor',
            'category' => 'pacs'
        ],
        [
            'permission_key' => 'gui_study_history_manager',
            'permission_name' => 'Menú Historial de estudios',
            'description' => 'Mostrar la sección Historial de estudios en el sidebar',
            'category' => 'interfaz'
        ]
    ];

    foreach ($permissions as $perm) {
        $check = $db->prepare('SELECT id FROM system_permissions WHERE permission_key = ?');
        $check->execute([$perm['permission_key']]);
        if ($check->fetch()) {
            $u = $db->prepare('UPDATE system_permissions SET permission_name = ?, description = ?, category = ? WHERE permission_key = ?');
            $u->execute([$perm['permission_name'], $perm['description'], $perm['category'], $perm['permission_key']]);
            echo '<div class="ok">Actualizado: <code>' . htmlspecialchars($perm['permission_key']) . '</code></div>';
        } else {
            $i = $db->prepare('INSERT INTO system_permissions (permission_key, permission_name, description, category, created_at) VALUES (?,?,?,?,NOW())');
            $i->execute([$perm['permission_key'], $perm['permission_name'], $perm['description'], $perm['category']]);
            echo '<div class="ok">Creado: <code>' . htmlspecialchars($perm['permission_key']) . '</code></div>';
        }
    }

    echo '<p>Asigná ambos permisos a los usuarios que correspondan (root ya tiene acceso por nivel).</p>';
    echo '<a class="btn" href="../../user-management.html">Gestión de usuarios</a> ';
    echo '<a class="btn" href="../../study-history-manager.html" style="background:#198754">Abrir Historial</a>';
} catch (Exception $e) {
    echo '<div class="err">' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>
</body>
</html>
