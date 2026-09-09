<?php
/**
 * API PACS Cloner — políticas y órdenes de réplica controlada
 *
 * GET  cloner.php?policies=1           — listar políticas
 * GET  cloner.php?orders=1&limit=50   — listar órdenes (sincroniza estado con pacs_node_jobs)
 * GET  cloner.php?worker_flags=1      — flags v1/v2 (tabla configuracion)
 * GET  cloner.php?worker_console=1   — flags + parámetros runtime + última actividad del worker
 * POST { "action":"save_worker_console", ... } — guardar flags y parámetros (configuracion)
 * POST { "action":"create_policy", ... }
 * POST { "action":"update_policy", "id", ... mismos campos que create }
 * POST { "action":"delete_policy", "id" }
 * POST { "action":"dispatch", "node_id", "study_instance_uids":[], "label"?, "policy_id"?, "alignment_strategy"? }
 * POST { "action":"retry_cloner_order", "id": orderId }
 * POST { "action":"set_worker_flags", "v1_enabled": bool, "v2_enabled": bool }
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../lib/cloner_order_helpers.php';
require_once __DIR__ . '/../lib/cloner_worker_runtime_flags.php';
require_once __DIR__ . '/../lib/cloner_worker_console.php';
require_once __DIR__ . '/../lib/retrieve_execute.php';
require_once __DIR__ . '/../lib/cloner_alignment.php';
require_once __DIR__ . '/../lib/pacs_node_jobs_reconcile.php';

try {
    $user = requirePacsNodesAuth('pacs_nodes_manager');
    $db = getDBConnection();
    if (!$db) {
        sendErrorResponse('No se pudo conectar a la base de datos', 500);
    }

    $uid = isset($user['id']) ? (int) $user['id'] : null;

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!empty($_GET['policies'])) {
            $stmt = $db->query("
                SELECT p.*, n.name AS node_name
                FROM pacs_cloner_policies p
                INNER JOIN pacs_nodes n ON n.id = p.node_id
                ORDER BY p.name ASC
            ");
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            sendSuccessResponse(['policies' => $rows]);
        }

        if (!empty($_GET['worker_flags'])) {
            sendSuccessResponse([
                'v1_enabled' => cloner_worker_config_flag_enabled($db, 'pacs_cloner_worker_v1_enabled', true),
                'v2_enabled' => cloner_worker_config_flag_enabled($db, 'pacs_cloner_worker_v2_enabled', false),
            ]);
        }

        if (!empty($_GET['worker_console'])) {
            sendSuccessResponse(pacs_cloner_get_worker_console($db));
        }

        if (!empty($_GET['orders'])) {
            pacsClonerSyncOrdersFromJobs($db);
            $limit = isset($_GET['limit']) ? max(1, min(200, (int) $_GET['limit'])) : 50;
            $stmt = $db->prepare("
                SELECT o.*,
                       n.name AS node_name,
                       j.status AS job_status,
                       j.progress AS job_progress,
                       j.orthanc_job_id
                FROM pacs_cloner_orders o
                INNER JOIN pacs_nodes n ON n.id = o.node_id
                LEFT JOIN pacs_node_jobs j ON j.id = o.pacs_node_job_id
                ORDER BY o.id DESC
                LIMIT " . (int) $limit . "
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            sendSuccessResponse(['orders' => $rows]);
        }

        sendErrorResponse('Parámetro requerido: policies=1, orders=1, worker_flags=1 o worker_console=1', 400);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendErrorResponse('Método no permitido', 405);
    }

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!$body || !is_array($body)) {
        sendErrorResponse('JSON inválido', 400);
    }

    $action = $body['action'] ?? '';

    if ($action === 'set_worker_flags') {
        $v1 = !empty($body['v1_enabled']);
        $v2 = !empty($body['v2_enabled']);
        $upsert = $db->prepare('
            INSERT INTO configuracion (clave, valor, descripcion)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE valor = VALUES(valor), descripcion = VALUES(descripcion)
        ');
        $upsert->execute([
            'pacs_cloner_worker_v1_enabled',
            $v1 ? '1' : '0',
            'PACS Cloner worker v1 (bin/cloner-worker.php)',
        ]);
        $upsert->execute([
            'pacs_cloner_worker_v2_enabled',
            $v2 ? '1' : '0',
            'PACS Cloner worker v2 (bin/cloner-worker-v2.php)',
        ]);
        sendSuccessResponse([
            'v1_enabled' => $v1,
            'v2_enabled' => $v2,
        ], 'Preferencias de workers guardadas');
    }

    if ($action === 'save_worker_console') {
        $saved = pacs_cloner_save_worker_console($db, $body);
        sendSuccessResponse($saved, 'Parámetros del worker guardados');
    }

    if ($action === 'unlock_stale_jobs') {
        // Cierra jobs bloqueantes ahora mismo, sin esperar al siguiente cron del worker.
        // Umbrales: los mismos que usa el worker (configurables) + el fast-close de 20 min.
        $pendingMin = cloner_worker_resolve_int_value($db, 'PACS_CLONER_STALE_PENDING_MINUTES', 'pacs_cloner_stale_pending_minutes', 90, 15, 1440);
        $runningH   = cloner_worker_resolve_int_value($db, 'PACS_CLONER_STALE_RUNNING_HOURS',   'pacs_cloner_stale_running_hours',   6,  1,  72);
        $result = pacs_nodes_fail_stale_active_jobs($db, $pendingMin, $runningH);
        // También reconciliar activos para cerrar via Orthanc los que sí tienen job_id.
        $recon = pacs_nodes_reconcile_active_jobs($db, 200);
        pacsClonerSyncOrdersFromJobs($db);
        sendSuccessResponse([
            'pending_closed' => $result['pending_closed'],
            'running_closed' => $result['running_closed'],
            'reconciled'     => $recon['updated'],
        ], 'Bloqueos liberados. El worker descubrirá los estudios en la próxima corrida.');
    }

    if ($action === 'delete_policy') {
        $policyId = isset($body['id']) ? (int) $body['id'] : 0;
        if ($policyId < 1) {
            sendErrorResponse('id es requerido', 400);
        }
        $del = $db->prepare('DELETE FROM pacs_cloner_policies WHERE id = ?');
        $del->execute([$policyId]);
        if ($del->rowCount() === 0) {
            sendErrorResponse('Política no encontrada', 404);
        }
        sendSuccessResponse(['id' => $policyId], 'Política eliminada');
    }

    if ($action === 'update_policy') {
        $policyId = isset($body['id']) ? (int) $body['id'] : 0;
        if ($policyId < 1) {
            sendErrorResponse('id es requerido', 400);
        }
        $exists = $db->prepare('SELECT id FROM pacs_cloner_policies WHERE id = ?');
        $exists->execute([$policyId]);
        if (!$exists->fetch()) {
            sendErrorResponse('Política no encontrada', 404);
        }

        $name = trim((string) ($body['name'] ?? ''));
        $nodeId = isset($body['node_id']) ? (int) $body['node_id'] : 0;
        if ($name === '' || $nodeId < 1) {
            sendErrorResponse('name y node_id son requeridos', 400);
        }
        $isEnabled = !empty($body['is_enabled']) ? 1 : 0;
        $mode = $body['mode'] ?? 'paused';
        if (!in_array($mode, ['paused', 'assisted', 'automatic'], true)) {
            $mode = 'paused';
        }
        $scanHours = isset($body['scan_window_hours']) ? max(1, min(8760, (int) $body['scan_window_hours'])) : 24;
        $maxConc = isset($body['max_concurrent']) ? max(1, min(16, (int) $body['max_concurrent'])) : 2;
        $dateFrom = !empty($body['date_from']) ? $body['date_from'] : null;
        $dateTo = !empty($body['date_to']) ? $body['date_to'] : null;
        $modality = isset($body['modality_filter']) ? trim((string) $body['modality_filter']) : null;
        if ($modality === '') {
            $modality = null;
        }
        $priority = isset($body['modality_priority']) ? trim((string) $body['modality_priority']) : null;
        if ($priority === '') {
            $priority = null;
        } elseif ($priority !== null) {
            $priority = strtoupper($priority);
        }
        $notes = isset($body['notes']) ? trim((string) $body['notes']) : null;
        if ($notes === '') {
            $notes = null;
        }
        $alignmentStrategy = pacs_cloner_policy_alignment_strategy(['alignment_strategy' => $body['alignment_strategy'] ?? 'study']);
        $discoverySort = pacs_cloner_policy_discovery_sort($body['discovery_sort'] ?? 'modality');

        $chk = $db->prepare('SELECT id FROM pacs_nodes WHERE id = ?');
        $chk->execute([$nodeId]);
        if (!$chk->fetch()) {
            sendErrorResponse('Nodo no encontrado', 404);
        }

        $upd = $db->prepare('
            UPDATE pacs_cloner_policies SET
                name = ?, node_id = ?, is_enabled = ?, mode = ?, scan_window_hours = ?,
                date_from = ?, date_to = ?, max_concurrent = ?, modality_filter = ?, modality_priority = ?,
                alignment_strategy = ?, discovery_sort = ?, notes = ?
            WHERE id = ?
        ');
        $upd->execute([
            $name, $nodeId, $isEnabled, $mode, $scanHours,
            $dateFrom, $dateTo, $maxConc, $modality, $priority,
            $alignmentStrategy, $discoverySort, $notes,
            $policyId,
        ]);
        sendSuccessResponse(['id' => $policyId], 'Política actualizada');
    }

    if ($action === 'create_policy') {
        $name = trim((string) ($body['name'] ?? ''));
        $nodeId = isset($body['node_id']) ? (int) $body['node_id'] : 0;
        if ($name === '' || $nodeId < 1) {
            sendErrorResponse('name y node_id son requeridos', 400);
        }
        $isEnabled = !empty($body['is_enabled']) ? 1 : 0;
        $mode = $body['mode'] ?? 'paused';
        if (!in_array($mode, ['paused', 'assisted', 'automatic'], true)) {
            $mode = 'paused';
        }
        $scanHours = isset($body['scan_window_hours']) ? max(1, min(8760, (int) $body['scan_window_hours'])) : 24;
        $maxConc = isset($body['max_concurrent']) ? max(1, min(16, (int) $body['max_concurrent'])) : 2;
        $dateFrom = !empty($body['date_from']) ? $body['date_from'] : null;
        $dateTo = !empty($body['date_to']) ? $body['date_to'] : null;
        $modality = isset($body['modality_filter']) ? trim((string) $body['modality_filter']) : null;
        if ($modality === '') {
            $modality = null;
        }
        $priority = isset($body['modality_priority']) ? trim((string) $body['modality_priority']) : null;
        if ($priority === '') {
            $priority = null;
        } elseif ($priority !== null) {
            $priority = strtoupper($priority);
        }
        $notes = isset($body['notes']) ? trim((string) $body['notes']) : null;
        if ($notes === '') {
            $notes = null;
        }
        $alignmentStrategy = pacs_cloner_policy_alignment_strategy(['alignment_strategy' => $body['alignment_strategy'] ?? 'study']);
        $discoverySort = pacs_cloner_policy_discovery_sort($body['discovery_sort'] ?? 'modality');

        $chk = $db->prepare('SELECT id FROM pacs_nodes WHERE id = ?');
        $chk->execute([$nodeId]);
        if (!$chk->fetch()) {
            sendErrorResponse('Nodo no encontrado', 404);
        }

        $ins = $db->prepare("
            INSERT INTO pacs_cloner_policies
            (name, node_id, is_enabled, mode, scan_window_hours, date_from, date_to, max_concurrent, modality_filter, modality_priority, alignment_strategy, discovery_sort, notes, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $ins->execute([
            $name, $nodeId, $isEnabled, $mode, $scanHours, $dateFrom, $dateTo, $maxConc, $modality, $priority, $alignmentStrategy, $discoverySort, $notes, $uid,
        ]);
        $newId = (int) $db->lastInsertId();
        sendSuccessResponse(['id' => $newId], 'Política creada');
    }

    if ($action === 'retry_cloner_order') {
        $orderId = isset($body['id']) ? (int) $body['id'] : 0;
        if ($orderId < 1) {
            sendErrorResponse('id es requerido', 400);
        }
        $st = $db->prepare('SELECT * FROM pacs_cloner_orders WHERE id = ?');
        $st->execute([$orderId]);
        $ord = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ord) {
            sendErrorResponse('Orden no encontrada', 404);
        }
        if (($ord['status'] ?? '') !== 'failed') {
            sendErrorResponse('Solo se puede reintentar una orden en estado failed', 400);
        }
        $nodeIdOrd = (int) ($ord['node_id'] ?? 0);
        $uidsRetry = json_decode($ord['study_instance_uids'] ?? '[]', true);
        if (!is_array($uidsRetry) || count($uidsRetry) === 0) {
            sendErrorResponse('La orden no tiene StudyInstanceUIDs', 400);
        }
        $firstUid = trim((string) ($uidsRetry[0] ?? ''));
        if ($firstUid === '') {
            sendErrorResponse('StudyInstanceUID inválido', 400);
        }
        $jchk = $db->prepare("
            SELECT id FROM pacs_node_jobs
            WHERE node_id = ?
              AND status IN ('pending','running')
              AND JSON_CONTAINS(study_instance_uids, CAST(? AS JSON), '\$')
            LIMIT 1
        ");
        $jchk->execute([$nodeIdOrd, json_encode($firstUid, JSON_UNESCAPED_UNICODE)]);
        if ($jchk->fetch(PDO::FETCH_ASSOC)) {
            sendErrorResponse('Ya hay un job activo para este estudio en el mismo nodo', 409);
        }
        $up = $db->prepare("
            UPDATE pacs_cloner_orders
            SET status = 'pending', error_message = NULL, completed_at = NULL, started_at = NULL,
                pacs_node_job_id = NULL
            WHERE id = ? AND status = 'failed'
        ");
        $up->execute([$orderId]);
        if ($up->rowCount() === 0) {
            sendErrorResponse('No se pudo reabrir la orden', 400);
        }
        sendSuccessResponse(['id' => $orderId], 'Orden reencolada como pending; el worker la despachará');
    }

    if ($action === 'dispatch') {
        $nodeId = isset($body['node_id']) ? (int) $body['node_id'] : 0;
        $uids = $body['study_instance_uids'] ?? [];
        if ($nodeId < 1) {
            sendErrorResponse('node_id es requerido', 400);
        }
        if (!is_array($uids) || count($uids) === 0) {
            sendErrorResponse('study_instance_uids debe ser un array no vacío', 400);
        }
        $clean = [];
        foreach ($uids as $u) {
            $u = is_string($u) ? trim($u) : '';
            if ($u !== '') {
                $clean[] = $u;
            }
        }
        if (count($clean) === 0) {
            sendErrorResponse('No hay StudyInstanceUID válidos', 400);
        }

        $policyId = isset($body['policy_id']) ? (int) $body['policy_id'] : null;
        if ($policyId !== null && $policyId < 1) {
            $policyId = null;
        }
        $label = isset($body['label']) ? trim((string) $body['label']) : '';
        if ($label === '') {
            $label = null;
        }

        $chk = $db->prepare('SELECT id FROM pacs_nodes WHERE id = ? AND is_active = 1');
        $chk->execute([$nodeId]);
        if (!$chk->fetch()) {
            sendErrorResponse('Nodo no encontrado o inactivo', 404);
        }

        $ins = $db->prepare("
            INSERT INTO pacs_cloner_orders
            (policy_id, node_id, trigger_type, label, study_instance_uids, status, created_by)
            VALUES (?, ?, 'ui', ?, ?, 'pending', ?)
        ");
        $ins->execute([
            $policyId,
            $nodeId,
            $label,
            json_encode($clean, JSON_UNESCAPED_UNICODE),
            $uid,
        ]);
        $orderId = (int) $db->lastInsertId();

        $pacsNodeConfigLoaded = false;
        if (file_exists(__DIR__ . '/../PacsNodeConfig.php')) {
            require_once __DIR__ . '/../PacsNodeConfig.php';
            $pacsNodeConfigLoaded = true;
        }

        $runUser = ['id' => $uid];

        $pacsClient = new PacsNodeClient($db);
        $stNode = $db->prepare('SELECT * FROM pacs_nodes WHERE id = ?');
        $stNode->execute([$nodeId]);
        $nodeRow = $stNode->fetch(PDO::FETCH_ASSOC);

        $alignFrom = 'study';
        $polRow = null;
        if ($policyId) {
            $pst = $db->prepare('SELECT * FROM pacs_cloner_policies WHERE id = ?');
            $pst->execute([$policyId]);
            $polRow = $pst->fetch(PDO::FETCH_ASSOC);
            if ($polRow) {
                $alignFrom = pacs_cloner_policy_alignment_strategy($polRow);
            }
        }
        if ($polRow === null && isset($body['alignment_strategy'])) {
            $alignFrom = pacs_cloner_policy_alignment_strategy(['alignment_strategy' => $body['alignment_strategy']]);
        }
        $moveExtra = ($alignFrom !== 'study' && $nodeRow)
            ? pacs_cloner_build_retrieve_move_options($pacsClient, $nodeRow, $alignFrom, $clean)
            : [];

        $runData = array_merge([
            'node_id' => $nodeId,
            'StudyInstanceUIDs' => $clean,
            'cloner_order_id' => $orderId,
        ], $moveExtra);
        $res = pacs_nodes_run_retrieve($db, $runUser, $runData, $pacsNodeConfigLoaded && class_exists('PacsNodeConfig'));

        if (empty($res['success'])) {
            $err = $res['error'] ?? 'Error en retrieve';
            pacsClonerMarkOrderFailed($db, $orderId, $err);
            http_response_code((int) ($res['http_code'] ?? 500));
            echo encodeApiJson([
                'success' => false,
                'error' => $err,
                'detail' => $res['detail'] ?? null,
                'order_id' => $orderId,
            ]);
            exit;
        }

        sendSuccessResponse([
            'order_id' => $orderId,
            'retrieve' => array_merge(['success' => true], $res['data'] ?? []),
        ], 'Recuperación iniciada');
    }

    sendErrorResponse('Acción no reconocida', 400);
} catch (Throwable $e) {
    error_log('[PACS_CLONER] ' . $e->getMessage());
    http_response_code(500);
    echo encodeApiJson([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
    exit;
}
