<?php
/**
 * Instalación idempotente del módulo QA / Control de Calidad.
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/includes/DbSchema.php';

const QA_MODULE_VERSION = '1.0.0';

$messages = [];
$errors = [];

function qaRenderInstallPage(array $messages, array $errors): void
{
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalación — QA / Control de Calidad</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 40px auto; padding: 20px; background: #f5f5f5; }
        .container { background: #fff; padding: 28px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
        h1 { color: #8B0D32; }
        .success { background: #d4edda; color: #155724; padding: 12px; border-radius: 6px; margin: 8px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin: 8px 0; }
        .btn { display: inline-block; padding: 10px 18px; background: #8B0D32; color: #fff; text-decoration: none; border-radius: 6px; margin: 8px 8px 0 0; }
        .btn-secondary { background: #6c757d; }
        code { background: #f1f1f1; padding: 2px 6px; border-radius: 4px; }
    </style>
</head>
<body>
<div class="container">
    <h1>QA / Control de Calidad — Instalación</h1>
    <p>Versión: <strong><?= htmlspecialchars(QA_MODULE_VERSION) ?></strong></p>
    <?php foreach ($messages as $msg): ?>
        <div class="success"><?= htmlspecialchars($msg) ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $msg): ?>
        <div class="error"><?= htmlspecialchars($msg) ?></div>
    <?php endforeach; ?>
    <?php if (empty($errors)): ?>
        <p>Siguiente paso: <code>install-permissions.php</code> y asignar permisos en Gestión de usuarios.</p>
    <?php endif; ?>
    <a class="btn" href="install-permissions.php">Instalar permisos</a>
    <a class="btn btn-secondary" href="../../dashboard-unified.html">Dashboard</a>
</div>
</body>
</html>
    <?php
}

function qaRunInstallSql(PDO $db, array &$messages): void
{
    $sqlFile = __DIR__ . '/database/install.sql';
    if (!is_readable($sqlFile)) {
        throw new RuntimeException('No se encontró database/install.sql');
    }

    $sql = file_get_contents($sqlFile);
    if ($sql === false) {
        throw new RuntimeException('No se pudo leer database/install.sql');
    }

    $statements = preg_split('/;\s*\n/', $sql);
    foreach ($statements as $statement) {
        $statement = trim($statement);
        $statement = preg_replace('/^--.*\n/m', '', $statement);
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }

        if (preg_match('/CREATE TABLE IF NOT EXISTS `([^`]+)`/i', $statement, $m)) {
            $table = $m[1];
            $existed = QaDbSchema::tableExists($db, $table);
            $db->exec($statement);
            $messages[] = $existed ? "Tabla {$table}: ya existía (verificada)." : "Tabla {$table}: creada.";
        } else {
            $db->exec($statement);
            if (stripos($statement, 'INSERT INTO') === 0) {
                $messages[] = 'Configuración por defecto sembrada.';
            }
        }
    }
}

try {
    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        throw new RuntimeException('Sin conexión a la base de datos');
    }

    $check = $db->query("SHOW TABLES LIKE 'system_permissions'");
    if (!$check || $check->rowCount() === 0) {
        throw new RuntimeException('La tabla system_permissions no existe. Instale el núcleo primero.');
    }

    qaRunInstallSql($db, $messages);
    $messages[] = 'Instalación completada. QA deshabilitado por defecto (qa_enabled=0).';
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

qaRenderInstallPage($messages, $errors);
