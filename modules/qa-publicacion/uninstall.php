<?php
/**
 * Desinstalación del módulo QA / Control de Calidad.
 * ?drop_tables=1 para eliminar tablas del módulo.
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';

$messages = [];
$errors = [];
$dropTables = isset($_GET['drop_tables']) && $_GET['drop_tables'] === '1';

$permissionKeys = [
    'gui_qa_publicacion',
    'qa_revisar',
    'qa_despublicar_rapido',
    'qa_config',
    'qa_bajar_pacs',
    'qa_ver_registro',
];

try {
    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        throw new Exception('Sin conexión a la base de datos');
    }

    foreach ($permissionKeys as $key) {
        $usersStmt = $db->prepare(
            "SELECT id, permisos FROM usuarios WHERE permisos IS NOT NULL AND JSON_CONTAINS(permisos, ?)"
        );
        $usersStmt->execute([json_encode($key)]);
        $updatedUsers = 0;
        while ($row = $usersStmt->fetch(PDO::FETCH_ASSOC)) {
            $perms = json_decode($row['permisos'], true) ?: [];
            $perms = array_values(array_filter($perms, fn($p) => $p !== $key));
            $upd = $db->prepare('UPDATE usuarios SET permisos = ? WHERE id = ?');
            $upd->execute([json_encode($perms, JSON_UNESCAPED_UNICODE), $row['id']]);
            $updatedUsers++;
        }
        if ($updatedUsers > 0) {
            $messages[] = "Permiso {$key} eliminado de {$updatedUsers} usuario(s).";
        }
    }

    $placeholders = implode(',', array_fill(0, count($permissionKeys), '?'));
    $stmt = $db->prepare("DELETE FROM system_permissions WHERE permission_key IN ({$placeholders})");
    $stmt->execute($permissionKeys);
    $messages[] = 'Permisos eliminados de system_permissions.';

    if ($dropTables) {
        $tables = ['qa_action_log', 'qa_informe_status', 'qa_study_status', 'qa_config'];
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $db->exec("DROP TABLE IF EXISTS `{$table}`");
            $messages[] = "Tabla {$table} eliminada.";
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    } else {
        $messages[] = 'Tablas conservadas. Use ?drop_tables=1 para eliminarlas.';
    }

    $messages[] = 'Desinstalación completada. El portal volverá a funcionar sin filtro QA.';
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Desinstalación — QA / Control de Calidad</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 40px auto; padding: 20px; background: #f5f5f5; }
        .container { background: #fff; padding: 28px; border-radius: 10px; }
        h1 { color: #8B0D32; }
        .success { background: #d4edda; color: #155724; padding: 12px; border-radius: 6px; margin: 8px 0; }
        .warning { background: #fff3cd; color: #856404; padding: 12px; border-radius: 6px; margin: 8px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin: 8px 0; }
        .btn { display: inline-block; padding: 10px 18px; background: #6c757d; color: #fff; text-decoration: none; border-radius: 6px; margin: 8px 8px 0 0; }
    </style>
</head>
<body>
<div class="container">
    <h1>QA / Control de Calidad — Desinstalación</h1>
    <div class="warning">Modo <?= $dropTables ? 'completo (tablas eliminadas)' : 'seguro (solo permisos)' ?></div>
    <?php foreach ($messages as $msg): ?>
        <div class="success"><?= htmlspecialchars($msg) ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $msg): ?>
        <div class="error"><?= htmlspecialchars($msg) ?></div>
    <?php endforeach; ?>
    <a class="btn" href="../../dashboard-unified.html">Dashboard</a>
</div>
</body>
</html>
