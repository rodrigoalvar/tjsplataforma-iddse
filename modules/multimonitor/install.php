<?php
/**
 * Instalación del módulo Multi-Monitor WorkSpace.
 * Este módulo es 100% frontend: no crea tablas en BD.
 * Solo verifica compatibilidad del entorno.
 *
 * Uso: /modules/multimonitor/install.php (navegador o CLI: php install.php)
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';

$messages = [];
$errors   = [];
$warnings = [];

const MM_MODULE_VERSION = '1.0.0';

try {
    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        throw new Exception('No se pudo conectar a la base de datos');
    }

    // Verificar que exista la tabla system_permissions (requerida por el núcleo)
    $check = $db->query("SHOW TABLES LIKE 'system_permissions'");
    if (!$check || $check->rowCount() === 0) {
        throw new Exception('La tabla system_permissions no existe. Asegurate de tener el núcleo del sistema instalado correctamente.');
    }
    $messages[] = 'Tabla system_permissions: encontrada ✓';

    // Verificar que la tabla usuarios tenga la columna permisos (JSON)
    $check2 = $db->query("SHOW COLUMNS FROM usuarios LIKE 'permisos'");
    if (!$check2 || $check2->rowCount() === 0) {
        $warnings[] = 'La columna permisos no se encontró en la tabla usuarios. Verificar que el núcleo esté correctamente instalado.';
    } else {
        $messages[] = 'Columna usuarios.permisos: encontrada ✓';
    }

    $messages[] = 'Módulo Multi-Monitor: verificación completada. No se crean tablas adicionales (módulo frontend).';
    $messages[] = 'Siguiente paso: ejecutar install-permissions.php para registrar el permiso en el sistema.';

} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalación — Multi-Monitor WorkSpace</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 40px auto; padding: 20px; background: #f5f5f5; }
        .container { background: #fff; padding: 28px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
        h1 { color: #8B0D32; }
        h2 { color: #444; font-size: 1.1rem; margin-top: 24px; }
        .success { background: #d4edda; color: #155724; padding: 12px; border-radius: 6px; margin: 8px 0; }
        .warning { background: #fff3cd; color: #856404; padding: 12px; border-radius: 6px; margin: 8px 0; }
        .error   { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin: 8px 0; }
        .info    { background: #d1ecf1; color: #0c5460; padding: 12px; border-radius: 6px; margin: 8px 0; }
        .btn { display: inline-block; padding: 10px 18px; background: #8B0D32; color: #fff; text-decoration: none; border-radius: 6px; margin: 8px 8px 0 0; font-weight: bold; }
        .btn-secondary { background: #6c757d; }
        code { background: #f1f1f1; padding: 2px 6px; border-radius: 4px; font-size: 0.9em; }
    </style>
</head>
<body>
<div class="container">
    <h1>🖥️ Multi-Monitor WorkSpace — Instalación</h1>
    <p>Versión del módulo: <strong><?= htmlspecialchars(MM_MODULE_VERSION) ?></strong></p>

    <?php foreach ($messages as $msg): ?>
        <div class="success"><?= htmlspecialchars($msg) ?></div>
    <?php endforeach; ?>
    <?php foreach ($warnings as $msg): ?>
        <div class="warning"><?= htmlspecialchars($msg) ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $msg): ?>
        <div class="error"><?= htmlspecialchars($msg) ?></div>
    <?php endforeach; ?>

    <?php if (empty($errors)): ?>
        <div class="info">
            <strong>Este módulo es 100% frontend.</strong> No crea tablas en la base de datos.<br>
            El siguiente paso es registrar el permiso <code>feature_multimonitor</code> ejecutando <code>install-permissions.php</code>.
        </div>
        <h2>Pasos siguientes</h2>
        <ol>
            <li>Ejecutar <strong>install-permissions.php</strong> para registrar el permiso en el sistema.</li>
            <li>Ir a <strong>Gestión de Usuarios</strong> y asignar el permiso <code>feature_multimonitor</code> a las cuentas de prueba.</li>
            <li>Iniciar sesión con una de esas cuentas para verificar el comportamiento multi-monitor.</li>
        </ol>
    <?php endif; ?>

    <br>
    <a class="btn" href="install-permissions.php">Instalar permisos →</a>
    <a class="btn btn-secondary" href="../../dashboard-unified.html">Dashboard</a>
</div>
</body>
</html>
