<?php
/**
 * Consola worker v1: lectura/escritura de parámetros en configuracion + estado para la UI.
 */

require_once __DIR__ . '/cloner_worker_runtime_flags.php';

if (!function_exists('pacs_cloner_get_worker_console')) {
    function pacs_cloner_get_worker_console(PDO $db) {
        $intSpec = [
            'max_per_run' => ['env' => 'PACS_CLONER_MAX_PER_RUN', 'key' => 'pacs_cloner_max_per_run', 'def' => 25, 'min' => 1, 'max' => 500],
            'dispatch_poll_sec' => ['env' => 'PACS_CLONER_DISPATCH_POLL_SEC', 'key' => 'pacs_cloner_dispatch_poll_sec', 'def' => 5, 'min' => 1, 'max' => 120],
            'dispatch_max_sec' => ['env' => 'PACS_CLONER_DISPATCH_MAX_SEC', 'key' => 'pacs_cloner_dispatch_max_sec', 'def' => 900, 'min' => 0, 'max' => 86400],
            'dispatch_max_starts' => ['env' => 'PACS_CLONER_DISPATCH_MAX_STARTS', 'key' => 'pacs_cloner_dispatch_max_starts', 'def' => 200, 'min' => 1, 'max' => 2000],
            'reconcile_jobs_limit' => ['env' => 'PACS_CLONER_RECONCILE_JOBS_LIMIT', 'key' => 'pacs_cloner_reconcile_jobs_limit', 'def' => 200, 'min' => 1, 'max' => 500],
            'stale_pending_minutes' => ['env' => 'PACS_CLONER_STALE_PENDING_MINUTES', 'key' => 'pacs_cloner_stale_pending_minutes', 'def' => 90, 'min' => 15, 'max' => 1440],
            'stale_running_hours' => ['env' => 'PACS_CLONER_STALE_RUNNING_HOURS', 'key' => 'pacs_cloner_stale_running_hours', 'def' => 6, 'min' => 1, 'max' => 72],
        ];
        $runtime = [];
        foreach ($intSpec as $name => $spec) {
            $r = cloner_worker_resolve_int($db, $spec['env'], $spec['key'], $spec['def'], $spec['min'], $spec['max']);
            $runtime[$name] = ['value' => $r['v'], 'source' => $r['source']];
        }
        $maxRun = $runtime['max_per_run']['value'];
        $mpp = cloner_worker_resolve_int($db, 'PACS_CLONER_MAX_PER_POLICY', 'pacs_cloner_max_per_policy', $maxRun, 1, 500);
        $runtime['max_per_policy'] = ['value' => $mpp['v'], 'source' => $mpp['source']];

        $gs = cloner_worker_resolve_string($db, 'PACS_CLONER_MODALITY_PRIORITY', 'pacs_cloner_modality_priority_global', '');
        $runtime['modality_priority_global'] = [
            'value' => strtoupper($gs['v']),
            'source' => $gs['source'],
        ];

        $ev = getenv('PACS_CLONER_RECONCILE_JOBS');
        if ($ev !== false && trim((string) $ev) !== '') {
            $rj = trim((string) $ev) !== '0';
            $reconcile = ['value' => $rj, 'source' => 'env'];
        } else {
            $dbVal = cloner_worker_config_get_string($db, 'pacs_cloner_reconcile_jobs_enabled');
            $rj = cloner_worker_config_flag_enabled($db, 'pacs_cloner_reconcile_jobs_enabled', true);
            $reconcile = ['value' => $rj, 'source' => ($dbVal !== null && trim($dbVal) !== '') ? 'db' : 'default'];
        }

        $lastRuns = [];
        try {
            $st = $db->query('
                SELECT id, started_at, finished_at, policies_touched, studies_discovered,
                       studies_skipped_local, studies_queued, notes
                FROM pacs_cloner_worker_runs
                ORDER BY id DESC
                LIMIT 12
            ');
            $lastRuns = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {
            $lastRuns = [];
        }

        $pending = 0;
        $running = 0;
        $recentOrders = [];
        try {
            $c1 = $db->query("SELECT COUNT(*) AS c FROM pacs_cloner_orders WHERE trigger_type = 'worker' AND status = 'pending'");
            $pending = (int) ($c1->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
            $c2 = $db->query("SELECT COUNT(*) AS c FROM pacs_cloner_orders WHERE trigger_type = 'worker' AND status = 'running'");
            $running = (int) ($c2->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
            $o = $db->query("
                SELECT o.id, o.node_id, o.status, o.label, o.created_at, o.started_at, o.pacs_node_job_id,
                       n.name AS node_name
                FROM pacs_cloner_orders o
                INNER JOIN pacs_nodes n ON n.id = o.node_id
                WHERE o.trigger_type = 'worker'
                ORDER BY o.id DESC
                LIMIT 20
            ");
            $recentOrders = $o ? $o->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {
            // ignorar
        }

        return [
            'flags' => [
                'v1_enabled' => cloner_worker_config_flag_enabled($db, 'pacs_cloner_worker_v1_enabled', true),
                'v2_enabled' => cloner_worker_config_flag_enabled($db, 'pacs_cloner_worker_v2_enabled', false),
            ],
            'runtime' => $runtime + ['reconcile_jobs_enabled' => $reconcile],
            'status' => [
                'last_runs' => $lastRuns,
                'worker_orders_pending' => $pending,
                'worker_orders_running' => $running,
                'recent_worker_orders' => $recentOrders,
            ],
            'hint' => 'Si el servidor define variables de entorno (PACS_CLONER_*), esas tienen prioridad sobre los valores guardados en la base.',
        ];
    }
}

if (!function_exists('pacs_cloner_save_worker_console')) {
    /**
     * @param array $body campos opcionales desde JSON
     */
    function pacs_cloner_save_worker_console(PDO $db, array $body) {
        $upsert = $db->prepare('
            INSERT INTO configuracion (clave, valor, descripcion)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE valor = VALUES(valor), descripcion = VALUES(descripcion)
        ');

        if (array_key_exists('v1_enabled', $body)) {
            $upsert->execute([
                'pacs_cloner_worker_v1_enabled',
                !empty($body['v1_enabled']) ? '1' : '0',
                'PACS Cloner worker v1 (bin/cloner-worker.php)',
            ]);
        }
        if (array_key_exists('v2_enabled', $body)) {
            $upsert->execute([
                'pacs_cloner_worker_v2_enabled',
                !empty($body['v2_enabled']) ? '1' : '0',
                'PACS Cloner worker v2 (bin/cloner-worker-v2.php)',
            ]);
        }

        $ints = [
            'max_per_run' => ['key' => 'pacs_cloner_max_per_run', 'desc' => 'Worker v1: máx. estudios nuevos encolados por corrida (total)', 'min' => 1, 'max' => 500],
            'max_per_policy' => ['key' => 'pacs_cloner_max_per_policy', 'desc' => 'Worker v1: máx. estudios nuevos encolados por política en una corrida', 'min' => 1, 'max' => 500],
            'dispatch_poll_sec' => ['key' => 'pacs_cloner_dispatch_poll_sec', 'desc' => 'Worker v1: segundos entre comprobaciones de cupo concurrente', 'min' => 1, 'max' => 120],
            'dispatch_max_sec' => ['key' => 'pacs_cloner_dispatch_max_sec', 'desc' => 'Worker v1: segundos máx. esperando cupo (0 = solo primera ronda)', 'min' => 0, 'max' => 86400],
            'dispatch_max_starts' => ['key' => 'pacs_cloner_dispatch_max_starts', 'desc' => 'Worker v1: máx. C-MOVE iniciados por política en una corrida', 'min' => 1, 'max' => 2000],
            'reconcile_jobs_limit' => ['key' => 'pacs_cloner_reconcile_jobs_limit', 'desc' => 'Worker v1: máx. jobs Orthanc a reconciliar por corrida', 'min' => 1, 'max' => 500],
            'stale_pending_minutes' => ['key' => 'pacs_cloner_stale_pending_minutes', 'desc' => 'Worker v1: pending más antiguos (min) → Fallido para liberar reintento', 'min' => 15, 'max' => 1440],
            'stale_running_hours' => ['key' => 'pacs_cloner_stale_running_hours', 'desc' => 'Worker v1: running sin cierre (h) → Fallido para liberar reintento', 'min' => 1, 'max' => 72],
        ];
        foreach ($ints as $field => $meta) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $v = (int) $body[$field];
            $v = max($meta['min'], min($meta['max'], $v));
            $upsert->execute([$meta['key'], (string) $v, $meta['desc']]);
        }

        if (array_key_exists('modality_priority_global', $body)) {
            $raw = strtoupper(trim((string) $body['modality_priority_global']));
            if (strlen($raw) > 256) {
                $raw = substr($raw, 0, 256);
            }
            $upsert->execute([
                'pacs_cloner_modality_priority_global',
                $raw,
                'Worker v1: prioridad modalidad global (CSV) si la política no define la suya',
            ]);
        }

        if (array_key_exists('reconcile_jobs_enabled', $body)) {
            $on = !empty($body['reconcile_jobs_enabled']);
            $upsert->execute([
                'pacs_cloner_reconcile_jobs_enabled',
                $on ? '1' : '0',
                'Worker v1: reconciliar jobs pending/running con Orthanc al inicio de cada corrida (salvo env PACS_CLONER_RECONCILE_JOBS)',
            ]);
        }

        return pacs_cloner_get_worker_console($db);
    }
}

if (!function_exists('pacs_cloner_policy_discovery_sort')) {
    function pacs_cloner_policy_discovery_sort($raw) {
        $v = is_string($raw) ? trim($raw) : '';
        $allowed = ['modality', 'oldest_study', 'newest_study'];

        return in_array($v, $allowed, true) ? $v : 'modality';
    }
}
