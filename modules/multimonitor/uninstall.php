<?php
/**
 * Desinstalación del módulo Multi-Monitor WorkSpace.
 * Elimina el permiso de system_permissions y sus asignaciones a usuarios.
 * No elimina tablas (este módulo no crea tablas).
 *
 * Uso: /modules/multimonitor/uninstall.php
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';

$messages = [];
$errors   = [];

$permissionKeys = [
    'feature_multimonitor',
];

try {
    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        throw new Exception('Sin conexión a la base de datos');
    }

    // Los permisos de usuario se almacenan como JSON en usuarios.permisos
    // Obtener todos los usuarios que tengan feature_multimonitor en su JSON de permisos
    $usersStmt = $db->query("SELECT id, permisos FROM usuarios WHERE permisos IS NOT NULL AND JSON_CONTAINS(permisos, '\"feature_multimonitor\"')");
    $updatedUsers = 0;
    if ($usersStmt) {
        while ($row = $usersStmt->fetch(PDO::FETCH_ASSOC)) {
            $perms = json_decode($row['permisos'], true) ?: [];
            $perms = array_values(array_filter($perms, fn($p) => $p !== 'feature_multimonitor'));
            $newJson = json_encode($perms, JSON_UNESCAPED_UNICODE);
            $upd = $db->prepare('UPDATE usuarios SET permisos = ? WHERE id = ?');
            $upd->execute([$newJson, $row['id']]);
            $updatedUsers++;
        }
    }
    if ($updatedUsers > 0) {
        $messages[] = "Permiso feature_multimonitor eliminado de {$updatedUsers} usuario(s).";
    } else {
        $messages[] = 'Ningún usuario tenía asignado el permiso feature_multimonitor.';
    }

    // Eliminar el permiso del catálogo de system_permissions
    $placeholders = implode(',', array_fill(0, count($permissionKeys), '?'));
    $stmt = $db->prepare("DELETE FROM system_permissions WHERE permission_key IN ({$placeholders})");
    $stmt->execute($permissionKeys);
    $messages[] = 'Permiso eliminado de system_permissions.';
    $messages[] = 'El módulo Multi-Monitor ha sido desinstalado. Los archivos en modules/multimonitor/ pueden eliminarse manualmente si se desea.';

} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Desinstalación — Multi-Monitor WorkSpace</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 40px auto; padding: 20px; background: #f5f5f5; }
        .container { background: #fff; padding: 28px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
        h1 { color: #8B0D32; }
        .success { background: #d4edda; color: #155724; padding: 12px; border-radius: 6px; margin: 8px 0; }
        .warning { background: #fff3cd; color: #856404; padding: 12px; border-radius: 6px; margin: 8px 0; }
        .error   { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin: 8px 0; }
        .btn { display: inline-block; padding: 10px 18px; background: #6c757d; color: #fff; text-decoration: none; border-radius: 6px; margin: 8px 8px 0 0; }
    </style>
</head>
<body>
<div class="container">
    <h1>🖥️ Multi-Monitor WorkSpace — Desinstalación</h1>
    <div class="warning">Modo seguro: solo se eliminaron permisos. No se modificaron tablas de datos.</div>
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
