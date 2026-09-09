<?php
/**
 * Instalación idempotente del módulo hl7-worklist.
 * CLI: php modules/hl7-worklist/install.php
 * Web: /modules/hl7-worklist/install.php (requiere sesión admin)
 */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/php/bootstrap.php';
require_once __DIR__ . '/../../utils/WorklistIngestionService.php';

function out(string $msg): void
{
    echo $msg . PHP_EOL;
}

try {
    if (!$isCli) {
        require_once __DIR__ . '/../../classes/User.php';
        $token = null;
        if (!empty($_SERVER['HTTP_AUTHORIZATION']) && stripos($_SERVER['HTTP_AUTHORIZATION'], 'Bearer ') === 0) {
            $token = substr($_SERVER['HTTP_AUTHORIZATION'], 7);
        }
        $token = $token ?: ($_COOKIE['session_token'] ?? null);
        if (!$token) {
            http_response_code(401);
            out('Se requiere autenticación admin/root');
            exit(1);
        }
        $user = new User();
        $userData = $user->validateSession($token);
        if (!$userData || !in_array(strtolower($userData['nivel'] ?? ''), ['root', 'admin'], true)) {
            http_response_code(403);
            out('Sin permisos de administración');
            exit(1);
        }
    }

    $db = getDBConnection();
    if (!$db) {
        throw new Exception('Sin conexión a BD');
    }

    out('=== Instalación módulo hl7-worklist v' . Hl7WorklistModule::MODULE_VERSION . ' ===');

    // Asegurar worklist_config existe
    $svc = new WorklistIngestionService($db);
    $svc->ensureSchema();

    if (file_exists(__DIR__ . '/../../api/worklist-config.php')) {
        // Reusar ensure vía include parcial no es trivial; columnas las crea el módulo
    }

    Hl7WorklistModule::ensureConfigColumns($db);
    out('OK columnas hl7_* en worklist_config');

    Hl7WorklistModule::ensureIngestSourceType($db);
    out('OK source_type HL7_MLLP en worklist_ingests');

    $cfg = Hl7WorklistModule::readConfigFromDb($db);
    Hl7WorklistModule::ensureDirectories($cfg);
    out('OK carpetas inbox/processed/failed/logs/runtime');

    $snap = Hl7WorklistModule::writeConfigSnapshot($db);
    out('OK config snapshot: ' . $snap);

    out('');
    out('Checklist operativo:');
    out('  1. Copiar listener/hl7-mllp-listener.service.example a /etc/systemd/system/hl7-mllp-listener.service');
    out('  2. systemctl daemon-reload && systemctl enable --now hl7-mllp-listener');
    out('  3. Abrir firewall al puerto (default 2575) solo desde IP del RIS');
    out('  4. Configuración → Worklist → activar HL7 y elegir Prestador');
    out('  5. Opcional cron: * * * * * php ' . __DIR__ . '/workers/hl7-inbox-worker.php');
    out('');
    out('Smoke test parser: php ' . __DIR__ . '/php/tests/smoke-parser.php');
    out('Instalación completada.');
} catch (Exception $e) {
    out('ERROR: ' . $e->getMessage());
    exit(1);
}
