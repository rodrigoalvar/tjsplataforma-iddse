#!/usr/bin/env php
<?php
/**
 * Worker PACS Cloner 2.0 (motor alternativo, desactivado por defecto).
 *
 * No reemplaza a cloner-worker.php: son procesos distintos. Mantenga el cron
 * de v1 mientras prueba v2; solo añada una línea para este script cuando corresponda.
 *
 * Control: configuracion.pacs_cloner_worker_v2_enabled = 1 para ejecutar lógica;
 *   sin clave o valor distinto de "activado" → sale 0 sin efectos.
 *
 * La implementación completa del estado estable / defer (spec 2.0) se añadirá aquí.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Este script solo puede ejecutarse en CLI.\n");
    exit(1);
}

$base = dirname(__DIR__);

require_once $base . '/../../config/database.php';
require_once $base . '/lib/cloner_worker_runtime_flags.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "[cloner-worker-v2] Sin conexión a BD\n");
    exit(1);
}

if (!cloner_worker_config_flag_enabled($db, 'pacs_cloner_worker_v2_enabled', false)) {
    echo "[cloner-worker-v2] Deshabilitado (pacs_cloner_worker_v2_enabled). Sin acción.\n";
    exit(0);
}

echo "[cloner-worker-v2] Motor 2.0 aún no implementado; no se encoló nada. Desactive el flag o retire esta línea del cron hasta tener la lógica lista.\n";
exit(0);
