<?php
/**
 * Desinstalación suave del módulo Recepción y Turnero.
 * Desactiva permisos del módulo; NO elimina tablas ni datos por defecto.
 *
 * Uso: /modules/recepcion-turnero/uninstall.php
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';

$messages = [];
$errors = [];

$permissionKeys = [
    'gui_turnero',
    'turnero',
    'gui_ingreso_pacientes',
    'ingreso_pacientes',
    'gui_abm_catalogos',
    'turnero_confirmar_ingreso',
    'obras_sociales_abm',
    'nomenclador_abm',
    'ref_physicians_abm',
    'equipos_horarios_abm',
    'turnero_admin',
];

$dropTables = isset($_GET['drop_tables']) && $_GET['drop_tables'] === '1';

try {
    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        throw new Exception('Sin conexión a la base de datos');
    }

    $placeholders = implode(',', array_fill(0, count($permissionKeys), '?'));
    $stmt = $db->prepare("DELETE FROM user_permissions WHERE permission_id IN (SELECT id FROM system_permissions WHERE permission_key IN ({$placeholders}))");
    $stmt->execute($permissionKeys);
    $messages[] = 'Asignaciones de permisos de usuario eliminadas para este módulo.';

    $stmt = $db->prepare("DELETE FROM system_permissions WHERE permission_key IN ({$placeholders})");
    $stmt->execute($permissionKeys);
    $messages[] = 'Permisos del módulo eliminados de system_permissions.';

    if ($dropTables) {
        $tables = [
            'rt_turno_practicas',
            'rt_turnos',
            'rt_paciente_obras_sociales',
            'rt_pacientes',
            'nomenclador_import_logs',
            'nomenclador_practicas',
            'equipo_secuencias',
            'equipo_excepciones',
            'equipo_horarios',
            'obras_sociales',
            'nomencladores',
            'equipos_imagen',
            'ref_physicians',
            'rt_module_meta',
        ];
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $db->exec("DROP TABLE IF EXISTS `{$table}`");
            $messages[] = "Tabla {$table}: eliminada.";
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
        $messages[] = 'Tablas del módulo eliminadas (modo drop_tables=1).';
    } else {
        $messages[] = 'Datos y tablas conservados. Para eliminar tablas, usar ?drop_tables=1 (solo staging/desarrollo).';
    }

    $messages[] = 'Recordá quitar entradas del sidebar en dashboard-unified.html si fueron añadidas manualmente.';
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Desinstalación — Recepción y Turnero</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 40px auto; padding: 20px; background: #f5f5f5; }
        .container { background: #fff; padding: 28px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
        h1 { color: #8B0D32; }
        .success { background: #d4edda; color: #155724; padding: 12px; border-radius: 6px; margin: 8px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin: 8px 0; }
        .warning { background: #fff3cd; color: #856404; padding: 12px; border-radius: 6px; margin: 8px 0; }
        .btn { display: inline-block; padding: 10px 18px; background: #6c757d; color: #fff; text-decoration: none; border-radius: 6px; margin: 8px 8px 0 0; }
    </style>
</head>
<body>
<div class="container">
    <h1>Recepción y Turnero — Desinstalación</h1>
    <?php if (!$dropTables): ?>
        <div class="warning">Modo seguro: solo se quitaron permisos. Los datos operativos se conservan.</div>
    <?php endif; ?>
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
