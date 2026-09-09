<?php
/**
 * Instalación idempotente del módulo Audit Manager.
 * Crea audit_manager_events y añade columnas opcionales a sesiones si no existen.
 *
 * Uso: /modules/audit-manager/install.php (navegador o CLI php install.php)
 */
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';

function columnExists(PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function tableExists(PDO $db, string $table): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Error 1067 al ALTER sesiones: TIMESTAMP NOT NULL con default implícito inválido bajo NO_ZERO_DATE.
 * Normaliza filas raras y fuerza DATETIME NOT NULL (el PHP ya usa cadenas 'Y-m-d H:i:s').
 */
function repairSesionesFechaExpiracion(PDO $db): array {
    $result = ['changed' => false, 'message' => null, 'error' => null];
    try {
        try {
            $db->exec(
                "UPDATE `sesiones` SET `fecha_expiracion` = DATE_ADD(COALESCE(`fecha_creacion`, NOW()), INTERVAL 24 HOUR)
                 WHERE `fecha_expiracion` < '2001-01-01'"
            );
        } catch (Throwable $e) {
            // Filas o comparaciones imposibles en sql_mode estricto: seguir con MODIFY
        }
        $db->exec('ALTER TABLE `sesiones` MODIFY COLUMN `fecha_expiracion` DATETIME NOT NULL');
        $result['changed'] = true;
        $result['message'] = 'Columna sesiones.fecha_expiracion normalizada a DATETIME NOT NULL (compatibilidad con sql_mode estricto).';
    } catch (Throwable $e) {
        $result['error'] = $e->getMessage();
    }
    return $result;
}

$messages = [];
$errors = [];
$warnings = [];

try {
    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        throw new Exception('Sin conexión a la base de datos');
    }

    if (!tableExists($db, 'audit_manager_events')) {
        $db->exec("CREATE TABLE `audit_manager_events` (
          `id` bigint NOT NULL AUTO_INCREMENT,
          `user_id` int NOT NULL,
          `action_key` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
          `resource_type` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `resource_id` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `description` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `metadata` json DEFAULT NULL,
          `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `user_agent` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `client_duration_ms` int unsigned DEFAULT NULL,
          `server_processing_ms` int unsigned DEFAULT NULL,
          `rtt_ms` int unsigned DEFAULT NULL,
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_user_created` (`user_id`,`created_at`),
          KEY `idx_action_created` (`action_key`,`created_at`),
          KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $messages[] = 'Tabla audit_manager_events creada.';
    } else {
        $messages[] = 'Tabla audit_manager_events: ya existía.';
    }

    if (!tableExists($db, 'audit_portal_paciente_visits')) {
        $db->exec("CREATE TABLE `audit_portal_paciente_visits` (
          `id` bigint NOT NULL AUTO_INCREMENT,
          `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `user_agent` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `page_url` varchar(256) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `referer` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `patient_query` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_created` (`created_at`),
          KEY `idx_ip_created` (`ip_address`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $messages[] = 'Tabla audit_portal_paciente_visits creada.';
    } else {
        $messages[] = 'Tabla audit_portal_paciente_visits: ya existía.';
    }

    if (tableExists($db, 'audit_portal_paciente_visits') && !columnExists($db, 'audit_portal_paciente_visits', 'patient_query')) {
        try {
            $db->exec(
                'ALTER TABLE `audit_portal_paciente_visits` ADD COLUMN `patient_query` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `referer`'
            );
            $messages[] = 'Columna audit_portal_paciente_visits.patient_query añadida.';
        } catch (Throwable $e) {
            $warnings[] = 'No se pudo añadir patient_query: ' . $e->getMessage();
        }
    }

    if (tableExists($db, 'sesiones')) {
        $sesionesAltered = false;
        $addCol = function (PDO $dbConn, string $col, string $ddl) use (&$messages, &$warnings, &$sesionesAltered) {
            if (columnExists($dbConn, 'sesiones', $col)) {
                return;
            }
            try {
                $dbConn->exec($ddl);
                $messages[] = 'Columna sesiones.' . $col . ' añadida.';
                $sesionesAltered = true;
            } catch (Throwable $e) {
                return $e->getMessage();
            }
            return null;
        };

        $tryAddAll = function () use ($db, $addCol, &$messages, &$warnings, &$sesionesAltered) {
            $failed = false;
            foreach (
                [
                    'ip_login' => 'ALTER TABLE `sesiones` ADD COLUMN `ip_login` varchar(45) DEFAULT NULL',
                    'user_agent_login' => 'ALTER TABLE `sesiones` ADD COLUMN `user_agent_login` varchar(512) DEFAULT NULL',
                    'fecha_cierre' => 'ALTER TABLE `sesiones` ADD COLUMN `fecha_cierre` datetime NULL DEFAULT NULL',
                    'active_seconds' => 'ALTER TABLE `sesiones` ADD COLUMN `active_seconds` int unsigned NOT NULL DEFAULT 0',
                    'active_tick_at' => 'ALTER TABLE `sesiones` ADD COLUMN `active_tick_at` datetime NULL DEFAULT NULL',
                ] as $col => $ddl
            ) {
                $err = $addCol($db, $col, $ddl);
                if ($err !== null) {
                    $warnings[] = 'No se pudo añadir sesiones.' . $col . ': ' . $err;
                    $failed = true;
                }
            }
            return !$failed;
        };

        // Primer intento
        if (!$tryAddAll() && (strpos(implode(' ', $warnings), '1067') !== false || strpos(implode(' ', $warnings), 'fecha_expiracion') !== false)) {
            $repair = repairSesionesFechaExpiracion($db);
            if ($repair['message']) {
                $messages[] = $repair['message'];
            }
            if ($repair['error']) {
                $warnings[] = 'Reparación fecha_expiracion: ' . $repair['error'];
            } else {
                // Quitar avisos del intento fallido y reintentar
                $warnings = array_values(array_filter($warnings, static function ($w) {
                    return strpos($w, 'No se pudo añadir sesiones.') === false;
                }));
                $tryAddAll();
            }
        }

        if (!$sesionesAltered && count($warnings) === 0) {
            $messages[] = 'Columnas opcionales en sesiones: ya estaban presentes.';
        }
    } else {
        $errors[] = 'No existe la tabla sesiones; no se alteró.';
    }
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

$isCli = php_sapi_name() === 'cli';
if ($isCli) {
    foreach ($messages as $m) {
        echo $m . PHP_EOL;
    }
    foreach ($warnings as $w) {
        echo 'AVISO: ' . $w . PHP_EOL;
    }
    foreach ($errors as $e) {
        echo 'ERROR: ' . $e . PHP_EOL;
    }
    exit(empty($errors) ? 0 : 1);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Instalar Audit Manager</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 720px; margin: 2rem auto; padding: 1rem; }
        .ok { background: #d4edda; padding: 12px; border-radius: 8px; margin: 8px 0; }
        .err { background: #f8d7da; padding: 12px; border-radius: 8px; margin: 8px 0; }
        a.btn { display: inline-block; margin-top: 16px; padding: 10px 16px; background: #0d6efd; color: #fff; text-decoration: none; border-radius: 6px; }
    </style>
</head>
<body>
    <h1>Audit Manager — instalación</h1>
    <?php foreach ($messages as $m): ?>
        <div class="ok"><?php echo htmlspecialchars($m); ?></div>
    <?php endforeach; ?>
    <?php foreach ($warnings as $w): ?>
        <div class="err" style="background:#fff3cd;color:#664d03;"><?php echo htmlspecialchars($w); ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $e): ?>
        <div class="err"><?php echo htmlspecialchars($e); ?></div>
    <?php endforeach; ?>
    <p><a class="btn" href="install-permissions.php">Instalar permisos</a>
    <a class="btn" href="../../dashboard-unified.html" style="background:#6c757d;">Dashboard</a></p>
</body>
</html>
