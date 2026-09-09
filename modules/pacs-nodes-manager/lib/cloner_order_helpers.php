<?php
/**
 * Helpers para órdenes PACS Cloner (retrieve, API, worker).
 */

if (!function_exists('pacsClonerSyncOrdersFromJobs')) {
    /**
     * Alinea pacs_cloner_orders con el estado final de pacs_node_jobs.
     * El worker depende de esto para max_concurrent (evita bloqueo si solo se abría la UI).
     */
    function pacsClonerSyncOrdersFromJobs(PDO $db) {
        try {
            $db->exec("
                UPDATE pacs_cloner_orders o
                INNER JOIN pacs_node_jobs j ON j.id = o.pacs_node_job_id
                SET o.status = CASE j.status
                    WHEN 'success' THEN 'success'
                    WHEN 'failed' THEN 'failed'
                    WHEN 'cancelled' THEN 'cancelled'
                    ELSE o.status
                    END,
                o.completed_at = COALESCE(j.completed_at, o.completed_at, NOW())
                WHERE o.pacs_node_job_id IS NOT NULL
                  AND o.status IN ('pending','running')
                  AND j.status IN ('success','failed','cancelled')
            ");
        } catch (Throwable $e) {
            error_log('[PACS_CLONER] syncOrdersFromJobs: ' . $e->getMessage());
        }
    }
}

if (!function_exists('pacsClonerMarkOrderFailed')) {
    function pacsClonerMarkOrderFailed(?PDO $db, $clonerOrderId, $message) {
        if (!$clonerOrderId || !$db) {
            return;
        }
        try {
            $msg = substr((string) $message, 0, 2000);
            $stmt = $db->prepare("
                UPDATE pacs_cloner_orders
                SET status = 'failed', error_message = ?, completed_at = NOW()
                WHERE id = ? AND status IN ('pending','running')
            ");
            $stmt->execute([$msg, $clonerOrderId]);
        } catch (Throwable $e) {
            error_log('[PACS_CLONER] markOrderFailed: ' . $e->getMessage());
        }
    }
}
