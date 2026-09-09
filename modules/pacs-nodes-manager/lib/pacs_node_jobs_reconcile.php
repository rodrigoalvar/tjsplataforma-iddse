<?php
/**
 * Reconciliación de pacs_node_jobs con Orthanc (misma lógica que GET jobs.php?id=).
 * Permite que el worker CLI avance estados sin abrir la pestaña Jobs.
 *
 * Jobs Orthanc "huérfanos" (p. ej. tras reinicio, GET /jobs/{id} → 404): se cierran en BD
 * como success si el estudio local ya tiene las instancias esperadas, o failed con mensaje
 * para liberar bloqueos del PACS Cloner y permitir reintento por C-MOVE.
 */

require_once __DIR__ . '/../PacsNodeClient.php';
require_once __DIR__ . '/cloner_order_helpers.php';

if (!function_exists('pacs_nodes_orthanc_job_failure_hint')) {
    /**
     * Texto breve para error_message cuando Orthanc marca el job C-MOVE en Failure.
     *
     * @param array $orthancStatus retorno de PacsNodeClient::getJobStatus()
     */
    function pacs_nodes_orthanc_job_failure_hint(array $orthancStatus) {
        if (!empty($orthancStatus['orthanc_message']) && trim((string) $orthancStatus['orthanc_message']) !== '') {
            return substr(trim((string) $orthancStatus['orthanc_message']), 0, 2000);
        }
        $c = $orthancStatus['content'] ?? [];
        if (!is_array($c)) {
            $c = [];
        }
        foreach (['ErrorMessage', 'Message', 'Description', 'HttpError', 'OrthancError'] as $k) {
            if (!empty($c[$k]) && trim((string) $c[$k]) !== '') {
                return substr(trim((string) $c[$k]), 0, 2000);
            }
        }
        $fi = (int) ($orthancStatus['failed_instances'] ?? 0);
        if ($fi > 0) {
            return 'Orthanc: FailedInstances=' . $fi;
        }

        return 'Job C-MOVE en estado Failure en Orthanc (sin detalle en Content).';
    }
}

if (!function_exists('pacs_nodes_orthanc_job_state_is_failure')) {
    function pacs_nodes_orthanc_job_state_is_failure($state) {
        $s = strtolower(trim((string) $state));

        return $s === 'failure' || $s === 'failed';
    }
}

if (!function_exists('pacs_nodes_orthanc_job_status_error_is_missing')) {
    /**
     * True si GET /jobs/{id} falló porque el job ya no existe (p. ej. reinicio de Orthanc).
     * No incluye errores transitorios de red (cURL).
     */
    function pacs_nodes_orthanc_job_status_error_is_missing($message) {
        $m = (string) $message;
        $lower = strtolower($m);
        if (strpos($m, 'HTTP 404') !== false || preg_match('/\b404\b/', $m)) {
            return true;
        }
        if (strpos($lower, 'unknown resource') !== false) {
            return true;
        }
        if (strpos($lower, 'unknown job') !== false) {
            return true;
        }
        if (strpos($lower, 'inexistent resource') !== false) {
            return true;
        }

        return false;
    }
}

if (!function_exists('pacs_nodes_reconcile_job_state')) {
    /**
     * Consulta Orthanc y conteo local; actualiza fila del job si cambió.
     *
     * @param PDO   $db
     * @param array $job Fila de pacs_node_jobs; study_instance_uids debe ser array (ya decodificado)
     * @return bool true si se ejecutó UPDATE en pacs_node_jobs
     */
    function pacs_nodes_reconcile_job_state(PDO $db, array &$job) {
        $jobId = (int) ($job['id'] ?? 0);
        if ($jobId < 1) {
            return false;
        }

        $expectedInstances = (int) ($job['expected_instances'] ?? 0);
        $expectedSeries = (int) ($job['expected_series'] ?? 0);
        $uids = $job['study_instance_uids'] ?? [];
        $studyUID = (is_array($uids) && !empty($uids[0])) ? $uids[0] : null;

        $isRunning = ($job['status'] === 'running' || $job['status'] === 'pending');
        $isImmediateJob = !empty($job['orthanc_job_id']) && strpos($job['orthanc_job_id'], 'completed-immediately-') === 0;
        $isRealOrthancJob = !empty($job['orthanc_job_id']) && !$isImmediateJob
            && strpos($job['orthanc_job_id'], 'unknown-') !== 0;

        $newReceivedInstances = (int) ($job['received_instances'] ?? 0);
        $newReceivedSeries = (int) ($job['received_series'] ?? 0);
        $newStatus = $job['status'];
        $newProgress = (int) ($job['progress'] ?? 0);
        $needsUpdate = false;

        $orthancJobCompleted = false;
        $orthancJobFailed = false;
        $orthancJobGone = false;
        $orthancStatusSnapshot = null;

        if ($isRunning && $isRealOrthancJob) {
            try {
                $client = new PacsNodeClient($db);
                $orthancStatus = $client->getJobStatus($job['orthanc_job_id']);
                $orthancStatusSnapshot = $orthancStatus;
                $oState = $orthancStatus['status'] ?? '';
                if ($oState === 'Success') {
                    $orthancJobCompleted = true;
                } elseif (pacs_nodes_orthanc_job_state_is_failure($oState)) {
                    $orthancJobFailed = true;
                }
                error_log("[JOBS][reconcile] Orthanc job {$job['orthanc_job_id']}: state={$oState} progress={$orthancStatus['progress']}");
            } catch (Throwable $e) {
                error_log('[JOBS][reconcile] Error consultando Orthanc job ' . ($job['orthanc_job_id'] ?? '') . ': ' . $e->getMessage());
                if (pacs_nodes_orthanc_job_status_error_is_missing($e->getMessage())) {
                    $orthancJobGone = true;
                }
            }
        }

        if (($isRunning || $isImmediateJob) && !empty($studyUID)) {
            try {
                if (!isset($client)) {
                    $client = new PacsNodeClient($db);
                }
                $studyInfo = $client->getLocalStudyInfo($studyUID);
                error_log('[JOBS][reconcile] getLocalStudyInfo(' . $studyUID . '): ' . json_encode($studyInfo));

                if ($studyInfo && $studyInfo['found'] && $studyInfo['instances'] > 0) {
                    $newReceivedInstances = $studyInfo['instances'];
                    $newReceivedSeries = $studyInfo['series'];

                    // Si expectedInstances es 0 (C-FIND no devolvió conteo al crear el job),
                    // intentar corregirlo consultando el PACS remoto ahora para poder cerrar el job.
                    if ($expectedInstances === 0 && !empty($job['node_id'])) {
                        try {
                            $nodeStmt = $db->prepare('SELECT * FROM pacs_nodes WHERE id = ?');
                            $nodeStmt->execute([(int) $job['node_id']]);
                            $nodeRow = $nodeStmt->fetch(PDO::FETCH_ASSOC);
                            if ($nodeRow && !empty($nodeRow['is_active'])) {
                                $findResult = $client->executeCFind($nodeRow, [
                                    'Level' => 'Study',
                                    'Query' => ['StudyInstanceUID' => $studyUID],
                                ]);
                                if (!empty($findResult[0])) {
                                    $ei = (int) ($findResult[0]['NumberOfStudyRelatedInstances'] ?? 0);
                                    $es = (int) ($findResult[0]['NumberOfStudyRelatedSeries'] ?? 0);
                                    if ($ei > 0) {
                                        $expectedInstances = $ei;
                                        $expectedSeries = $es;
                                        $db->prepare('UPDATE pacs_node_jobs SET expected_instances=?, expected_series=? WHERE id=?')
                                            ->execute([$expectedInstances, $expectedSeries, $jobId]);
                                        error_log("[JOBS][reconcile] expectedInstances corregido via C-FIND: job $jobId ei={$expectedInstances}");
                                    }
                                }
                            }
                        } catch (Throwable $eFi) {
                            error_log('[JOBS][reconcile] C-FIND para corregir expected job ' . $jobId . ': ' . $eFi->getMessage());
                        }
                    }

                    if ($expectedInstances > 0) {
                        $newProgress = min(99, round(($newReceivedInstances / $expectedInstances) * 100));
                    } else {
                        $newProgress = max(5, $newProgress);
                    }
                    $newStatus = 'running';
                    $needsUpdate = true;
                    if ($expectedInstances > 0 && $newReceivedInstances >= $expectedInstances) {
                        $newStatus = 'success';
                        $newProgress = 100;
                        error_log("[JOBS][reconcile] Auto-close por conteo local: job $jobId inst={$newReceivedInstances}/{$expectedInstances} -> success");
                    }
                } else {
                    $newProgress = 5;
                    $newStatus = 'running';
                    error_log("[JOBS][reconcile] /tools/find: job $jobId — estudio $studyUID aún no en Orthanc local");
                }
            } catch (Throwable $e) {
                error_log('[JOBS][reconcile] Error en getLocalStudyInfo job ' . $jobId . ': ' . $e->getMessage());
            }
        }

        $failErrorMessage = null;
        if ($orthancJobCompleted || $orthancJobFailed) {
            if ($orthancJobCompleted) {
                $newStatus = 'success';
                $newProgress = 100;
                if ($newReceivedInstances === 0 && $expectedInstances > 0) {
                    $newReceivedInstances = $expectedInstances;
                    $newReceivedSeries = $expectedSeries;
                }
                error_log("[JOBS][reconcile] Job $jobId completado en Orthanc. inst={$newReceivedInstances}/{$expectedInstances}");
            } else {
                $newStatus = 'failed';
                $newProgress = (int) ($job['progress'] ?? 0);
                $failErrorMessage = is_array($orthancStatusSnapshot)
                    ? pacs_nodes_orthanc_job_failure_hint($orthancStatusSnapshot)
                    : 'Orthanc: job C-MOVE en Failure.';
            }
            $needsUpdate = true;
        }

        if ($isImmediateJob && $isRunning) {
            $startedAt = strtotime($job['started_at'] ?? 'now');
            if ((time() - $startedAt) > 600) {
                $newStatus = 'success';
                $newProgress = 100;
                if ($newReceivedInstances === 0 && $expectedInstances > 0) {
                    $newReceivedInstances = $expectedInstances;
                    $newReceivedSeries = $expectedSeries;
                }
                $needsUpdate = true;
                error_log("[JOBS][reconcile] Job $jobId timeout (10min). Marcando success. inst={$newReceivedInstances}");
            }
        }

        if ($orthancJobGone && $isRealOrthancJob && ($newStatus === 'pending' || $newStatus === 'running')
            && !$orthancJobCompleted && !$orthancJobFailed) {
            $localComplete = ($expectedInstances > 0 && $newReceivedInstances >= $expectedInstances);
            if ($localComplete) {
                $newStatus = 'success';
                $newProgress = 100;
                $needsUpdate = true;
                error_log("[JOBS][reconcile] Job $jobId: job Orthanc huérfano pero estudio completo en local -> success inst={$newReceivedInstances}/{$expectedInstances}");
            } else {
                $newStatus = 'failed';
                $newProgress = (int) ($job['progress'] ?? 0);
                $needsUpdate = true;
                $failErrorMessage = 'Job Orthanc ya no disponible (404 o reinicio). El worker puede volver a solicitar el estudio si faltan instancias.';
                error_log("[JOBS][reconcile] Job $jobId: job Orthanc huérfano -> failed (inst local={$newReceivedInstances}, esperadas={$expectedInstances})");
            }
        }

        $rowUpdated = false;
        if ($needsUpdate) {
            $prevInstances = (int) ($job['received_instances'] ?? 0);
            $prevSeries = (int) ($job['received_series'] ?? 0);
            $prevStatus = $job['status'];

            if ($newReceivedInstances !== $prevInstances || $newReceivedSeries !== $prevSeries
                || $newStatus !== $prevStatus || $newProgress !== (int) ($job['progress'] ?? 0)
                || $failErrorMessage !== null) {
                $updateStmt = $db->prepare('
                    UPDATE pacs_node_jobs
                    SET status = ?,
                        progress = ?,
                        received_series = ?,
                        received_instances = ?,
                        error_message = COALESCE(?, error_message),
                        completed_at = CASE WHEN ? IN (\'success\', \'failed\') THEN NOW() ELSE completed_at END
                    WHERE id = ?
                ');
                $updateStmt->execute([
                    $newStatus, $newProgress,
                    $newReceivedSeries, $newReceivedInstances,
                    $failErrorMessage,
                    $newStatus, $jobId,
                ]);
                $rowUpdated = true;

                if ($newStatus === 'success' && $prevStatus !== 'success') {
                    $statsStmt = $db->prepare('
                        INSERT INTO pacs_node_statistics (node_id, date, retrieves_count, studies_retrieved)
                        VALUES (?, CURDATE(), 1, ?)
                        ON DUPLICATE KEY UPDATE
                            retrieves_count = retrieves_count + 1,
                            studies_retrieved = studies_retrieved + ?
                    ');
                    $cnt = is_array($uids) ? count($uids) : 0;
                    $statsStmt->execute([$job['node_id'], $cnt, $cnt]);

                    // Ancla SLA: estudio ya en Orthanc local
                    try {
                        $slaHelper = dirname(__DIR__, 3) . '/api/estudios/sla_helper.php';
                        if (is_file($slaHelper)) {
                            require_once $slaHelper;
                            $uidList = is_array($uids) ? $uids : [];
                            if (empty($uidList) && !empty($studyUID)) {
                                $uidList = [$studyUID];
                            }
                            foreach ($uidList as $oneUid) {
                                $oneUid = trim((string)$oneUid);
                                if ($oneUid === '') {
                                    continue;
                                }
                                sla_mark_local_arrived($db, [
                                    'study_instance_uid' => $oneUid,
                                ], null, 'cloner');
                            }
                        }
                    } catch (Throwable $slaEx) {
                        error_log('[JOBS][reconcile] sla arrived: ' . $slaEx->getMessage());
                    }
                }
                error_log("[JOBS][reconcile] Job $jobId actualizado: status=$newStatus progress=$newProgress% inst=$newReceivedInstances/$expectedInstances");
            }

            $job['status'] = $newStatus;
            $job['progress'] = $newProgress;
            $job['received_series'] = $newReceivedSeries;
            $job['received_instances'] = $newReceivedInstances;
            if ($failErrorMessage !== null) {
                $job['error_message'] = $failErrorMessage;
            }
        }

        $job['expected_instances'] = (int) ($job['expected_instances'] ?? 0);
        $job['expected_series'] = (int) ($job['expected_series'] ?? 0);
        $job['received_instances'] = (int) ($newReceivedInstances > 0 ? $newReceivedInstances : ($job['received_instances'] ?? 0));
        $job['received_series'] = (int) ($newReceivedSeries > 0 ? $newReceivedSeries : ($job['received_series'] ?? 0));
        $job['progress'] = (int) ($newProgress > 0 ? $newProgress : ($job['progress'] ?? 0));
        $job['status'] = $newStatus ?: ($job['status'] ?? 'running');

        return $rowUpdated;
    }
}

if (!function_exists('pacs_nodes_reconcile_active_jobs')) {
    /**
     * Reconciliar jobs pending/running (p. ej. al inicio del worker CLI).
     *
     * @return array{examined:int, updated:int}
     */
    function pacs_nodes_reconcile_active_jobs(PDO $db, $limit = 200) {
        $lim = max(1, min(500, (int) $limit));
        $examined = 0;
        $updated = 0;
        try {
            $st = $db->prepare('
                SELECT * FROM pacs_node_jobs
                WHERE status IN (\'pending\', \'running\')
                ORDER BY id ASC
                LIMIT ' . (int) $lim . '
            ');
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('[JOBS][reconcile] batch: ' . $e->getMessage());

            return ['examined' => 0, 'updated' => 0];
        }

        foreach ($rows as $row) {
            $examined++;
            $row['study_instance_uids'] = json_decode($row['study_instance_uids'] ?? '[]', true);
            if (!is_array($row['study_instance_uids'])) {
                $row['study_instance_uids'] = [];
            }
            if (pacs_nodes_reconcile_job_state($db, $row)) {
                $updated++;
            }
        }

        return ['examined' => $examined, 'updated' => $updated];
    }
}

if (!function_exists('pacs_nodes_fail_stale_active_jobs')) {
    /**
     * Marca como failed jobs pending/running demasiado antiguos para liberar el descubrimiento
     * del worker (JSON_CONTAINS en cloner_worker_has_active_job) y sincroniza órdenes cloner.
     *
     * @param int $pendingStaleMinutes mínimo 15, máximo 1440
     * @param int $runningStaleHours   mínimo 1, máximo 72
     *
     * @return array{pending_closed:int, running_closed:int}
     */
    function pacs_nodes_fail_stale_active_jobs(PDO $db, $pendingStaleMinutes, $runningStaleHours) {
        $pendingStaleMinutes = max(15, min(1440, (int) $pendingStaleMinutes));
        $runningStaleHours   = max(1,  min(72,   (int) $runningStaleHours));
        $pendingClosed = 0;
        $runningClosed = 0;

        // 1) Pending SIN orthanc_job_id: el C-MOVE nunca se inició (PHP falló antes).
        //    Threshold fijo de 20 min — independiente del parámetro configurable.
        $msgPFast = 'Job nunca inició el C-MOVE (sin orthanc_job_id tras 20 min). Timeout rápido; el worker puede reintentar.';
        try {
            $stFast = $db->prepare("
                UPDATE pacs_node_jobs
                SET status = 'failed',
                    error_message = ?,
                    completed_at = NOW()
                WHERE status = 'pending'
                  AND (orthanc_job_id IS NULL OR orthanc_job_id = '')
                  AND created_at < DATE_SUB(NOW(), INTERVAL 20 MINUTE)
            ");
            $stFast->execute([$msgPFast]);
            $pendingClosed += $stFast->rowCount();
        } catch (Throwable $e) {
            error_log('[JOBS][stale] pending-fast: ' . $e->getMessage());
        }

        // 2) Pending CON orthanc_job_id pero que sigue en pending (Orthanc no confirmó running).
        //    Usa el threshold configurable.
        $msgP = 'Timeout administrativo: en pending más de ' . $pendingStaleMinutes
            . ' min. Revise PHP/Orthanc; el worker puede reintentar el estudio.';
        try {
            $pm = (int) $pendingStaleMinutes;
            $st = $db->prepare("
                UPDATE pacs_node_jobs
                SET status = 'failed',
                    error_message = ?,
                    completed_at = NOW()
                WHERE status = 'pending'
                  AND orthanc_job_id IS NOT NULL AND orthanc_job_id <> ''
                  AND created_at < DATE_SUB(NOW(), INTERVAL {$pm} MINUTE)
            ");
            $st->execute([$msgP]);
            $pendingClosed += $st->rowCount();
        } catch (Throwable $e) {
            error_log('[JOBS][stale] pending-normal: ' . $e->getMessage());
        }

        // 3) Running sin cierre: usa el threshold configurable en horas.
        $msgR = 'Timeout administrativo: en running más de ' . $runningStaleHours
            . ' h sin cierre. Revise Orthanc/DIMSE; el worker puede reintentar.';
        try {
            $rh = (int) $runningStaleHours;
            $st2 = $db->prepare("
                UPDATE pacs_node_jobs
                SET status = 'failed',
                    error_message = ?,
                    completed_at = NOW()
                WHERE status = 'running'
                  AND COALESCE(started_at, created_at) < DATE_SUB(NOW(), INTERVAL {$rh} HOUR)
            ");
            $st2->execute([$msgR]);
            $runningClosed = $st2->rowCount();
        } catch (Throwable $e) {
            error_log('[JOBS][stale] running: ' . $e->getMessage());
        }

        if ($pendingClosed > 0 || $runningClosed > 0) {
            error_log('[JOBS][stale] Cerrados: pending=' . $pendingClosed . ' running=' . $runningClosed);
            pacsClonerSyncOrdersFromJobs($db);
        }

        return ['pending_closed' => $pendingClosed, 'running_closed' => $runningClosed];
    }
}
