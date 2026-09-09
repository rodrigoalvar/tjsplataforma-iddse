<?php
/**
 * Instalación idempotente del módulo MPPS Audit.
 * Uso: /modules/mpps-audit/install.php (navegador o CLI: php install.php)
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';

const MPPS_AUDIT_MODULE_VERSION = '1.0.0';

$messages = [];
$errors = [];
$warnings = [];

function mppsTableExists(PDO $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function mppsFkExists(PDO $db, string $table, string $constraintName): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = \'FOREIGN KEY\''
    );
    $stmt->execute([$table, $constraintName]);
    return (int) $stmt->fetchColumn() > 0;
}

function mppsRunInstallSql(PDO $db, array &$messages): void
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
        if ($statement === '') {
            continue;
        }
        // Quitar líneas de comentario iniciales (-- ...) para no saltar el CREATE
        $lines = preg_split('/\r\n|\r|\n/', $statement);
        $kept = [];
        foreach ($lines as $line) {
            $t = ltrim($line);
            if ($t === '' && empty($kept)) {
                continue;
            }
            if (str_starts_with($t, '--') && empty($kept)) {
                continue;
            }
            $kept[] = $line;
        }
        $statement = trim(implode("\n", $kept));
        if ($statement === '' || str_starts_with($statement, '--')) {
            continue;
        }
        if (preg_match('/CREATE TABLE IF NOT EXISTS `([^`]+)`/i', $statement, $m)) {
            $table = $m[1];
            $existed = mppsTableExists($db, $table);
            $db->exec($statement);
            $messages[] = $existed
                ? "Tabla {$table}: ya existía (verificada)."
                : "Tabla {$table}: creada.";
        } else {
            $db->exec($statement);
        }
    }
}

function mppsApplyOptionalWorklistFk(PDO $db, array &$messages, array &$warnings): void
{
    if (!mppsTableExists($db, 'mpps_events')) {
        return;
    }
    if (!mppsTableExists($db, 'worklist')) {
        $warnings[] = 'FK a worklist omitida: tabla worklist no encontrada (se puede añadir después).';
        return;
    }
    $name = 'fk_mpps_events_worklist';
    if (mppsFkExists($db, 'mpps_events', $name)) {
        $messages[] = "FK {$name}: ya existía.";
        return;
    }
    try {
        $db->exec(
            'ALTER TABLE `mpps_events`
             ADD CONSTRAINT `fk_mpps_events_worklist`
             FOREIGN KEY (`worklist_id`) REFERENCES `worklist` (`id`) ON DELETE SET NULL'
        );
        $messages[] = "FK {$name}: añadida (vínculo opcional a worklist).";
    } catch (Throwable $e) {
        $warnings[] = "FK {$name}: " . $e->getMessage();
    }
}

try {
    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        throw new Exception('Sin conexión a la base de datos');
    }

    mppsRunInstallSql($db, $messages);
    mppsApplyOptionalWorklistFk($db, $messages, $warnings);
    $messages[] = 'Instalación MPPS Audit v' . MPPS_AUDIT_MODULE_VERSION . ' completada.';
    $messages[] = 'Siguiente: install-permissions.php y pestaña en Auditoría (ver docs/INTEGRACION_AUDIT_UI.md).';
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalación — MPPS Audit</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 40px auto; padding: 20px; background: #f5f5f5; }
        .container { background: #fff; padding: 28px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
        h1 { color: #8B0D32; }
        .success { background: #d4edda; color: #155724; padding: 12px; border-radius: 6px; margin: 8px 0; }
        .warning { background: #fff3cd; color: #856404; padding: 12px; border-radius: 6px; margin: 8px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin: 8px 0; }
        .btn { display: inline-block; padding: 10px 18px; background: #8B0D32; color: #fff; text-decoration: none; border-radius: 6px; margin: 8px 8px 0 0; }
        .btn-secondary { background: #6c757d; }
        code { background: #f1f1f1; padding: 2px 6px; border-radius: 4px; }
    </style>
</head>
<body>
<div class="container">
    <h1>MPPS Audit — Instalación</h1>
    <p>Versión del módulo: <strong><?= htmlspecialchars(MPPS_AUDIT_MODULE_VERSION) ?></strong></p>
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
        <p>Siguiente paso: ejecutar <code>install-permissions.php</code>.</p>
    <?php endif; ?>
    <a class="btn" href="install-permissions.php">Instalar permisos</a>
    <a class="btn btn-secondary" href="../../audit-manager.html">Auditoría</a>
    <a class="btn btn-secondary" href="../../dashboard-unified.html">Dashboard</a>
</div>
</body>
</html>
