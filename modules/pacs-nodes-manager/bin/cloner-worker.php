#!/usr/bin/env php
<?php
/**
 * Worker PACS Cloner — ejecutar desde cron/systemd (no requiere sesión web).
 *
 * Políticas: is_enabled=1, mode='automatic', nodo activo y no local.
 * Descubrimiento: C-FIND Study por StudyDate (rango) y opcional ModalitiesInStudy.
 * Sincronización incremental: compara instancias remotas (C-FIND) vs Orthanc local.
 *   - Si local < remoto → C-MOVE.
 *   - Si local ≥ remoto y el conteo remoto es igual al de la pasada anterior (tabla snapshot)
 *     → el remoto está estable y se da por completo (no encola).
 *   - Si local ≥ remoto pero es la primera pasada o el remoto cambió desde la última vez
 *     → no encola aún (espera siguiente cron, p. ej. 10 min) por si el equipo sigue subiendo imágenes.
 * Requiere tabla pacs_cloner_study_snapshot (migration_pacs_cloner_study_snapshot.sql).
 *
 * Variables de entorno opcionales:
 *   PACS_CLONER_MAX_PER_RUN   — máximo de estudios a encolar en total (default 25)
 *   PACS_CLONER_MAX_PER_POLICY — máximo por política (default = MAX_PER_RUN)
 *   PACS_CLONER_DISPATCH_POLL_SEC — espera entre comprobaciones de cupo (default 5)
 *   PACS_CLONER_DISPATCH_MAX_SEC — tiempo máx. esperando a que baje la concurrencia (default 900; 0=solo primera ronda)
 *   PACS_CLONER_DISPATCH_MAX_STARTS — máx. C-MOVE iniciados en una corrida por política (default 200)
 *   PACS_CLONER_RECONCILE_JOBS — si es "0", no consulta Orthanc para jobs pending/running (default: activo)
 *   PACS_CLONER_RECONCILE_JOBS_LIMIT — máx. jobs a reconciliar por corrida (default 200, máx. 500)
 *
 * Parámetros equivalentes en tabla configuracion (si la variable de entorno no está definida):
 *   pacs_cloner_max_per_run, pacs_cloner_max_per_policy, pacs_cloner_dispatch_poll_sec,
 *   pacs_cloner_dispatch_max_sec, pacs_cloner_dispatch_max_starts, pacs_cloner_reconcile_jobs_limit,
 *   pacs_cloner_modality_priority_global (CSV), pacs_cloner_reconcile_jobs_enabled (1/0),
 *   pacs_cloner_stale_pending_minutes (default 90), pacs_cloner_stale_running_hours (default 6).
 * Variables de entorno (cierre de jobs bloqueantes):
 *   PACS_CLONER_STALE_PENDING_MINUTES — pending más antiguos → failed (15–1440)
 *   PACS_CLONER_STALE_RUNNING_HOURS — running sin cierre → failed (1–72)
 * Editable desde la UI PACS Cloner (worker_console).
 *
 * Ejemplo cron (cada 10 min): línea con asterisco-barra-diez en crontab + resto de campos,
 *   php /var/www/tjsiddse/modules/pacs-nodes-manager/bin/cloner-worker.php >> /var/log/pacs-cloner-worker.log 2>&1
 *
 * Opcional: worker 2.0 en script aparte (desactivado por defecto en BD):
 *   php .../cloner-worker-v2.php
 * El v1 sigue activo salvo que desactive pacs_cloner_worker_v1_enabled en configuracion.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Este script solo puede ejecutarse en CLI.\n");
    exit(1);
}

$base = dirname(__DIR__);

require_once $base . '/../../config/database.php';
require_once $base . '/PacsNodeClient.php';
require_once $base . '/lib/cloner_order_helpers.php';
require_once $base . '/lib/cloner_worker_runtime_flags.php';
require_once $base . '/lib/pacs_node_jobs_reconcile.php';
require_once $base . '/lib/retrieve_execute.php';

$pacsNodeConfigLoaded = false;
if (file_exists($base . '/PacsNodeConfig.php')) {
    require_once $base . '/PacsNodeConfig.php';
    $pacsNodeConfigLoaded = true;
}

/**
 * Rango DICOM StudyDate YYYYMMDD-YYYYMMDD.
 *
 * El extremo superior siempre avanza +1 día extra para cubrir:
 *   - Equipos DICOM con reloj adelantado (fecha futura en StudyDate).
 *   - Zonas horarias del equipo distintas a UTC (p. ej. UTC+X puede ser "mañana" cuando
 *     el servidor ya calcula "hoy" en UTC).
 * Sin este buffer, estudios con fecha del día siguiente quedan fuera del C-FIND y nunca
 * se descubren hasta que el servidor también llegue a esa fecha.
 */
function cloner_worker_study_date_range(array $policy) {
    $from = $policy['date_from'] ?? null;
    $to = $policy['date_to'] ?? null;
    if (!empty($from) && !empty($to)) {
        $d0 = preg_replace('/\D/', '', substr((string) $from, 0, 10));
        $d1 = preg_replace('/\D/', '', substr((string) $to, 0, 10));
        if (strlen($d0) === 8 && strlen($d1) === 8) {
            if ($d0 > $d1) {
                $t = $d0;
                $d0 = $d1;
                $d1 = $t;
            }

            return $d0 . '-' . $d1;
        }
    }
    $hours = max(1, (int) ($policy['scan_window_hours'] ?? 24));
    $end = new DateTime('now', new DateTimeZone('UTC'));
    $start = clone $end;
    $start->sub(new DateInterval('PT' . $hours . 'H'));
    // +1 día de buffer para equipos con reloj adelantado o en zona horaria distinta a UTC.
    $endBuffer = clone $end;
    $endBuffer->add(new DateInterval('P1D'));

    return $start->format('Ymd') . '-' . $endBuffer->format('Ymd');
}

function cloner_worker_row_matches_modality(array $row, $filterRaw) {
    $f = trim((string) $filterRaw);
    if ($f === '') {
        return true;
    }
    $mods = array_map('trim', explode(',', $f));
    $studyMods = (string) ($row['ModalitiesInStudy'] ?? '');
    foreach ($mods as $m) {
        if ($m !== '' && stripos($studyMods, $m) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Prioridad de modalidades para ordenar encolado.
 * Acepta lista CSV, ej.: "CR,DX,CT,MR,US"
 */
function cloner_worker_parse_modality_priority($raw) {
    $parts = array_filter(array_map('trim', explode(',', strtoupper($raw))));
    $out = [];
    foreach ($parts as $i => $m) {
        if ($m !== '' && !isset($out[$m])) {
            $out[$m] = $i;
        }
    }

    return $out;
}

function cloner_worker_modality_rank(array $row, array $priorityMap) {
    if (empty($priorityMap)) {
        return PHP_INT_MAX;
    }
    $modsRaw = strtoupper((string) ($row['ModalitiesInStudy'] ?? ''));
    if ($modsRaw === '') {
        return PHP_INT_MAX;
    }
    $mods = array_filter(array_map('trim', explode('\\', str_replace(',', '\\', $modsRaw))));
    $best = PHP_INT_MAX;
    foreach ($mods as $m) {
        if (isset($priorityMap[$m]) && $priorityMap[$m] < $best) {
            $best = $priorityMap[$m];
        }
    }

    return $best;
}

/**
 * Clave comparable YYYYMMDDHHMMSS desde tags DICOM del estudio (C-FIND Study).
 */
function cloner_worker_study_datetime_key(array $row) {
    $d = preg_replace('/\D/', '', (string) ($row['StudyDate'] ?? ''));
    if (strlen($d) >= 8) {
        $d = substr($d, 0, 8);
    } else {
        $d = '00000000';
    }
    $t = preg_replace('/\D/', '', (string) ($row['StudyTime'] ?? ''));
    if (strlen($t) >= 6) {
        $t = substr($t, 0, 6);
    } else {
        $t = '000000';
    }

    return $d . $t;
}

/**
 * Ordena resultados C-FIND Study antes de evaluar cola (por política).
 *
 * @param array $results referencia a lista de filas asociativas
 * @param string $discoverySort modality|oldest_study|newest_study
 */
function cloner_worker_sort_cfind_results(array &$results, $discoverySort, array $policyModalityPriorityMap) {
    $sort = in_array($discoverySort, ['modality', 'oldest_study', 'newest_study'], true) ? $discoverySort : 'modality';
    if ($sort === 'modality') {
        if (empty($policyModalityPriorityMap)) {
            return;
        }
        usort($results, function ($a, $b) use ($policyModalityPriorityMap) {
            $ra = cloner_worker_modality_rank(is_array($a) ? $a : [], $policyModalityPriorityMap);
            $rb = cloner_worker_modality_rank(is_array($b) ? $b : [], $policyModalityPriorityMap);
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            $ka = cloner_worker_study_datetime_key(is_array($a) ? $a : []);
            $kb = cloner_worker_study_datetime_key(is_array($b) ? $b : []);

            return strcmp($ka, $kb);
        });

        return;
    }
    if ($sort === 'oldest_study') {
        usort($results, function ($a, $b) use ($policyModalityPriorityMap) {
            $ka = cloner_worker_study_datetime_key(is_array($a) ? $a : []);
            $kb = cloner_worker_study_datetime_key(is_array($b) ? $b : []);
            $c = strcmp($ka, $kb);
            if ($c !== 0) {
                return $c;
            }
            if (!empty($policyModalityPriorityMap)) {
                $ra = cloner_worker_modality_rank(is_array($a) ? $a : [], $policyModalityPriorityMap);
                $rb = cloner_worker_modality_rank(is_array($b) ? $b : [], $policyModalityPriorityMap);
                if ($ra !== $rb) {
                    return $ra <=> $rb;
                }
            }

            return 0;
        });

        return;
    }
    usort($results, function ($a, $b) use ($policyModalityPriorityMap) {
        $ka = cloner_worker_study_datetime_key(is_array($a) ? $a : []);
        $kb = cloner_worker_study_datetime_key(is_array($b) ? $b : []);
        $c = strcmp($kb, $ka);
        if ($c !== 0) {
            return $c;
        }
        if (!empty($policyModalityPriorityMap)) {
            $ra = cloner_worker_modality_rank(is_array($a) ? $a : [], $policyModalityPriorityMap);
            $rb = cloner_worker_modality_rank(is_array($b) ? $b : [], $policyModalityPriorityMap);
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
        }

        return 0;
    });
}

/**
 * Mejor esfuerzo: instancias en remoto (Study luego Instance count).
 */
function cloner_worker_resolve_remote_instance_count(PacsNodeClient $client, array $node, array $row, $suid) {
    $n = (int) ($row['NumberOfStudyRelatedInstances'] ?? 0);
    if ($n > 0) {
        return $n;
    }
    try {
        $r = $client->executeCFind($node, [
            'Level' => 'Study',
            'Query' => ['StudyInstanceUID' => $suid],
        ]);
        if (!empty($r[0])) {
            $n2 = (int) ($r[0]['NumberOfStudyRelatedInstances'] ?? 0);
            if ($n2 > 0) {
                return $n2;
            }
        }
    } catch (Throwable $e) {
        error_log('[cloner-worker] C-FIND Study por UID: ' . $e->getMessage());
    }
    try {
        $inst = $client->executeCFind($node, [
            'Level' => 'Instance',
            'Query' => ['StudyInstanceUID' => $suid],
        ]);

        return is_array($inst) ? count($inst) : 0;
    } catch (Throwable $e) {
        error_log('[cloner-worker] C-FIND Instance count: ' . $e->getMessage());

        return 0;
    }
}

function cloner_worker_get_snapshot_prev(PDO $db, $nodeId, $suid) {
    try {
        $st = $db->prepare('SELECT last_remote_instance_count FROM pacs_cloner_study_snapshot WHERE node_id = ? AND study_instance_uid = ?');
        $st->execute([$nodeId, $suid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);

        return $r ? (int) $r['last_remote_instance_count'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function cloner_worker_upsert_snapshot(PDO $db, $nodeId, $suid, $remoteInst) {
    try {
        $st = $db->prepare('
            INSERT INTO pacs_cloner_study_snapshot (node_id, study_instance_uid, last_remote_instance_count, updated_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE last_remote_instance_count = VALUES(last_remote_instance_count), updated_at = NOW()
        ');
        $st->execute([$nodeId, $suid, max(0, (int) $remoteInst)]);
    } catch (Throwable $e) {
        error_log('[cloner-worker] snapshot: ' . $e->getMessage());
    }
}

function cloner_worker_has_active_job(PDO $db, $nodeId, $suid) {
    try {
        $j = json_encode($suid, JSON_UNESCAPED_UNICODE);
        $st = $db->prepare("
            SELECT id FROM pacs_node_jobs
            WHERE node_id = ?
              AND status IN ('pending','running')
              AND JSON_CONTAINS(study_instance_uids, CAST(? AS JSON), '\$')
            LIMIT 1
        ");
        $st->execute([$nodeId, $j]);

        return (bool) $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Órdenes con C-MOVE ya iniciado (no cuenta pending sin job).
 */
function cloner_worker_count_policy_inflight(PDO $db, $policyId) {
    try {
        $st = $db->prepare("
            SELECT COUNT(*) AS c
            FROM pacs_cloner_orders
            WHERE policy_id = ?
              AND status = 'running'
        ");
        $st->execute([(int) $policyId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);

        return (int) ($r['c'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function cloner_worker_has_pending_cloner_order(PDO $db, $policyId, $suid) {
    try {
        $j = json_encode($suid, JSON_UNESCAPED_UNICODE);
        $st = $db->prepare("
            SELECT id FROM pacs_cloner_orders
            WHERE policy_id = ?
              AND status = 'pending'
              AND (pacs_node_job_id IS NULL OR pacs_node_job_id = 0)
              AND JSON_CONTAINS(study_instance_uids, CAST(? AS JSON), '\$')
            LIMIT 1
        ");
        $st->execute([(int) $policyId, $j]);

        return (bool) $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function cloner_worker_fetch_next_pending_order(PDO $db, $policyId) {
    try {
        $st = $db->prepare("
            SELECT * FROM pacs_cloner_orders
            WHERE policy_id = ?
              AND status = 'pending'
              AND (pacs_node_job_id IS NULL OR pacs_node_job_id = 0)
            ORDER BY id ASC
            LIMIT 1
        ");
        $st->execute([(int) $policyId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);

        return $r ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Inicia retrieves hasta max_concurrent; espera (poll) a que baje la cola si hace falta.
 *
 * @param array|null $policyRow fila pacs_cloner_policies (alignment_strategy); null = solo estudio
 */
function cloner_worker_dispatch_pending_for_policy(
    PDO $db,
    PacsNodeClient $client,
    array $workerUser,
    $policyId,
    $nodeId,
    ?array $policyRow,
    $maxConcurrent,
    $pacsNodeConfigLoaded,
    array &$stats,
    $maxStartsPerRun,
    $pollSec,
    $maxWaitSec
) {
    $policyId = (int) $policyId;
    $nodeId = (int) $nodeId;
    $maxConcurrent = max(1, (int) $maxConcurrent);
    $maxStartsPerRun = max(1, (int) $maxStartsPerRun);
    $pollSec = max(1, (int) $pollSec);
    $allowWait = (int) $maxWaitSec > 0;
    $deadline = time() + (int) $maxWaitSec;
    if ($allowWait && function_exists('set_time_limit')) {
        @set_time_limit(min(3600, (int) $maxWaitSec + 120));
    }
    $dispatchAttempts = 0;

    while (true) {
        pacsClonerSyncOrdersFromJobs($db);
        $inflight = cloner_worker_count_policy_inflight($db, $policyId);
        $next = cloner_worker_fetch_next_pending_order($db, $policyId);

        if ($next === null && $inflight === 0) {
            break;
        }
        if ($next === null && $inflight > 0) {
            if (!$allowWait || time() >= $deadline) {
                break;
            }
            sleep($pollSec);
            continue;
        }
        if ($inflight >= $maxConcurrent) {
            if (!$allowWait || time() >= $deadline) {
                break;
            }
            sleep($pollSec);
            continue;
        }
        if ($dispatchAttempts >= $maxStartsPerRun) {
            break;
        }

        $orderId = (int) ($next['id'] ?? 0);
        if ($orderId < 1) {
            break;
        }
        $uids = json_decode($next['study_instance_uids'] ?? '[]', true);
        if (!is_array($uids) || count($uids) === 0) {
            pacsClonerMarkOrderFailed($db, $orderId, 'study_instance_uids vacío');
            $dispatchAttempts++;
            continue;
        }

        $stmtNode = $db->prepare('SELECT * FROM pacs_nodes WHERE id = ?');
        $stmtNode->execute([$nodeId]);
        $nodeRow = $stmtNode->fetch(PDO::FETCH_ASSOC);
        if (!$nodeRow) {
            pacsClonerMarkOrderFailed($db, $orderId, 'Nodo no encontrado');
            $dispatchAttempts++;
            continue;
        }

        require_once dirname(__DIR__) . '/lib/cloner_alignment.php';
        $strategy = is_array($policyRow) ? pacs_cloner_policy_alignment_strategy($policyRow) : 'study';
        $moveExtra = ($strategy !== 'study')
            ? pacs_cloner_build_retrieve_move_options($client, $nodeRow, $strategy, $uids)
            : [];

        $runPayload = array_merge([
            'node_id' => $nodeId,
            'StudyInstanceUIDs' => $uids,
            'cloner_order_id' => $orderId,
        ], $moveExtra);

        $res = pacs_nodes_run_retrieve($db, $workerUser, $runPayload, $pacsNodeConfigLoaded && class_exists('PacsNodeConfig'));

        $dispatchAttempts++;

        if (empty($res['success'])) {
            $err = $res['error'] ?? 'retrieve failed';
            error_log('[cloner-worker] dispatch order=' . $orderId . ' ' . $err);
            pacsClonerMarkOrderFailed($db, $orderId, $err);
        } else {
            $stats['studies_queued']++;
        }
    }
}

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "[cloner-worker] Sin conexión a BD\n");
    exit(1);
}

if (!cloner_worker_config_flag_enabled($db, 'pacs_cloner_worker_v1_enabled', true)) {
    echo "[cloner-worker] Deshabilitado (pacs_cloner_worker_v1_enabled). Sin acción.\n";
    exit(0);
}

$stalePendingMin = cloner_worker_resolve_int_value(
    $db,
    'PACS_CLONER_STALE_PENDING_MINUTES',
    'pacs_cloner_stale_pending_minutes',
    90,
    15,
    1440
);
$staleRunH = cloner_worker_resolve_int_value(
    $db,
    'PACS_CLONER_STALE_RUNNING_HOURS',
    'pacs_cloner_stale_running_hours',
    6,
    1,
    72
);
$stale = pacs_nodes_fail_stale_active_jobs($db, $stalePendingMin, $staleRunH);
if (($stale['pending_closed'] ?? 0) + ($stale['running_closed'] ?? 0) > 0) {
    echo '[cloner-worker] stale_jobs pending_closed=' . (int) ($stale['pending_closed'] ?? 0)
        . ' running_closed=' . (int) ($stale['running_closed'] ?? 0) . "\n";
}

if (cloner_worker_should_reconcile_jobs($db)) {
    $reconLimit = cloner_worker_resolve_int_value(
        $db,
        'PACS_CLONER_RECONCILE_JOBS_LIMIT',
        'pacs_cloner_reconcile_jobs_limit',
        200,
        1,
        500
    );
    $recon = pacs_nodes_reconcile_active_jobs($db, $reconLimit);
    if ($recon['examined'] > 0 && ($recon['updated'] > 0 || getenv('PACS_CLONER_RECONCILE_JOBS_VERBOSE') === '1')) {
        echo '[cloner-worker] jobs_reconcile examined=' . $recon['examined'] . ' updated=' . $recon['updated'] . "\n";
    }
}

pacsClonerSyncOrdersFromJobs($db);

$maxTotal = cloner_worker_resolve_int_value($db, 'PACS_CLONER_MAX_PER_RUN', 'pacs_cloner_max_per_run', 25, 1, 500);
$maxPerPolicyResolved = cloner_worker_resolve_int($db, 'PACS_CLONER_MAX_PER_POLICY', 'pacs_cloner_max_per_policy', $maxTotal, 1, 500);
$maxPerPolicy = $maxPerPolicyResolved['v'];
$globalModStr = cloner_worker_resolve_string($db, 'PACS_CLONER_MODALITY_PRIORITY', 'pacs_cloner_modality_priority_global', '');
$globalModalityPriorityMap = cloner_worker_parse_modality_priority($globalModStr['v']);

$runId = null;
try {
    $db->exec("INSERT INTO pacs_cloner_worker_runs (started_at) VALUES (NOW())");
    $runId = (int) $db->lastInsertId();
} catch (Throwable $e) {
    error_log('[cloner-worker] Sin tabla pacs_cloner_worker_runs (ejecute migration_pacs_cloner_worker.sql): ' . $e->getMessage());
}

$stats = [
    'policies_touched' => 0,
    'studies_discovered' => 0,
    'studies_skipped_stable' => 0,
    'studies_skipped_active_job' => 0,
    'studies_skipped_defer' => 0,
    'studies_pending_new' => 0,
    'studies_queued' => 0,
];

$queuedTotal = 0;
$dispatchPollSec = cloner_worker_resolve_int_value($db, 'PACS_CLONER_DISPATCH_POLL_SEC', 'pacs_cloner_dispatch_poll_sec', 5, 1, 120);
$dispatchMaxSec = cloner_worker_resolve_int_value($db, 'PACS_CLONER_DISPATCH_MAX_SEC', 'pacs_cloner_dispatch_max_sec', 900, 0, 86400);
$dispatchMaxStarts = cloner_worker_resolve_int_value($db, 'PACS_CLONER_DISPATCH_MAX_STARTS', 'pacs_cloner_dispatch_max_starts', 200, 1, 2000);

try {
    $sql = "
        SELECT p.*
        FROM pacs_cloner_policies p
        INNER JOIN pacs_nodes n ON n.id = p.node_id
        WHERE p.is_enabled = 1
          AND p.mode = 'automatic'
          AND n.is_active = 1
          AND n.node_type <> 'local'
        ORDER BY p.id ASC
    ";
    $policies = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    fwrite(STDERR, '[cloner-worker] Error leyendo políticas: ' . $e->getMessage() . "\n");
    exit(1);
}

if (count($policies) === 0) {
    echo '[' . gmdate('c') . "] Sin políticas automáticas habilitadas (is_enabled + mode=automatic).\n";
    if ($runId) {
        $db->prepare("UPDATE pacs_cloner_worker_runs SET finished_at = NOW(), notes = ? WHERE id = ?")
            ->execute(['Sin políticas', $runId]);
    }
    exit(0);
}

$client = new PacsNodeClient($db);
$workerUser = ['id' => null];

foreach ($policies as $policy) {
    if ($queuedTotal >= $maxTotal) {
        break;
    }
    $stats['policies_touched']++;
    $policyId = (int) $policy['id'];
    $nodeId = (int) $policy['node_id'];

    $stmt = $db->prepare('SELECT * FROM pacs_nodes WHERE id = ?');
    $stmt->execute([$nodeId]);
    $node = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$node || !$node['is_active'] || ($node['node_type'] ?? '') === 'local') {
        continue;
    }

    $range = cloner_worker_study_date_range($policy);
    $findQuery = [
        'Level' => 'Study',
        'Query' => [
            'StudyDate' => $range,
        ],
        'limit' => min(200, $maxPerPolicy * 4),
    ];
    $modalityFilter = trim((string) ($policy['modality_filter'] ?? ''));
    // Un solo código en el filtro: acotar C-FIND. Varios (CT,MR): filtrar solo en PHP para no perder modalidades.
    if ($modalityFilter !== '' && strpos($modalityFilter, ',') === false) {
        $findQuery['Query']['ModalitiesInStudy'] = $modalityFilter;
    }

    try {
        $results = $client->executeCFind($node, $findQuery);
    } catch (Throwable $e) {
        error_log('[cloner-worker] C-FIND policy=' . $policyId . ' node=' . $nodeId . ': ' . $e->getMessage());
        continue;
    }

    if (!is_array($results)) {
        continue;
    }
    $policyModalityPriorityRaw = trim((string) ($policy['modality_priority'] ?? ''));
    $policyModalityPriorityMap = $policyModalityPriorityRaw !== ''
        ? cloner_worker_parse_modality_priority($policyModalityPriorityRaw)
        : $globalModalityPriorityMap;
    $discoverySort = isset($policy['discovery_sort']) ? (string) $policy['discovery_sort'] : 'modality';
    cloner_worker_sort_cfind_results($results, $discoverySort, $policyModalityPriorityMap);

    $policyPendingCreated = 0;
    $policyMaxConcurrent = max(1, (int) ($policy['max_concurrent'] ?? 2));
    foreach ($results as $row) {
        if ($queuedTotal >= $maxTotal || $policyPendingCreated >= $maxPerPolicy) {
            break 1;
        }
        if (!cloner_worker_row_matches_modality($row, $modalityFilter)) {
            continue;
        }
        $suid = trim((string) ($row['StudyInstanceUID'] ?? ''));
        if ($suid === '') {
            continue;
        }
        $stats['studies_discovered']++;

        $remoteInst = cloner_worker_resolve_remote_instance_count($client, $node, $row, $suid);
        $local = $client->getLocalStudyInfo($suid);
        $localInst = (is_array($local) && !empty($local['found'])) ? (int) ($local['instances'] ?? 0) : 0;
        $remotePrev = cloner_worker_get_snapshot_prev($db, $nodeId, $suid);

        if (cloner_worker_has_active_job($db, $nodeId, $suid)) {
            $stats['studies_skipped_active_job']++;
            cloner_worker_upsert_snapshot($db, $nodeId, $suid, $remoteInst);
            continue;
        }

        $needQueue = false;
        if ($remoteInst > 0 && $localInst < $remoteInst) {
            // Local tiene menos instancias que remoto → encolar siempre.
            $needQueue = true;
        } elseif ($remoteInst === 0 && $localInst === 0) {
            // No se pudo obtener conteo remoto Y local tampoco tiene nada → encolar.
            // Evita que remoteInst=0 bloquee indefinidamente estudios ausentes en local.
            $needQueue = true;
            error_log('[cloner-worker] remoteInst=0 y localInst=0 para suid=' . $suid . ' node=' . $nodeId . '; se encola por seguridad.');
        } elseif ($remoteInst > 0 && $remotePrev !== null && $remoteInst === $remotePrev && $localInst >= $remoteInst) {
            // Remoto estable y local completo → estudio ya alineado.
            $stats['studies_skipped_stable']++;
        } elseif ($remoteInst === 0 && $localInst > 0) {
            // C-FIND no devolvió conteo pero local tiene algo: asumir alineado (defer silencioso).
            $stats['studies_skipped_defer']++;
        } else {
            // Primera pasada (remotePrev null) u otro caso no determinado → defer para siguiente corrida.
            $stats['studies_skipped_defer']++;
        }

        cloner_worker_upsert_snapshot($db, $nodeId, $suid, $remoteInst);

        if (!$needQueue) {
            continue;
        }

        if (cloner_worker_has_pending_cloner_order($db, $policyId, $suid)) {
            continue;
        }

        $label = 'worker policy #' . $policyId . ' ' . ($policy['name'] ?? '');
        $label = substr($label, 0, 255);

        try {
            $ins = $db->prepare('
                INSERT INTO pacs_cloner_orders
                (policy_id, node_id, trigger_type, label, study_instance_uids, status, created_by)
                VALUES (?, ?, \'worker\', ?, ?, \'pending\', NULL)
            ');
            $ins->execute([
                $policyId,
                $nodeId,
                $label,
                json_encode([$suid], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {
            error_log('[cloner-worker] INSERT order: ' . $e->getMessage());
            continue;
        }

        $stats['studies_pending_new']++;
        $policyPendingCreated++;
        $queuedTotal++;
    }

    cloner_worker_dispatch_pending_for_policy(
        $db,
        $client,
        $workerUser,
        $policyId,
        $nodeId,
        $policy,
        $policyMaxConcurrent,
        $pacsNodeConfigLoaded,
        $stats,
        $dispatchMaxStarts,
        $dispatchPollSec,
        $dispatchMaxSec
    );
}

$skippedTotal = $stats['studies_skipped_stable'] + $stats['studies_skipped_active_job'] + $stats['studies_skipped_defer'];
$note = sprintf(
    'policies=%d discovered=%d dispatched=%d pending_new=%d skip_total=%d (stable=%d active_job=%d defer=%d)',
    $stats['policies_touched'],
    $stats['studies_discovered'],
    $stats['studies_queued'],
    $stats['studies_pending_new'],
    $skippedTotal,
    $stats['studies_skipped_stable'],
    $stats['studies_skipped_active_job'],
    $stats['studies_skipped_defer']
);
echo '[' . gmdate('c') . "] {$note}\n";

if ($runId) {
    try {
        $u = $db->prepare('
            UPDATE pacs_cloner_worker_runs SET
                finished_at = NOW(),
                policies_touched = ?,
                studies_discovered = ?,
                studies_skipped_local = ?,
                studies_queued = ?,
                notes = ?
            WHERE id = ?
        ');
        $u->execute([
            $stats['policies_touched'],
            $stats['studies_discovered'],
            $skippedTotal,
            $stats['studies_queued'],
            substr($note, 0, 500),
            $runId,
        ]);
    } catch (Throwable $e) {
        error_log('[cloner-worker] update run: ' . $e->getMessage());
    }
}

exit(0);
