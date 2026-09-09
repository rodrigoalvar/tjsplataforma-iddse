<?php
/**
 * Registra permisos del módulo Recepción y Turnero en system_permissions.
 * Uso: /modules/recepcion-turnero/install-permissions.php
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
    <title>Permisos — Recepción y Turnero</title>
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
    <h1>Permisos — Recepción y Turnero</h1>
<?php
try {
    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        throw new Exception('Sin conexión a la base de datos');
    }

    $permissions = [
        ['permission_key' => 'gui_turnero', 'permission_name' => 'Ver menú Turnero', 'description' => 'Mostrar el enlace Turnero en el sidebar', 'category' => 'interfaz'],
        ['permission_key' => 'turnero', 'permission_name' => 'Turnero', 'description' => 'Reservar y gestionar turnos provisorios', 'category' => 'operaciones'],
        ['permission_key' => 'gui_ingreso_pacientes', 'permission_name' => 'Ver menú Ingreso de Pacientes', 'description' => 'Mostrar el enlace Ingreso de Pacientes en el sidebar', 'category' => 'interfaz'],
        ['permission_key' => 'ingreso_pacientes', 'permission_name' => 'Ingreso de pacientes', 'description' => 'Registrar ingreso, prácticas y documentación', 'category' => 'operaciones'],
        ['permission_key' => 'gui_abm_catalogos', 'permission_name' => 'Ver menú ABM Catálogos', 'description' => 'Mostrar el enlace ABM Catálogos en el sidebar', 'category' => 'interfaz'],
        ['permission_key' => 'turnero_confirmar_ingreso', 'permission_name' => 'Confirmar ingreso', 'description' => 'Confirmar ingreso y generar worklist DICOM', 'category' => 'operaciones'],
        ['permission_key' => 'obras_sociales_abm', 'permission_name' => 'ABM obras sociales', 'description' => 'Alta, baja y modificación de obras sociales', 'category' => 'catalogos'],
        ['permission_key' => 'nomenclador_abm', 'permission_name' => 'ABM nomenclador', 'description' => 'Gestionar nomencladores y prácticas; importar CSV/Excel', 'category' => 'catalogos'],
        ['permission_key' => 'ref_physicians_abm', 'permission_name' => 'ABM médicos referentes', 'description' => 'Alta, baja y modificación de médicos referentes', 'category' => 'catalogos'],
        ['permission_key' => 'equipos_horarios_abm', 'permission_name' => 'ABM equipos y horarios', 'description' => 'Configurar equipos de imagen, horarios y excepciones', 'category' => 'catalogos'],
        ['permission_key' => 'turnero_admin', 'permission_name' => 'Administración turnero', 'description' => 'Cancelar turnos, override accession y excepciones de agenda', 'category' => 'administracion'],
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
    echo '<p>Asigná permisos en <strong>Gestión de usuarios</strong>. Los usuarios <code>root</code> o con permiso <code>all</code> ya tienen acceso total.</p>';
    echo '<p><strong>Paquete sugerido Recepción:</strong> <code>gui_turnero</code>, <code>turnero</code>, <code>gui_ingreso_pacientes</code>, <code>ingreso_pacientes</code>, <code>turnero_confirmar_ingreso</code></p>';
    echo '<p><strong>Paquete sugerido Admin catálogos:</strong> <code>gui_abm_catalogos</code> + permisos <code>*_abm</code></p>';
    echo '</div>';
} catch (Exception $e) {
    echo '<div class="error">' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>
    <a class="btn" href="install.php">Volver a instalación</a>
    <a class="btn btn-success" href="../../user-management.html">Gestión usuarios</a>
    <a class="btn btn-secondary" href="../../dashboard-unified.html">Dashboard</a>
</div>
</body>
</html>
