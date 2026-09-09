<?php
/**
 * Permisos del módulo QA / Control de Calidad.
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
    <title>Permisos — QA / Control de Calidad</title>
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
    <h1>Permisos — QA / Control de Calidad</h1>
<?php
try {
    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        throw new Exception('Sin conexión a la base de datos');
    }

    $permissions = [
        ['permission_key' => 'gui_qa_publicacion', 'permission_name' => 'Ver menú QA Control de Calidad', 'description' => 'Mostrar el enlace QA Control de Calidad en el sidebar', 'category' => 'interfaz'],
        ['permission_key' => 'qa_revisar', 'permission_name' => 'QA: revisar y publicar/bloquear', 'description' => 'Aprobar o bloquear estudios e informes en el módulo QA', 'category' => 'operaciones'],
        ['permission_key' => 'qa_despublicar_rapido', 'permission_name' => 'QA: despublicar rápido', 'description' => 'Acción rápida de despublicar/republicar desde estudios-manager e informes-manager', 'category' => 'operaciones'],
        ['permission_key' => 'qa_config', 'permission_name' => 'QA: configuración', 'description' => 'Cambiar modo (lista negra/blanca/híbrido) y reglas del módulo', 'category' => 'administracion'],
        ['permission_key' => 'qa_bajar_pacs', 'permission_name' => 'QA: bajar informe del PACS', 'description' => 'Eliminar serie DOC del informe en Orthanc (igual que Eliminar de PACS)', 'category' => 'pacs'],
        ['permission_key' => 'qa_ver_registro', 'permission_name' => 'QA: ver registro de auditoría', 'description' => 'Consultar el historial de acciones QA', 'category' => 'operaciones'],
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
    echo '<p><strong>Paquete sugerido revisor QA:</strong> <code>gui_qa_publicacion</code>, <code>qa_revisar</code>, <code>qa_ver_registro</code></p>';
    echo '<p><strong>Paquete sugerido médico informante:</strong> <code>qa_despublicar_rapido</code></p>';
    echo '<p><strong>Admin QA:</strong> <code>qa_config</code>, <code>qa_bajar_pacs</code></p>';
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
