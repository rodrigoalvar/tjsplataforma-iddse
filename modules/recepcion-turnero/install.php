<?php
/**
 * Instalación idempotente del módulo Recepción y Turnero.
 * Crea tablas del plugin (rt_* y catálogos) sin alterar el núcleo.
 *
 * Uso: /modules/recepcion-turnero/install.php (navegador o CLI: php install.php)
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/includes/DbSchema.php';

const RT_MODULE_VERSION = '1.0.0';

$messages = [];
$errors = [];
$warnings = [];

function rtRenderInstallPage(array $messages, array $warnings, array $errors): void
{
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalación — Recepción y Turnero</title>
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
    <h1>Recepción y Turnero — Instalación</h1>
    <p>Versión del módulo: <strong><?= htmlspecialchars(RT_MODULE_VERSION) ?></strong></p>
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
        <p>Siguiente paso: ejecutar <code>install-permissions.php</code> y asignar permisos en Gestión de usuarios.</p>
    <?php endif; ?>
    <a class="btn" href="install-permissions.php">Instalar permisos</a>
    <a class="btn btn-secondary" href="../../dashboard-unified.html">Dashboard</a>
</div>
</body>
</html>
    <?php
}

/**
 * Ejecuta statements CREATE TABLE del install.sql.
 */
function rtRunInstallSql(PDO $db, array &$messages, array &$warnings): void
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
        if ($statement === '' || str_starts_with($statement, '--')) {
            continue;
        }

        if (preg_match('/CREATE TABLE IF NOT EXISTS `([^`]+)`/i', $statement, $m)) {
            $table = $m[1];
            $existed = RtDbSchema::tableExists($db, $table);
            $db->exec($statement);
            if ($existed) {
                $messages[] = "Tabla {$table}: ya existía (verificada).";
            } else {
                $messages[] = "Tabla {$table}: creada.";
            }
        } else {
            $db->exec($statement);
        }
    }
}

/**
 * Añade claves foráneas de forma idempotente.
 */
function rtApplyForeignKeys(PDO $db, array &$messages, array &$warnings): void
{
    $fks = [
        ['nomencladores', 'fk_nomencladores_origen', 'ALTER TABLE `nomencladores` ADD CONSTRAINT `fk_nomencladores_origen` FOREIGN KEY (`nomenclador_origen_id`) REFERENCES `nomencladores` (`id`) ON DELETE SET NULL'],
        ['obras_sociales', 'fk_obras_sociales_nomenclador', 'ALTER TABLE `obras_sociales` ADD CONSTRAINT `fk_obras_sociales_nomenclador` FOREIGN KEY (`nomenclador_id`) REFERENCES `nomencladores` (`id`) ON DELETE SET NULL'],
        ['nomencladores', 'fk_nomencladores_obra_social', 'ALTER TABLE `nomencladores` ADD CONSTRAINT `fk_nomencladores_obra_social` FOREIGN KEY (`obra_social_id`) REFERENCES `obras_sociales` (`id`) ON DELETE SET NULL'],
        ['nomenclador_practicas', 'fk_nom_practicas_nomenclador', 'ALTER TABLE `nomenclador_practicas` ADD CONSTRAINT `fk_nom_practicas_nomenclador` FOREIGN KEY (`nomenclador_id`) REFERENCES `nomencladores` (`id`) ON DELETE CASCADE'],
        ['rt_paciente_obras_sociales', 'fk_rt_pac_os_paciente', 'ALTER TABLE `rt_paciente_obras_sociales` ADD CONSTRAINT `fk_rt_pac_os_paciente` FOREIGN KEY (`rt_paciente_id`) REFERENCES `rt_pacientes` (`id`) ON DELETE CASCADE'],
        ['rt_paciente_obras_sociales', 'fk_rt_pac_os_obra', 'ALTER TABLE `rt_paciente_obras_sociales` ADD CONSTRAINT `fk_rt_pac_os_obra` FOREIGN KEY (`obra_social_id`) REFERENCES `obras_sociales` (`id`) ON DELETE RESTRICT'],
        ['equipo_horarios', 'fk_equipo_horarios_equipo', 'ALTER TABLE `equipo_horarios` ADD CONSTRAINT `fk_equipo_horarios_equipo` FOREIGN KEY (`equipo_id`) REFERENCES `equipos_imagen` (`id`) ON DELETE CASCADE'],
        ['equipo_excepciones', 'fk_equipo_exc_equipo', 'ALTER TABLE `equipo_excepciones` ADD CONSTRAINT `fk_equipo_exc_equipo` FOREIGN KEY (`equipo_id`) REFERENCES `equipos_imagen` (`id`) ON DELETE CASCADE'],
        ['equipo_secuencias', 'fk_equipo_sec_equipo', 'ALTER TABLE `equipo_secuencias` ADD CONSTRAINT `fk_equipo_sec_equipo` FOREIGN KEY (`equipo_id`) REFERENCES `equipos_imagen` (`id`) ON DELETE CASCADE'],
        ['rt_turnos', 'fk_rt_turnos_paciente', 'ALTER TABLE `rt_turnos` ADD CONSTRAINT `fk_rt_turnos_paciente` FOREIGN KEY (`rt_paciente_id`) REFERENCES `rt_pacientes` (`id`) ON DELETE SET NULL'],
        ['rt_turnos', 'fk_rt_turnos_equipo', 'ALTER TABLE `rt_turnos` ADD CONSTRAINT `fk_rt_turnos_equipo` FOREIGN KEY (`equipo_id`) REFERENCES `equipos_imagen` (`id`) ON DELETE SET NULL'],
        ['rt_turnos', 'fk_rt_turnos_obra_social', 'ALTER TABLE `rt_turnos` ADD CONSTRAINT `fk_rt_turnos_obra_social` FOREIGN KEY (`obra_social_id`) REFERENCES `obras_sociales` (`id`) ON DELETE SET NULL'],
        ['rt_turnos', 'fk_rt_turnos_ref_physician', 'ALTER TABLE `rt_turnos` ADD CONSTRAINT `fk_rt_turnos_ref_physician` FOREIGN KEY (`ref_physician_id`) REFERENCES `ref_physicians` (`id`) ON DELETE SET NULL'],
        ['rt_turno_practicas', 'fk_rt_turno_pract_turno', 'ALTER TABLE `rt_turno_practicas` ADD CONSTRAINT `fk_rt_turno_pract_turno` FOREIGN KEY (`rt_turno_id`) REFERENCES `rt_turnos` (`id`) ON DELETE CASCADE'],
        ['rt_turno_practicas', 'fk_rt_turno_pract_nom', 'ALTER TABLE `rt_turno_practicas` ADD CONSTRAINT `fk_rt_turno_pract_nom` FOREIGN KEY (`nomenclador_practica_id`) REFERENCES `nomenclador_practicas` (`id`) ON DELETE RESTRICT'],
        ['nomenclador_import_logs', 'fk_nom_import_nomenclador', 'ALTER TABLE `nomenclador_import_logs` ADD CONSTRAINT `fk_nom_import_nomenclador` FOREIGN KEY (`nomenclador_id`) REFERENCES `nomencladores` (`id`) ON DELETE CASCADE'],
    ];

    foreach ($fks as [$table, $name, $ddl]) {
        if (!RtDbSchema::tableExists($db, $table)) {
            continue;
        }
        try {
            if (RtDbSchema::addForeignKeyIfMissing($db, $table, $name, $ddl)) {
                $messages[] = "FK {$name}: añadida.";
            }
        } catch (Throwable $e) {
            $warnings[] = "FK {$name}: " . $e->getMessage();
        }
    }

    if (RtDbSchema::tableExists($db, 'pacientes') && RtDbSchema::tableExists($db, 'rt_pacientes')) {
        try {
            if (RtDbSchema::addForeignKeyIfMissing(
                $db,
                'rt_pacientes',
                'fk_rt_pacientes_pacientes',
                'ALTER TABLE `rt_pacientes` ADD CONSTRAINT `fk_rt_pacientes_pacientes` FOREIGN KEY (`pacientes_id`) REFERENCES `pacientes` (`id`) ON DELETE SET NULL'
            )) {
                $messages[] = 'FK fk_rt_pacientes_pacientes: añadida (vínculo opcional al núcleo).';
            }
        } catch (Throwable $e) {
            $warnings[] = 'FK fk_rt_pacientes_pacientes: ' . $e->getMessage();
        }
    } else {
        $warnings[] = 'FK a pacientes omitida: tabla pacientes no encontrada (se puede añadir después).';
    }

    if (RtDbSchema::tableExists($db, 'worklist') && RtDbSchema::tableExists($db, 'rt_turnos')) {
        try {
            if (RtDbSchema::addForeignKeyIfMissing(
                $db,
                'rt_turnos',
                'fk_rt_turnos_worklist',
                'ALTER TABLE `rt_turnos` ADD CONSTRAINT `fk_rt_turnos_worklist` FOREIGN KEY (`worklist_id`) REFERENCES `worklist` (`id`) ON DELETE SET NULL'
            )) {
                $messages[] = 'FK fk_rt_turnos_worklist: añadida (vínculo opcional a worklist).';
            }
        } catch (Throwable $e) {
            $warnings[] = 'FK fk_rt_turnos_worklist: ' . $e->getMessage();
        }
    } else {
        $warnings[] = 'FK a worklist omitida: tabla worklist no encontrada (se puede añadir después).';
    }
}

function rtUpsertModuleMeta(PDO $db, array &$messages): void
{
    if (!RtDbSchema::tableExists($db, 'rt_module_meta')) {
        return;
    }

    $stmt = $db->prepare(
        'INSERT INTO rt_module_meta (id, version, installed_at)
         VALUES (1, ?, NOW())
         ON DUPLICATE KEY UPDATE version = VALUES(version), updated_at = NOW()'
    );
    $stmt->execute([RT_MODULE_VERSION]);
    $messages[] = 'Metadatos del módulo actualizados (rt_module_meta).';
}

try {
    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        throw new Exception('Sin conexión a la base de datos');
    }

    rtRunInstallSql($db, $messages, $warnings);
    rtApplyForeignKeys($db, $messages, $warnings);
    rtUpsertModuleMeta($db, $messages);

    $messages[] = 'Instalación completada. El módulo NO modifica pacientes, worklist ni sidebars del núcleo.';
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

rtRenderInstallPage($messages, $warnings, $errors);
