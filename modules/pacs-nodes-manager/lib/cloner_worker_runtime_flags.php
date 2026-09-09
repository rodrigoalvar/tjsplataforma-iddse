<?php
/**
 * Flags de ejecución para workers PACS Cloner (tabla configuracion).
 * - v1: por defecto habilitado si no existe la clave (compatibilidad).
 * - v2: por defecto deshabilitado si no existe la clave.
 */

if (!function_exists('cloner_worker_config_flag_enabled')) {
    /**
     * @param PDO    $db
     * @param string $clave
     * @param bool   $defaultIfMissing
     */
    function cloner_worker_config_flag_enabled(PDO $db, $clave, $defaultIfMissing) {
        try {
            $st = $db->prepare('SELECT valor FROM configuracion WHERE clave = ?');
            $st->execute([$clave]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r || !array_key_exists('valor', $r)) {
                return $defaultIfMissing;
            }
            $v = strtolower(trim((string) $r['valor']));
            if ($v === '') {
                return $defaultIfMissing;
            }
            return in_array($v, ['1', 'true', 'yes', 'on', 'si', 'sí'], true);
        } catch (Throwable $e) {
            return $defaultIfMissing;
        }
    }
}

if (!function_exists('cloner_worker_config_get_string')) {
    /**
     * @return string|null valor en BD o null si no existe / error
     */
    function cloner_worker_config_get_string(PDO $db, $clave) {
        try {
            $st = $db->prepare('SELECT valor FROM configuracion WHERE clave = ?');
            $st->execute([$clave]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r || !array_key_exists('valor', $r)) {
                return null;
            }

            return (string) $r['valor'];
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('cloner_worker_resolve_int')) {
    /**
     * Entero de worker: variable de entorno (si está definida y no vacía) gana sobre BD y default.
     *
     * @return array{v:int, source:string} source = env|db|default
     */
    function cloner_worker_resolve_int(PDO $db, $envName, $configKey, $default, $min, $max) {
        $ev = getenv($envName);
        if ($ev !== false && trim((string) $ev) !== '') {
            $v = (int) $ev;

            return ['v' => max($min, min($max, $v)), 'source' => 'env'];
        }
        $s = cloner_worker_config_get_string($db, $configKey);
        if ($s !== null && trim($s) !== '') {
            $v = (int) trim($s);

            return ['v' => max($min, min($max, $v)), 'source' => 'db'];
        }

        return ['v' => max($min, min($max, $default)), 'source' => 'default'];
    }
}

if (!function_exists('cloner_worker_resolve_int_value')) {
    function cloner_worker_resolve_int_value(PDO $db, $envName, $configKey, $default, $min, $max) {
        return cloner_worker_resolve_int($db, $envName, $configKey, $default, $min, $max)['v'];
    }
}

if (!function_exists('cloner_worker_resolve_string')) {
    /**
     * Cadena: env gana si definida y no vacía; si no, valor en configuracion; si no, default.
     *
     * @return array{v:string, source:string}
     */
    function cloner_worker_resolve_string(PDO $db, $envName, $configKey, $default = '') {
        $ev = getenv($envName);
        if ($ev !== false && trim((string) $ev) !== '') {
            return ['v' => trim((string) $ev), 'source' => 'env'];
        }
        $s = cloner_worker_config_get_string($db, $configKey);
        if ($s !== null && trim($s) !== '') {
            return ['v' => trim($s), 'source' => 'db'];
        }

        return ['v' => (string) $default, 'source' => 'default'];
    }
}

if (!function_exists('cloner_worker_should_reconcile_jobs')) {
    /**
     * Reconciliación de jobs: PACS_CLONER_RECONCILE_JOBS=0 desactiva; si no hay env, usa configuracion.
     */
    function cloner_worker_should_reconcile_jobs(PDO $db) {
        $ev = getenv('PACS_CLONER_RECONCILE_JOBS');
        if ($ev !== false && trim((string) $ev) !== '') {
            return trim((string) $ev) !== '0';
        }

        return cloner_worker_config_flag_enabled($db, 'pacs_cloner_reconcile_jobs_enabled', true);
    }
}
