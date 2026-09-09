<?php
/**
 * Rescaneo por mtime de PDF/TXT en las carpetas configuradas (respaldo si inotify no ve CIFS).
 *
 * Uso (cron o systemd oneshot + timer):
 *   php workers/informes-carpeta-poll-recent.php
 *
 * Variables de entorno opcionales:
 *   IC_POLL_WINDOW_SEC  segundos hacia atrás para considerar "reciente" (default 900 = 15 min)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../api/informes/carpeta/informes_carpeta_lib.php';

function log_poll(string $msg): void
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] [informes-carpeta-poll] ' . $msg . PHP_EOL);
}

$window = (int)(getenv('IC_POLL_WINDOW_SEC') ?: 900);
if ($window < 60) {
    $window = 60;
}
if ($window > 86400) {
    $window = 86400;
}

$since = time() - $window;

try {
    $db = getDBConnection();
    if (!$db) {
        throw new Exception('Sin conexión BD');
    }
    ic_ensure_table($db);
    $list = ic_list_recent_poll_candidates($db, $since);
    log_poll('Ventana ' . $window . 's, candidatos: ' . count($list));

    $ok = 0;
    $fail = 0;
    $skip = 0;
    foreach ($list as $path) {
        $r = ic_process_filepath($db, $path);
        if (!empty($r['ok'])) {
            if (strpos((string)($r['message'] ?? ''), 'Duplicado') !== false) {
                $skip++;
            } else {
                $ok++;
            }
        } else {
            $fail++;
            log_poll('FAIL ' . $path . ' — ' . ($r['message'] ?? ''));
        }
    }
    log_poll("Fin: procesados_ok={$ok} duplicados_omitidos={$skip} fallos={$fail}");
    exit($fail > 0 ? 1 : 0);
} catch (Throwable $e) {
    log_poll('EX: ' . $e->getMessage());
    exit(1);
}
