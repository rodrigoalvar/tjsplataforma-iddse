<?php
/**
 * Ejecución interna de C-MOVE (compartida por retrieve.php, cloner.php y worker CLI).
 */

require_once __DIR__ . '/cloner_order_helpers.php';
require_once __DIR__ . '/../PacsNodeClient.php';
require_once __DIR__ . '/../../../api/config/orthanc_config.php';
if (file_exists(__DIR__ . '/../PacsNodeConfig.php')) {
    require_once __DIR__ . '/../PacsNodeConfig.php';
}

/**
 * @param array $user Debe incluir 'id' (int|null) para created_by del job.
 * @param array $data node_id, StudyInstanceUIDs[], Callback?, TargetAet?, cloner_order_id?
 * @return array{success:true,data:array}|array{success:false,http_code:int,error:string,detail:?string}
 */
function pacs_nodes_run_retrieve(PDO $db, array $user, array $data, bool $pacsNodeConfigLoaded) {
    $nodeId = $data['node_id'] ?? null;
    $studyInstanceUIDs = $data['StudyInstanceUIDs'] ?? [];
    $callbackUrl = $data['Callback'] ?? null;
    $targetAet = $data['TargetAet'] ?? null;
    $clonerOrderId = isset($data['cloner_order_id']) ? (int) $data['cloner_order_id'] : 0;
    $cMoveLevel = isset($data['c_move_level']) ? trim((string) $data['c_move_level']) : '';
    $cMoveResources = (isset($data['c_move_resources']) && is_array($data['c_move_resources'])) ? $data['c_move_resources'] : null;
    if ($clonerOrderId < 1) {
        $clonerOrderId = null;
    }

    $createdBy = array_key_exists('id', $user) ? $user['id'] : null;

    error_log('[RETRIEVE][exec] nodeId=' . json_encode($nodeId) . ' uids=' . json_encode($studyInstanceUIDs) . ' clonerOrder=' . ($clonerOrderId ?? 'null'));

    if (!$nodeId) {
        pacsClonerMarkOrderFailed($db, $clonerOrderId, 'node_id es requerido');

        return ['success' => false, 'http_code' => 400, 'error' => 'node_id es requerido', 'detail' => null];
    }
    if (empty($studyInstanceUIDs)) {
        pacsClonerMarkOrderFailed($db, $clonerOrderId, 'StudyInstanceUIDs es requerido');

        return ['success' => false, 'http_code' => 400, 'error' => 'StudyInstanceUIDs es requerido', 'detail' => null];
    }
    if (!is_array($studyInstanceUIDs)) {
        pacsClonerMarkOrderFailed($db, $clonerOrderId, 'StudyInstanceUIDs debe ser un array');

        return ['success' => false, 'http_code' => 400, 'error' => 'StudyInstanceUIDs debe ser un array', 'detail' => null];
    }

    $stmt = $db->prepare('SELECT * FROM pacs_nodes WHERE id = ?');
    $stmt->execute([$nodeId]);
    $node = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$node) {
        pacsClonerMarkOrderFailed($db, $clonerOrderId, 'Nodo no encontrado');

        return ['success' => false, 'http_code' => 404, 'error' => 'Nodo no encontrado', 'detail' => null];
    }
    if (!$node['is_active']) {
        pacsClonerMarkOrderFailed($db, $clonerOrderId, 'Nodo inactivo');

        return ['success' => false, 'http_code' => 400, 'error' => 'Nodo inactivo', 'detail' => null];
    }

    if ($clonerOrderId) {
        $co = $db->prepare('SELECT id, node_id, status FROM pacs_cloner_orders WHERE id = ?');
        $co->execute([$clonerOrderId]);
        $cord = $co->fetch(PDO::FETCH_ASSOC);
        if (!$cord || (string) $cord['node_id'] !== (string) $nodeId) {
            return ['success' => false, 'http_code' => 400, 'error' => 'Orden PACS Cloner no encontrada o no coincide con el nodo', 'detail' => null];
        }
        if ($cord['status'] !== 'pending') {
            return ['success' => false, 'http_code' => 409, 'error' => 'La orden PACS Cloner ya fue procesada', 'detail' => null];
        }
    }

    foreach ($studyInstanceUIDs as $uid) {
        if (empty($uid) || !is_string($uid)) {
            pacsClonerMarkOrderFailed($db, $clonerOrderId, 'StudyInstanceUID inválido');

            return [
                'success' => false,
                'http_code' => 400,
                'error' => 'StudyInstanceUID inválido',
                'detail' => 'UID debe ser un string no vacío. Recibido: ' . var_export($uid, true),
            ];
        }
    }

    if (($node['node_type'] === 'dimse' || $node['node_type'] === 'hybrid') &&
        $pacsNodeConfigLoaded && class_exists('PacsNodeConfig')) {
        try {
            error_log('[RETRIEVE][exec] Sincronizando nodo con Orthanc antes de C-MOVE...');
            $config = new PacsNodeConfig($db);
            $syncResult = $config->syncNodeWithOrthanc($node);
            if ($syncResult && isset($syncResult['success']) && $syncResult['success']) {
                $stmt = $db->prepare('SELECT * FROM pacs_nodes WHERE id = ?');
                $stmt->execute([$nodeId]);
                $node = $stmt->fetch(PDO::FETCH_ASSOC);
                $modalityId = $node['orthanc_node_id'] ?? $node['aet'] ?? null;
                if ($modalityId) {
                    try {
                        $client = new PacsNodeClient($db);
                        $verifyUrl = $client->orthancBaseUrl . '/modalities?expand';
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $verifyUrl);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                        $credentials = OrthancConfig::getCredentials();
                        curl_setopt($ch, CURLOPT_USERPWD, $credentials['username'] . ':' . $credentials['password']);
                        $verifyResponse = curl_exec($ch);
                        $verifyHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);
                        if ($verifyHttpCode === 200) {
                            $allModalities = json_decode($verifyResponse, true);
                            $nodeConfig = (is_array($allModalities) && isset($allModalities[$modalityId])) ? $allModalities[$modalityId] : null;
                            if ($nodeConfig && is_array($nodeConfig)) {
                                if (empty($nodeConfig['AET']) || empty($nodeConfig['Host']) || empty($nodeConfig['Port'])) {
                                    throw new Exception('El nodo no está correctamente configurado en Orthanc. AET, Host o Port están vacíos.');
                                }
                            }
                        }
                    } catch (Exception $e) {
                        error_log('[RETRIEVE][exec] Verificación modalidad: ' . $e->getMessage());
                    }
                }
            }
        } catch (Exception $e) {
            error_log('[RETRIEVE][exec] sync Orthanc: ' . $e->getMessage());
        }
    }

    $jobStmt = $db->prepare('
        INSERT INTO pacs_node_jobs
        (node_id, job_type, study_instance_uids, status, callback_url, created_by)
        VALUES (?, \'c_move\', ?, \'pending\', ?, ?)
    ');
    $jobStmt->execute([
        $nodeId,
        json_encode($studyInstanceUIDs),
        $callbackUrl,
        $createdBy,
    ]);
    $jobId = $db->lastInsertId();

    if ($clonerOrderId) {
        try {
            $link = $db->prepare('
                UPDATE pacs_cloner_orders
                SET pacs_node_job_id = ?, status = \'running\', started_at = NOW()
                WHERE id = ? AND status = \'pending\'
            ');
            $link->execute([$jobId, $clonerOrderId]);
        } catch (Throwable $e) {
            error_log('[RETRIEVE][exec][cloner] link job: ' . $e->getMessage());
        }
    }

    $expectedSeries = 0;
    $expectedInstances = 0;
    try {
        $client = new PacsNodeClient($db);
        if (!empty($studyInstanceUIDs[0])) {
            $studyUID = $studyInstanceUIDs[0];
            $findQuery = [
                'Level' => 'Study',
                'Query' => [
                    'StudyInstanceUID' => $studyUID,
                ],
            ];
            $findResults = $client->executeCFind($node, $findQuery);
            if (!empty($findResults)) {
                $studyInfo = $findResults[0];
                $expectedSeries = (int) ($studyInfo['NumberOfStudyRelatedSeries'] ?? 0);
                $expectedInstances = (int) ($studyInfo['NumberOfStudyRelatedInstances'] ?? 0);
                // Si el C-FIND de Study no devuelve conteos (dcm4chee lento o sin atributos),
                // NO hacer fallback Instance/Series aquí: esas queries pueden colgar 120 s cada una.
                // La reconciliación ya resuelve expected_instances=0 de forma asíncrona.
                $updateExpectedStmt = $db->prepare('
                    UPDATE pacs_node_jobs
                    SET expected_series = ?, expected_instances = ?
                    WHERE id = ?
                ');
                $updateExpectedStmt->execute([$expectedSeries, $expectedInstances, $jobId]);
            }
        }
    } catch (Exception $e) {
        error_log('[RETRIEVE][exec] info estudio: ' . $e->getMessage());
    }

    try {
        $client = new PacsNodeClient($db);
        if ($cMoveLevel !== '' && !empty($cMoveResources) && in_array($cMoveLevel, ['Study', 'Series', 'Instance'], true)) {
            $moveResult = $client->executeCMoveAtLevel($node, $cMoveLevel, $cMoveResources, $targetAet);
        } else {
            $moveResult = $client->executeCMove($node, $studyInstanceUIDs, $targetAet);
        }

        $updateJobStmt = $db->prepare('
            UPDATE pacs_node_jobs
            SET orthanc_job_id = ?, status = \'running\', started_at = NOW()
            WHERE id = ?
        ');

        $jobStatus = 'running';
        $jobProgress = 0;
        $failMsgImmediate = null;
        $isImmediateResponse = strpos($moveResult['job_id'], 'completed-immediately-') === 0;
        $orthancState = $moveResult['status'] ?? 'Running';
        $orthancStateNorm = strtolower(trim((string) $orthancState));

        if ($isImmediateResponse) {
            $jobStatus = 'running';
            $jobProgress = 0;
        } elseif ($orthancStateNorm === 'failure' || $orthancStateNorm === 'failed') {
            $jobStatus = 'failed';
            $jobProgress = 0;
            $fr = $moveResult['full_response'] ?? [];
            if (is_array($fr)) {
                foreach (['Message', 'Description', 'HttpError', 'OrthancError'] as $k) {
                    if (!empty($fr[$k]) && is_scalar($fr[$k])) {
                        $t = trim((string) $fr[$k]);
                        if ($t !== '') {
                            $failMsgImmediate = substr($t, 0, 2000);
                            break;
                        }
                    }
                }
                if (($failMsgImmediate === null || $failMsgImmediate === '') && isset($fr['Content']) && is_array($fr['Content'])) {
                    $c2 = $fr['Content'];
                    foreach (['ErrorMessage', 'Message', 'Description'] as $k) {
                        if (!empty($c2[$k]) && is_scalar($c2[$k])) {
                            $t = trim((string) $c2[$k]);
                            if ($t !== '') {
                                $failMsgImmediate = substr($t, 0, 2000);
                                break;
                            }
                        }
                    }
                }
            }
            if ($failMsgImmediate === null || $failMsgImmediate === '') {
                $failMsgImmediate = 'Orthanc devolvió estado ' . $orthancState . ' al iniciar C-MOVE.';
            }
        } else {
            $jobStatus = 'running';
            $jobProgress = $moveResult['progress'] ?? 0;
        }

        $updateJobStmt->execute([
            $moveResult['job_id'],
            $jobId,
        ]);

        if ($jobStatus !== 'running' || $jobProgress > 0) {
            if ($jobStatus === 'failed' && $failMsgImmediate !== null) {
                $updateStatusStmt = $db->prepare('
                    UPDATE pacs_node_jobs
                    SET status = ?, progress = ?, error_message = ?, completed_at = NOW()
                    WHERE id = ?
                ');
                $updateStatusStmt->execute([$jobStatus, $jobProgress, $failMsgImmediate, $jobId]);
            } else {
                $updateStatusStmt = $db->prepare('
                    UPDATE pacs_node_jobs
                    SET status = ?, progress = ?
                    WHERE id = ?
                ');
                $updateStatusStmt->execute([$jobStatus, $jobProgress, $jobId]);
            }
        }

        if ($jobStatus === 'failed') {
            $em = $failMsgImmediate ?? 'C-MOVE fallido en Orthanc';
            pacsClonerMarkOrderFailed($db, $clonerOrderId, $em);

            return [
                'success' => false,
                'http_code' => 502,
                'error' => $em,
                'detail' => 'Orthanc rechazó o falló el job C-MOVE al iniciar',
            ];
        }

        $successPayload = [
            'job_id' => $jobId,
            'orthanc_job_id' => $moveResult['job_id'],
            'status' => $jobStatus,
            'progress' => $jobProgress,
            'message' => $jobStatus === 'success' ? 'Estudio recuperado exitosamente' : 'Job iniciado correctamente',
        ];
        if ($clonerOrderId) {
            $successPayload['cloner_order_id'] = $clonerOrderId;
        }

        return ['success' => true, 'data' => $successPayload];
    } catch (Exception $e) {
        error_log('[RETRIEVE][exec] C-MOVE: ' . $e->getMessage());
        try {
            $updateJobStmt = $db->prepare('
                UPDATE pacs_node_jobs
                SET status = \'failed\', error_message = ?, completed_at = NOW()
                WHERE id = ?
            ');
            $updateJobStmt->execute([$e->getMessage(), $jobId]);
        } catch (Exception $updateError) {
            error_log('[RETRIEVE][exec] update job: ' . $updateError->getMessage());
        }
        pacsClonerMarkOrderFailed($db, $clonerOrderId, $e->getMessage());
        $httpCode = 500;
        if (strpos($e->getMessage(), 'requerido') !== false ||
            strpos($e->getMessage(), 'inválido') !== false ||
            strpos($e->getMessage(), 'no encontrado') !== false) {
            $httpCode = 400;
        }

        return [
            'success' => false,
            'http_code' => $httpCode,
            'error' => $e->getMessage(),
            'detail' => 'Error al ejecutar C-MOVE en Orthanc',
        ];
    }
}
