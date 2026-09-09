<?php
/**
 * Procesa un PDF o TXT bajo las carpetas configuradas (inotify / manual).
 * Uso: php informes-carpeta-process-one.php /ruta/absoluta/archivo.pdf
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../api/informes/carpeta/informes_carpeta_lib.php';

function ic_log_one(string $msg): void
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL);
}

$path = $argv[1] ?? '';
if ($path === '') {
    fwrite(STDERR, "Uso: php informes-carpeta-process-one.php /ruta/al/archivo.pdf|.txt\n");
    exit(2);
}

$path = str_replace('\\', '/', $path);
if (!is_file($path)) {
    ic_log_one('No existe el archivo: ' . $path);
    exit(2);
}

try {
    $db = getDBConnection();
    if (!$db) {
        throw new Exception('Sin conexión a BD');
    }
    $r = ic_process_filepath($db, $path);
    ic_log_one(($r['ok'] ? 'OK' : 'FAIL') . ' ' . ($r['message'] ?? '') . (isset($r['id']) ? ' id=' . $r['id'] : ''));
    fwrite(STDOUT, json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit($r['ok'] ? 0 : 1);
} catch (Throwable $e) {
    ic_log_one('EX: ' . $e->getMessage());
    exit(1);
}
