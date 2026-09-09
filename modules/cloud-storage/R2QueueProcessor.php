<?php
/**
 * Procesador de cola de estudios para subida a R2
 * Sistema TJSMEDICAL - Cloud Storage Module
 */

// Rutas absolutas para evitar problemas con chdir()
$moduleDir = __DIR__;
$projectRoot = dirname(dirname($moduleDir));

require_once $projectRoot . '/config/database.php';
require_once $moduleDir . '/drivers/R2StorageDriver.php';
require_once $moduleDir . '/ManifestBuilder.php';
require_once $moduleDir . '/config/cloud_storage_config.php';
require_once $projectRoot . '/api/OrthancClient.php';

class R2QueueProcessor {
    private $db;
    private $r2Driver;
    private $manifestBuilder;
    private $orthancClient;
    private $maxConcurrency;
    private $maxRetries;
    private $config;

    /** @var array<string,bool> */
    private $r2StudiesColumnCache = [];

    /** @var array<string,bool> */
    private $r2QueueColumnCache = [];
    
    public function __construct($db, $r2Driver, $manifestBuilder, $orthancClient) {
        $this->db = $db;
        $this->r2Driver = $r2Driver;
        $this->manifestBuilder = $manifestBuilder;
        $this->orthancClient = $orthancClient;
        
        $this->config = CloudStorageConfig::load();
        $this->maxConcurrency = (int)($this->config['r2_upload_concurrency'] ?? 2);
        $this->maxRetries = 3;
    }

    private function r2StudiesHasColumn(string $name): bool {
        if (!array_key_exists($name, $this->r2StudiesColumnCache)) {
            try {
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'r2_studies' AND COLUMN_NAME = ?
                ");
                $stmt->execute([$name]);
                $this->r2StudiesColumnCache[$name] = ((int) $stmt->fetchColumn()) > 0;
            } catch (Exception $e) {
                $this->r2StudiesColumnCache[$name] = false;
            }
        }
        return $this->r2StudiesColumnCache[$name];
    }

    private function r2QueueHasColumn(string $name): bool {
        if (!array_key_exists($name, $this->r2QueueColumnCache)) {
            try {
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'r2_queue' AND COLUMN_NAME = ?
                ");
                $stmt->execute([$name]);
                $this->r2QueueColumnCache[$name] = ((int) $stmt->fetchColumn()) > 0;
            } catch (Exception $e) {
                $this->r2QueueColumnCache[$name] = false;
            }
        }
        return $this->r2QueueColumnCache[$name];
    }

    private function setR2StudySyncStatus(string $orthancStudyId, string $status): void {
        if (!$this->r2StudiesHasColumn('r2_sync_status')) {
            return;
        }
        if (!in_array($status, ['idle', 'pending', 'syncing'], true)) {
            return;
        }
        try {
            $stmt = $this->db->prepare('
                UPDATE r2_studies SET r2_sync_status = ?, updated_at = NOW()
                WHERE orthanc_study_id = ?
            ');
            $stmt->execute([$status, $orthancStudyId]);
        } catch (Exception $e) {
            error_log('[R2_QUEUE] setR2StudySyncStatus: ' . $e->getMessage());
        }
    }

    /**
     * Enforcement de cuota: si no alcanza espacio para el nuevo estudio, opcionalmente recicla
     * eliminando estudios más viejos (no bloqueados) y vuelve a validar.
     *
     * Límite y umbrales se configuran en GB desde Quote Manager.
     */
    private function enforceQuotaBeforeUpload($orthancStudyId, $queueId, $incomingBytes) {
        try {
            $quotaEnabled = (bool)($this->config['r2_quota_enabled'] ?? false);
            $limitGb = (float)($this->config['r2_quota_limit_gb'] ?? 0);
            $recycleEnabled = (bool)($this->config['r2_recycle_enabled'] ?? false);

            if (!$quotaEnabled) return;
            if ($limitGb <= 0) return;

            $incomingBytes = (int)$incomingBytes;
            if ($incomingBytes < 0) $incomingBytes = 0;

            $limitBytes = (int)round($limitGb * 1024 * 1024 * 1024);
            if ($limitBytes <= 0) return;

            // Trigger informativo (no se usa para "no reciclar" si igual hay que liberar)
            $triggerPercent = (float)($this->config['r2_recycle_trigger_percent'] ?? 0);
            $triggerGb = (float)($this->config['r2_recycle_trigger_gb'] ?? 0);

            $triggerPercentBytes = $triggerPercent > 0
                ? (int)round($limitBytes * ($triggerPercent / 100.0))
                : 0;
            $triggerGbBytes = $triggerGb > 0
                ? (int)round($triggerGb * 1024 * 1024 * 1024)
                : 0;

            $usedBytes = $this->getUsedBytesFromDbForQuotaEnforcement();
            $fits = ($usedBytes + $incomingBytes) <= $limitBytes;
            if ($fits) return;

            // No alcanza para el nuevo estudio
            if (!$recycleEnabled) {
                throw new Exception(
                    'QUOTA_EXCEEDED: cuota excedida y reciclado deshabilitado. used_bytes=' . $usedBytes .
                    ' incoming_bytes=' . $incomingBytes . ' limit_bytes=' . $limitBytes .
                    ' trigger_percent_bytes=' . $triggerPercentBytes . ' trigger_gb_bytes=' . $triggerGbBytes
                );
            }

            // A partir de acá reciclamos para liberar espacio.
            $guardKey = $this->getQuotaGuardLockKey();
            $this->acquireQuotaGuardLock($guardKey, 12);

            try {
                // Revalidar tras adquirir lock (evita carreras entre workers)
                $usedBytes = $this->getUsedBytesFromDbForQuotaEnforcement();
                if (($usedBytes + $incomingBytes) <= $limitBytes) return;

                $neededFree = ($usedBytes + $incomingBytes) - $limitBytes;

                // Si el umbral (percent y/o GB) ya fue alcanzado, intentamos también
                // bajar el uso por debajo de dicho umbral antes de subir.
                $thresholdBytes = 0;
                if ($triggerPercentBytes > 0 && $triggerGbBytes > 0) {
                    $thresholdBytes = min($triggerPercentBytes, $triggerGbBytes);
                } elseif ($triggerPercentBytes > 0) {
                    $thresholdBytes = $triggerPercentBytes;
                } elseif ($triggerGbBytes > 0) {
                    $thresholdBytes = $triggerGbBytes;
                }

                if ($thresholdBytes > 0 && $usedBytes >= $thresholdBytes) {
                    $neededFreeByTrigger = $usedBytes - $thresholdBytes;
                    if ($neededFreeByTrigger > $neededFree) {
                        $neededFree = $neededFreeByTrigger;
                    }
                }
                $this->recycleOldUnlockedStudiesToFree($neededFree, $limitBytes, $incomingBytes, $queueId);

                // Validar resultado final
                $usedAfter = $this->getUsedBytesFromDbForQuotaEnforcement();
                if (($usedAfter + $incomingBytes) > $limitBytes) {
                    throw new Exception(
                        'QUOTA_EXCEEDED_AFTER_RECYCLE: no se liberó espacio suficiente. used_bytes=' . $usedAfter .
                        ' incoming_bytes=' . $incomingBytes . ' limit_bytes=' . $limitBytes
                    );
                }
            } finally {
                $this->releaseQuotaGuardLock($guardKey);
            }
        } catch (Exception $e) {
            // Propagar hacia processStudy para marcar job como error (sin reintentos).
            throw $e;
        }
    }

    private function getQuotaGuardLockKey() {
        $bucket = (string)($this->config['r2_bucket_name'] ?? '');
        $prefix = (string)($this->config['r2_storage_prefix'] ?? 'studies/');
        return 'r2_quota_guard_' . md5($bucket . '|' . $prefix);
    }

    private function acquireQuotaGuardLock($lockKey, $timeoutSeconds = 10) {
        $stmt = $this->db->prepare("SELECT GET_LOCK(?, ?) AS l");
        $stmt->execute([$lockKey, (int)$timeoutSeconds]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $locked = (int)($row['l'] ?? 0);
        if ($locked !== 1) {
            throw new Exception('QUOTA_LOCK_FAILED: no se pudo obtener lock de cuota: ' . $lockKey);
        }
    }

    private function releaseQuotaGuardLock($lockKey) {
        try {
            $stmt = $this->db->prepare("SELECT RELEASE_LOCK(?) AS r");
            $stmt->execute([$lockKey]);
        } catch (Exception $e) {
            error_log('[R2_QUEUE][QUOTA] WARNING: fallo al liberar lock: ' . $e->getMessage());
        }
    }

    /**
     * Obtiene bytes usados para enforcement de cuota.
     * Preferimos DB (ya guardan total_size_bytes por estudio) para no consultar por estudio.
     * Si no hay token/credenciales, igual funciona con DB.
     */
    private function getUsedBytesForQuotaEnforcement() {
        $apiToken = trim((string)($this->config['r2_cf_api_token_read'] ?? ''));
        $accountId = trim((string)($this->config['r2_account_id'] ?? ''));
        $bucket = trim((string)($this->config['r2_bucket_name'] ?? ''));

        // Si hay credenciales, usar Cloudflare API una vez cada cierto TTL (caché en /tmp) para mayor exactitud.
        if ($apiToken !== '' && $accountId !== '' && $bucket !== '') {
            try {
                $cacheTtlSeconds = 60;
                $cacheFile = sys_get_temp_dir() . '/r2_quota_used_bytes_' . md5($accountId . '|' . $bucket) . '.json';
                if (file_exists($cacheFile)) {
                    $age = time() - (int)@filemtime($cacheFile);
                    if ($age >= 0 && $age < $cacheTtlSeconds) {
                        $cached = json_decode((string)@file_get_contents($cacheFile), true);
                        if (is_array($cached) && isset($cached['bytes'])) {
                            return (int)$cached['bytes'];
                        }
                    }
                }

                $url = "https://api.cloudflare.com/client/v4/accounts/{$accountId}/r2/buckets/{$bucket}/usage";
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 12,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_HTTPHEADER => [
                        "Authorization: Bearer {$apiToken}",
                        'Content-Type: application/json',
                    ],
                ]);

                $raw = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($raw !== false && !$curlError && $httpCode === 200) {
                    $response = json_decode((string)$raw, true);
                    $apiOk = is_array($response) && !empty($response['success']);
                    if ($apiOk) {
                        $result = $response['result'] ?? [];
                        $payloadSize = (int)($result['payloadSize'] ?? 0);
                        $metadataSize = (int)($result['metadataSize'] ?? 0);
                        $bytes = $payloadSize + $metadataSize;
                        @file_put_contents($cacheFile, json_encode(['bytes' => $bytes, 'saved_at' => time()], JSON_UNESCAPED_UNICODE));
                        return (int)$bytes;
                    }
                }
            } catch (Exception $e) {
                // Fallback a DB
            }
        }

        // Fallback: suma local en DB (evita consultar "por estudio")
        $stmt = $this->db->query("SELECT COALESCE(SUM(total_size_bytes), 0) AS used_bytes FROM r2_studies WHERE r2_status = 'online'");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['used_bytes'] ?? 0);
    }

    /**
     * Enforcement determinista: usar solo BD (total_size_bytes guardado al subir).
     * Evita inconsistencias temporales del "usage" de Cloudflare que puede tardar en reflejar purges.
     */
    private function getUsedBytesFromDbForQuotaEnforcement() {
        $stmt = $this->db->query("SELECT COALESCE(SUM(total_size_bytes), 0) AS used_bytes FROM r2_studies WHERE r2_status = 'online'");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['used_bytes'] ?? 0);
    }

    private function recycleOldUnlockedStudiesToFree($neededFree, $limitBytes, $incomingBytes, $queueId = null) {
        $neededFree = (int)$neededFree;
        if ($neededFree <= 0) return;

        $batchSize = 10;
        $freed = 0;
        $safetyMaxDeletes = 200; // evita loops infinitos si hay datos inconsistentes
        $deleteCount = 0;

        while ($freed < $neededFree) {
            if ($deleteCount >= $safetyMaxDeletes) {
                throw new Exception('QUOTA_RECYCLE_LOOP_GUARD: demasiados deletes sin liberar suficiente espacio');
            }

            // Buscar candidatos: online y NO bloqueados, por antigüedad (más viejos primero)
            $stmt = $this->db->prepare("
                SELECT orthanc_study_id, study_instance_uid, total_size_bytes
                FROM r2_studies
                WHERE r2_status = 'online'
                  AND (is_locked = 0)
                ORDER BY uploaded_at ASC, created_at ASC
                LIMIT :limit
            ");
            $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
            $stmt->execute();
            $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($candidates)) {
                throw new Exception(
                    'QUOTA_BLOCKED_NO_UNLOCKED_CANDIDATES: freed=' . $freed .
                    ' neededFree=' . $neededFree .
                    ' limit_bytes=' . $limitBytes .
                    ' incoming_bytes=' . $incomingBytes
                );
            }

            foreach ($candidates as $cand) {
                $orthancStudyId = $cand['orthanc_study_id'] ?? null;
                $studyInstanceUid = $cand['study_instance_uid'] ?? null;
                $candSize = (int)($cand['total_size_bytes'] ?? 0);

                if (!$orthancStudyId || !$studyInstanceUid) continue;
                if ($candSize <= 0) $candSize = 1; // evita que quede en 0 y no progrese

                // Purga real en R2
                $this->purgeR2StudyByStudyInstanceUid($orthancStudyId, $studyInstanceUid);

                $freed += $candSize;
                $deleteCount++;

                // Re-check local: si ya liberamos suficiente, salir
                if ($freed >= $neededFree) break;
            }
        }
    }

    /**
     * Elimina un estudio completo de R2 por prefijo del StudyInstanceUID (rclone purge),
     * y sincroniza DB (r2_status/manifest/tamaños).
     */
    private function purgeR2StudyByStudyInstanceUid($orthancStudyId, $studyInstanceUid) {
        $config = $this->config;

        $bucketName = trim((string)($config['r2_bucket_name'] ?? ''));
        $accountId = trim((string)($config['r2_account_id'] ?? ''));
        $accessKey = (string)($config['r2_access_key'] ?? '');
        $secretKey = (string)($config['r2_secret_key'] ?? '');
        $region = (string)($config['r2_region'] ?? 'auto');
        $storagePrefix = rtrim((string)($config['r2_storage_prefix'] ?? 'studies/'), '/');

        if ($bucketName === '' || $accountId === '' || $accessKey === '' || $secretKey === '') {
            throw new Exception('QUOTA_RECYCLE_PURGE: configuración R2 incompleta (bucket/account/keys)');
        }

        // Prefijo exacto: <storagePrefix>/<studyInstanceUid>/
        $r2Dest = $storagePrefix . '/' . $studyInstanceUid . '/';
        $r2Remote = "r2:$bucketName/$r2Dest";

        // Crear config temporal para rclone
        $rcloneTempDir = sys_get_temp_dir();
        $rcloneConfigFile = $rcloneTempDir . '/rclone_r2_quota_delete_' . uniqid() . '.conf';
        $endpoint = 'https://' . $accountId . '.r2.cloudflarestorage.com';

        $rcloneConfigContent = "[r2]\n"
            . "type = s3\n"
            . "provider = Cloudflare\n"
            . "access_key_id = " . $accessKey . "\n"
            . "secret_access_key = " . $secretKey . "\n"
            . "endpoint = " . $endpoint . "\n"
            . "region = " . $region . "\n"
            . "no_check_bucket = true\n"
            . "env_auth = false\n";

        if (file_put_contents($rcloneConfigFile, $rcloneConfigContent) === false) {
            throw new Exception("QUOTA_RECYCLE_PURGE: no se pudo crear archivo rclone config: $rcloneConfigFile");
        }
        chmod($rcloneConfigFile, 0600);

        // Encontrar binario rclone
        $nativeBinary = __DIR__ . '/bin/rclone';
        $commonPaths = [
            $nativeBinary,
            '/usr/local/bin/rclone',
            '/usr/bin/rclone',
        ];
        $rclonePath = null;
        foreach ($commonPaths as $path) {
            if (file_exists($path) && is_executable($path)) {
                $rclonePath = $path;
                break;
            }
        }
        if (empty($rclonePath)) {
            $whichPath = trim(shell_exec('which rclone 2>/dev/null'));
            if (!empty($whichPath) && file_exists($whichPath) && strpos($whichPath, '/snap/') === false) {
                $rclonePath = $whichPath;
            }
        }
        if (empty($rclonePath)) {
            throw new Exception('QUOTA_RECYCLE_PURGE: rclone nativo no encontrado');
        }

        $rcloneLogFile = $rcloneTempDir . '/rclone_purge_quota_' . uniqid() . '.log';
        $env = [
            'HOME' => $rcloneTempDir,
            'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'TMPDIR' => $rcloneTempDir,
            'RCLONE_CONFIG' => $rcloneConfigFile,
        ];

        $cmd = sprintf(
            '%s purge %s --config %s --s3-no-check-bucket --log-level ERROR --stats 0',
            escapeshellarg($rclonePath),
            escapeshellarg($r2Remote),
            escapeshellarg($rcloneConfigFile)
        );

        $descriptorspec = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $rcloneLogFile, 'w'],
            2 => ['file', $rcloneLogFile, 'a'],
        ];

        $process = proc_open($cmd, $descriptorspec, $pipes, null, $env);
        if (!is_resource($process)) {
            @unlink($rcloneConfigFile);
            @unlink($rcloneLogFile);
            throw new Exception('QUOTA_RECYCLE_PURGE: no se pudo iniciar rclone purge');
        }

        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = (int)($status['exitcode'] ?? -1);
                break;
            }
            usleep(200000);
        }
        proc_close($process);

        @unlink($rcloneConfigFile);

        if (file_exists($rcloneLogFile)) {
            @unlink($rcloneLogFile);
        }

        if ($exitCode !== 0) {
            throw new Exception('QUOTA_RECYCLE_PURGE: rclone purge falló con exit code ' . $exitCode);
        }

        // Sincronizar DB: marcar como none + limpiar manifest/tamaños
        $update = $this->db->prepare("
            UPDATE r2_studies
            SET r2_status = 'none',
                r2_manifest_path = NULL,
                r2_manifest_url = NULL,
                total_instances = 0,
                total_size_bytes = 0,
                uploaded_at = NULL,
                updated_at = NOW(),
                is_locked = 0,
                lock_reason = NULL,
                locked_at = NULL
            WHERE orthanc_study_id = ?
        ");
        $update->execute([$orthancStudyId]);

        // Actualizar r2_queue.updated_at (traza mínima para UI)
        $touchQueue = $this->db->prepare("
            UPDATE r2_queue
            SET updated_at = NOW()
            WHERE orthanc_study_id = ?
        ");
        $touchQueue->execute([$orthancStudyId]);
    }
    
    /**
     * Procesa la cola de estudios pendientes
     * @param int|null $limit Límite de estudios a procesar (null = usar maxConcurrency)
     */
    public function processQueue($limit = null) {
        $limit = $limit ?? $this->maxConcurrency;
        
        // Verificar señal de detención antes de procesar
        $stopFile = __DIR__ . '/../workers/.worker_stop';
        if (file_exists($stopFile)) {
            error_log('[R2_QUEUE] Señal de detención detectada. No procesando cola.');
            return ['processed' => 0, 'message' => 'Worker detenido por señal'];
        }
        
        try {
            // 1. Obtener estudios pendientes o atascados en uploading
            // Nota: SKIP LOCKED requiere MySQL 8.0+, en MySQL 5.7 usamos una consulta simple
            // y confiamos en que el procesamiento sea rápido para evitar conflictos
            $stmt = $this->db->prepare("
                SELECT * FROM r2_queue 
                WHERE status = 'pending' 
                   OR (status = 'uploading' AND (
                       upload_started_at IS NULL 
                       OR upload_started_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                       OR upload_finished_at IS NULL
                   ))
                ORDER BY created_at ASC 
                LIMIT :limit
            ");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($pending)) {
                return ['processed' => 0, 'message' => 'No hay estudios pendientes'];
            }
            
            // 2. Procesar cada estudio
            $processed = 0;
            $errors = [];
            
            foreach ($pending as $item) {
                // Verificar señal de detención antes de procesar cada estudio
                if (file_exists($stopFile)) {
                    error_log('[R2_QUEUE] Señal de detención detectada durante procesamiento. Deteniendo.');
                    break;
                }
                
                try {
                    $this->processStudy($item);
                    $processed++;
                } catch (Exception $e) {
                    $errors[] = [
                        'study_id' => $item['orthanc_study_id'],
                        'error' => $e->getMessage()
                    ];
                    error_log('[R2_QUEUE] Error procesando estudio ' . $item['orthanc_study_id'] . ': ' . $e->getMessage());
                }
            }
            
            return [
                'processed' => $processed,
                'total' => count($pending),
                'errors' => $errors
            ];
            
        } catch (Exception $e) {
            error_log('[R2_QUEUE] Error en processQueue: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Verifica si un job fue cancelado
     * 
     * @param int $queueId ID del job
     * @return bool True si fue cancelado
     */
    private function isJobCancelled($queueId) {
        try {
            $stmt = $this->db->prepare("SELECT status FROM r2_queue WHERE id = ?");
            $stmt->execute([$queueId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return ($result && $result['status'] === 'cancelled');
        } catch (Exception $e) {
            error_log('[R2_QUEUE] Error verificando cancelación: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Guarda información del paciente/estudio en r2_queue
     */
    private function saveStudyPatientInfo($queueId, $orthancStudyId) {
        try {
            // getStudyDetails() devuelve un objeto flat (no la respuesta cruda de Orthanc)
            $studyDetails = $this->orthancClient->getStudyDetails($orthancStudyId);
            if (!$studyDetails) return;
            
            // Usar las claves del objeto flat que devuelve OrthancClient::getStudyDetails()
            $patientName      = $studyDetails['patient_name']         ?? null;
            $patientId        = $studyDetails['patient_id']           ?? null;
            $studyDate        = $studyDetails['study_date']           ?? null;
            $modality         = $studyDetails['modality']             ?? null;
            $description      = $studyDetails['study_description']    ?? null;
            $studyInstanceUid = $studyDetails['study_instance_uid']   ?? null;
            
            $stmt = $this->db->prepare("
                UPDATE r2_queue SET
                    patient_name       = :patient_name,
                    patient_id         = :patient_id,
                    study_date         = :study_date,
                    modality           = :modality,
                    study_description  = :study_description,
                    study_instance_uid = :study_instance_uid,
                    updated_at         = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':patient_name'       => $patientName,
                ':patient_id'         => $patientId,
                ':study_date'         => $studyDate,
                ':modality'           => $modality,
                ':study_description'  => $description,
                ':study_instance_uid' => $studyInstanceUid,
                ':id'                 => $queueId
            ]);
        } catch (Exception $e) {
            error_log("[R2_QUEUE] Aviso: no se pudo guardar info del paciente para queue_id=$queueId: " . $e->getMessage());
        }
    }
    
    /**
     * Procesa un estudio individual
     */
    private function processStudy($queueItem) {
        $orthancStudyId = $queueItem['orthanc_study_id'];
        $queueId = $queueItem['id'];
        $isResync = isset($queueItem['is_resync']) && (int) $queueItem['is_resync'] === 1;
        
        try {
            // 0. Verificar si el job fue cancelado antes de iniciar
            if ($this->isJobCancelled($queueId)) {
                error_log("[R2_QUEUE] Job $queueId fue cancelado. Saltando procesamiento.");
                return ['success' => false, 'cancelled' => true];
            }

            if ($isResync) {
                $this->setR2StudySyncStatus($orthancStudyId, 'syncing');
            }
            
            // 1. Marcar como uploading y registrar inicio (solo si no está ya en uploading)
            if ($queueItem['status'] !== 'uploading') {
                $this->updateQueueStatus($queueId, 'uploading', null, null, null, null, null, null, true);
            }
            
            // 1b. Guardar información del paciente (no bloquea si falla)
            $this->saveStudyPatientInfo($queueId, $orthancStudyId);
            
            // 2. Método de upload (re-sync con rclone: incremental ZIP + rclone; con instance: HeadObject por instancia)
            $uploadMethod = $this->config['r2_upload_method'] ?? 'instance';
            
            // 3. Subir estudio según método configurado
            if ($uploadMethod === 'zip') {
                $result = $this->uploadStudyToR2AsZip($orthancStudyId, $queueId);
            } elseif ($uploadMethod === 'pre-download') {
                $result = $this->uploadStudyToR2AsPreDownload($orthancStudyId, $queueId);
            } elseif ($uploadMethod === 'zip-extract-upload') {
                $result = $this->uploadStudyToR2AsZipExtractUpload($orthancStudyId, $queueId);
            } elseif ($uploadMethod === 'rclone') {
                $result = $this->uploadStudyToR2AsRclone($orthancStudyId, $queueId, $isResync);
            } else {
                $result = $this->uploadStudyToR2($orthancStudyId, $queueId, $isResync);
            }

            if ($uploadMethod === 'rclone') {
                $this->processRcloneDeferredFollowUps($queueId, $orthancStudyId);
            }
            
            // 4. Marcar como done SOLO para métodos 'instance' y 'pre-download'
            // Para ZIP, el status ya se setea a 'pending_extraction' dentro de uploadStudyToR2AsZip()
            // No sobreescribir 'pending_extraction' con 'done'
            if ($uploadMethod !== 'zip') {
                $stmt = $this->db->prepare("UPDATE r2_queue SET status = 'done', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$queueId]);
            }
            
            return $result;
            
        } catch (Exception $e) {
            if ($isResync) {
                $this->setR2StudySyncStatus($orthancStudyId, 'idle');
            }
            // Manejar error y reintentos
            $retryCount = (int)$queueItem['retry_count'] + 1;
            $errorMessage = $e->getMessage();
            
            // Si el error es de credenciales, marcar como error inmediatamente (no reintentar)
            $isCredentialError = stripos($errorMessage, 'credential') !== false || 
                                 stripos($errorMessage, 'access key') !== false ||
                                 stripos($errorMessage, 'InvalidArgument') !== false ||
                                 stripos($errorMessage, 'length') !== false;
            
            // Si el error es por cuota/bloqueo, no reintentar (no va a cambiar sin intervención)
            $isQuotaError = stripos($errorMessage, 'QUOTA_') !== false ||
                             stripos($errorMessage, 'CUOTA_') !== false ||
                             stripos($errorMessage, 'CUOTA') !== false;
            
            if ($isCredentialError || $isQuotaError || $retryCount >= $this->maxRetries) {
                // Error de credenciales o máximo de reintentos alcanzado
                $this->updateQueueStatus($queueId, 'error', $retryCount, $errorMessage);
                error_log("[R2_QUEUE] Estudio marcado como error (credenciales/cuota o max reintentos): {$queueItem['orthanc_study_id']}");
            } else {
                // Reintentar
                $this->updateQueueStatus($queueId, 'pending', $retryCount, $errorMessage);
            }
            
            throw $e;
        }
    }
    
    /**
     * Sube un estudio completo a R2 (modo por instancia).
     * Si $isResync: omite instancias ya presentes en R2 con el mismo tamaño (HeadObject).
     *
     * @param string $orthancStudyId ID del estudio en Orthanc
     * @param int|null $queueId ID de la cola para actualizar progreso
     * @param bool $isResync Job de sincronización incremental
     */
    private function uploadStudyToR2($orthancStudyId, $queueId = null, $isResync = false) {
        try {
            // 1. Construir manifest
            $manifest = $this->manifestBuilder->buildManifest($orthancStudyId);
            $studyInstanceUid = $manifest['studyInstanceUID'];
            
            // 2. Obtener instancias para subir
            $instances = $this->getInstancesForStudy($orthancStudyId);
            $totalInstancesInOrthanc = count($instances);

            $fullStudyTotalBytes = 0;
            foreach ($instances as $instRaw) {
                $fullStudyTotalBytes += isset($instRaw['FileSize']) && $instRaw['FileSize'] > 0
                    ? (int) $instRaw['FileSize']
                    : 500 * 1024;
            }
            
            // 3. Obtener IPs del servidor (local y WAN)
            $serverIp = $this->getServerIp();
            $serverIpWan = $this->getServerIpWan();
            
            // 4. Preparar datos de instancias; en re-sync solo las faltantes o con tamaño distinto en R2
            $instancesToUpload = [];
            $uploadBatchTotalBytes = 0;
            
            foreach ($instances as $instance) {
                $instanceId = $instance['ID'];
                $sopUid = $instance['MainDicomTags']['SOPInstanceUID'] ?? null;
                $seriesId = $instance['ParentSeries'] ?? null;
                
                if (!$sopUid || !$seriesId) {
                    continue;
                }
                
                $seriesInfo = $this->getSeriesInfo($seriesId);
                $seriesInstanceUid = $seriesInfo['MainDicomTags']['SeriesInstanceUID'] ?? null;
                
                if (!$seriesInstanceUid) {
                    continue;
                }
                
                $instanceSize = isset($instance['FileSize']) && $instance['FileSize'] > 0 
                    ? (int)$instance['FileSize'] 
                    : 500 * 1024;
                
                if ($isResync) {
                    $r2Key = $this->r2Driver->getInstanceKey($studyInstanceUid, $seriesInstanceUid, $sopUid);
                    $existingLen = $this->r2Driver->getObjectContentLengthIfExists($r2Key);
                    if ($existingLen !== null && $existingLen === $instanceSize) {
                        continue;
                    }
                }
                
                $uploadBatchTotalBytes += $instanceSize;
                
                $instancesToUpload[] = [
                    'instanceId' => $instanceId,
                    'sopInstanceUid' => $sopUid,
                    'studyInstanceUid' => $studyInstanceUid,
                    'seriesInstanceUid' => $seriesInstanceUid,
                    'size' => $instanceSize
                ];
            }

            $uploadBatchCount = count($instancesToUpload);
            
            // 5. Inicializar progreso en BD (batch actual; total instancias del estudio en columna para contexto)
            if ($queueId) {
                $this->updateQueueProgress($queueId, 0, max(1, $uploadBatchCount), 0, max(1, $uploadBatchTotalBytes));
            }

            // 5b. Enforcement de cuota sobre bytes que realmente se subirán en esta corrida
            $this->enforceQuotaBeforeUpload($orthancStudyId, $queueId, $uploadBatchTotalBytes);
            
            // 6. Subir instancias en paralelo usando HTTP/2 y curl_multi
            $totalSize = 0;
            $instancesUploaded = 0;
            $startTime = microtime(true);
            
            $lastUpdateTime = $startTime;
            $lastUpdateBytes = 0;
            $speedSamples = [];
            $speedMin = null;
            $speedMax = null;
            
            $self = $this;
            $progressTotalInstances = max(1, $uploadBatchCount);
            $progressTotalBytes = max(1, $uploadBatchTotalBytes);
            
            $progressCallback = function($uploaded, $total, $totalBytesUploaded) use ($queueId, &$speedSamples, &$speedMin, &$speedMax, &$lastUpdateTime, &$lastUpdateBytes, $startTime, $serverIp, $serverIpWan, $progressTotalInstances, $progressTotalBytes, $self) {
                $currentTime = microtime(true);
                $timeSinceLastUpdate = $currentTime - $lastUpdateTime;
                $bytesSinceLastUpdate = $totalBytesUploaded - $lastUpdateBytes;
                
                // Solo calcular velocidad si ha pasado al menos 1 segundo desde la última actualización
                // Esto evita divisiones por números muy pequeños que causan velocidades incorrectas
                if ($timeSinceLastUpdate >= 1.0 && $bytesSinceLastUpdate > 0) {
                    // Calcular velocidad instantánea en MB/s basada en bytes reales
                    $instantSpeed = ($bytesSinceLastUpdate / 1024 / 1024) / $timeSinceLastUpdate;
                    
                    // Validar que la velocidad sea razonable (menos de 1000 MB/s para evitar errores)
                    if ($instantSpeed > 0 && $instantSpeed < 1000) {
                        if ($speedMin === null || $instantSpeed < $speedMin) {
                            $speedMin = $instantSpeed;
                        }
                        if ($speedMax === null || $instantSpeed > $speedMax) {
                            $speedMax = $instantSpeed;
                        }
                        $speedSamples[] = $instantSpeed;
                    }
                    
                    $lastUpdateTime = $currentTime;
                    $lastUpdateBytes = $totalBytesUploaded;
                }
                
                // Calcular velocidad promedio total desde el inicio
                $elapsedTime = $currentTime - $startTime;
                $avgSpeed = ($elapsedTime > 0 && $totalBytesUploaded > 0) 
                    ? ($totalBytesUploaded / 1024 / 1024) / $elapsedTime 
                    : 0;
                
                // Velocidad actual: usar la última muestra válida o la promedio
                $currentSpeedMbps = count($speedSamples) > 0 ? end($speedSamples) : $avgSpeed;
                
                if ($queueId && ($uploaded % 5 == 0 || $uploaded == $total)) {
                    $self->updateQueueProgress(
                        $queueId,
                        $uploaded,
                        $progressTotalInstances,
                        $totalBytesUploaded,
                        $progressTotalBytes,
                        $currentSpeedMbps,
                        $speedMin ?? $avgSpeed,
                        $speedMax ?? $avgSpeed,
                        $avgSpeed,
                        $serverIp,
                        $serverIpWan
                    );
                }
            };
            
            if ($uploadBatchCount === 0) {
                error_log('[R2_QUEUE] Sin instancias nuevas que subir (re-sync al día o vacío): ' . $orthancStudyId);
            } else {
                $uploadResults = $this->r2Driver->uploadInstancesParallel(
                    $instancesToUpload,
                    $this->maxConcurrency,
                    $progressCallback
                );
                foreach ($uploadResults as $result) {
                    if ($result['success']) {
                        $totalSize += $result['size'];
                        $instancesUploaded++;
                    } else {
                        error_log('[R2_QUEUE] Error subiendo instancia: ' . ($result['error'] ?? 'Unknown error'));
                    }
                }
            }
            
            $endTime = microtime(true);
            $totalDuration = max(0.0001, $endTime - $startTime);
            $finalAvgSpeed = $totalSize > 0 ? ($totalSize / 1024 / 1024) / $totalDuration : 0;
            
            if ($queueId) {
                $this->updateQueueProgress(
                    $queueId,
                    $instancesUploaded,
                    $progressTotalInstances,
                    $totalSize,
                    $progressTotalBytes,
                    $finalAvgSpeed,
                    $speedMin ?? $finalAvgSpeed,
                    $speedMax ?? $finalAvgSpeed,
                    $finalAvgSpeed,
                    $serverIp,
                    $serverIpWan
                );
            }
            
            // 7. Finalizar: registrar tiempo total y velocidades finales
            if ($queueId) {
                $this->finalizeQueueProgress(
                    $queueId,
                    $totalDuration,
                    $speedMin ?? $finalAvgSpeed,
                    $speedMax ?? $finalAvgSpeed,
                    $finalAvgSpeed
                );
            }
            
            // 7. Subir manifest.json
            $manifestKey = $this->r2Driver->getManifestKey($studyInstanceUid);
            $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $this->r2Driver->putObject($manifestKey, $manifestJson, 'application/json');
            
            // 8. Actualizar r2_studies (totales del estudio en Orthanc; bytes estimados según FileSize)
            $this->updateR2Studies(
                $orthancStudyId,
                $studyInstanceUid,
                $manifestKey,
                $totalInstancesInOrthanc,
                $fullStudyTotalBytes,
                $this->r2StudiesHasColumn('r2_sync_status')
            );
            
            return [
                'success' => true,
                'study_instance_uid' => $studyInstanceUid,
                'total_instances' => $totalInstancesInOrthanc,
                'total_size' => $fullStudyTotalBytes,
                'instances_uploaded_this_run' => $instancesUploaded,
                'resync' => $isResync,
            ];
            
        } catch (Exception $e) {
            error_log('[R2_QUEUE] Error subiendo estudio a R2: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Obtiene instancias del estudio desde Orthanc
     */
    private function getInstancesForStudy($orthancStudyId) {
        $baseUrl = OrthancConfig::getServerUrl();
        $credentials = OrthancConfig::getCredentials();
        
        $ch = curl_init($baseUrl . '/studies/' . $orthancStudyId . '/instances');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $credentials['username'] . ':' . $credentials['password']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("Error obteniendo instancias: HTTP $httpCode");
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Obtiene información de una serie
     */
    private function getSeriesInfo($seriesId) {
        $baseUrl = OrthancConfig::getServerUrl();
        $credentials = OrthancConfig::getCredentials();
        
        $ch = curl_init($baseUrl . '/series/' . $seriesId);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $credentials['username'] . ':' . $credentials['password']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("Error obteniendo serie: HTTP $httpCode");
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Actualiza el estado de la cola
     */
    private function updateQueueStatus($queueId, $status, $retryCount = null, $lastError = null, 
                                       $instancesUploaded = null, $totalInstances = null, 
                                       $bytesUploaded = null, $totalBytes = null, $resetProgress = false,
                                       $uploadIp = null, $uploadIpWan = null) {
        $sql = "UPDATE r2_queue SET status = :status, updated_at = NOW()";
        $params = [':status' => $status];
        
        if ($retryCount !== null) {
            $sql .= ", retry_count = :retry_count";
            $params[':retry_count'] = $retryCount;
        }
        
        if ($lastError !== null) {
            $sql .= ", last_error = :last_error";
            $params[':last_error'] = $lastError;
        }
        
        if ($resetProgress) {
            $sql .= ", upload_started_at = NOW(), instances_uploaded = 0, bytes_uploaded = 0, total_instances = 0, total_bytes = 0, upload_finished_at = NULL, upload_duration_seconds = NULL, upload_speed_mbps = NULL, upload_speed_min_mbps = NULL, upload_speed_max_mbps = NULL, upload_speed_avg_mbps = NULL";
            error_log('[R2_QUEUE] Iniciando upload - queue_id: ' . $queueId . ', upload_started_at: ' . date('Y-m-d H:i:s'));
        }
        
        // Si se proporcionan valores específicos, usarlos en lugar de resetear
        if ($totalInstances !== null) {
            $sql .= ", total_instances = :total_instances";
            $params[':total_instances'] = $totalInstances;
        }
        
        if ($instancesUploaded !== null) {
            $sql .= ", instances_uploaded = :instances_uploaded";
            $params[':instances_uploaded'] = $instancesUploaded;
        }
        
        if ($totalBytes !== null) {
            $sql .= ", total_bytes = :total_bytes";
            $params[':total_bytes'] = $totalBytes;
        }
        
        if ($bytesUploaded !== null) {
            $sql .= ", bytes_uploaded = :bytes_uploaded";
            $params[':bytes_uploaded'] = $bytesUploaded;
        }
        
        if ($uploadIp !== null) {
            $sql .= ", upload_ip = :upload_ip";
            $params[':upload_ip'] = $uploadIp;
        }
        
        if ($uploadIpWan !== null) {
            $sql .= ", upload_ip_wan = :upload_ip_wan";
            $params[':upload_ip_wan'] = $uploadIpWan;
        }
        
        $sql .= " WHERE id = :id";
        $params[':id'] = $queueId;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }
    
    /**
     * Actualiza el progreso del upload en la cola
     */
    private function updateQueueProgress($queueId, $instancesUploaded, $totalInstances, 
                                        $bytesUploaded, $totalBytes, $speedMbps = null,
                                        $speedMin = null, $speedMax = null, $speedAvg = null,
                                        $uploadIp = null, $uploadIpWan = null) {
        $sql = "UPDATE r2_queue SET 
                    instances_uploaded = :instances_uploaded,
                    total_instances = :total_instances,
                    bytes_uploaded = :bytes_uploaded,
                    total_bytes = :total_bytes,
                    updated_at = NOW()";
        $params = [
            ':instances_uploaded' => $instancesUploaded,
            ':total_instances' => $totalInstances,
            ':bytes_uploaded' => $bytesUploaded,
            ':total_bytes' => $totalBytes,
            ':id' => $queueId
        ];
        
        if ($speedMbps !== null) {
            $sql .= ", upload_speed_mbps = :speed";
            $params[':speed'] = round($speedMbps, 2);
        }
        
        if ($speedMin !== null) {
            $sql .= ", upload_speed_min_mbps = :speed_min";
            $params[':speed_min'] = round($speedMin, 2);
        }
        
        if ($speedMax !== null) {
            $sql .= ", upload_speed_max_mbps = :speed_max";
            $params[':speed_max'] = round($speedMax, 2);
        }
        
        if ($speedAvg !== null) {
            $sql .= ", upload_speed_avg_mbps = :speed_avg";
            $params[':speed_avg'] = round($speedAvg, 2);
        }
        
        if ($uploadIp !== null) {
            $sql .= ", upload_ip = :upload_ip";
            $params[':upload_ip'] = $uploadIp;
        }
        
        if ($uploadIpWan !== null) {
            $sql .= ", upload_ip_wan = :upload_ip_wan";
            $params[':upload_ip_wan'] = $uploadIpWan;
        }
        
        $sql .= " WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }
    
    /**
     * Finaliza el progreso del upload (cuando termina)
     */
    private function finalizeQueueProgress($queueId, $durationSeconds, $speedMin, $speedMax, $speedAvg) {
        $sql = "UPDATE r2_queue SET 
                    upload_finished_at = NOW(),
                    upload_duration_seconds = :duration,
                    upload_speed_min_mbps = :speed_min,
                    upload_speed_max_mbps = :speed_max,
                    upload_speed_avg_mbps = :speed_avg,
                    updated_at = NOW()
                WHERE id = :id";
        
        $durationInt = (int)$durationSeconds;
        error_log('[R2_QUEUE] Finalizando upload - queue_id: ' . $queueId . ', duration: ' . $durationInt . 's, speeds: min=' . round($speedMin, 2) . ' max=' . round($speedMax, 2) . ' avg=' . round($speedAvg, 2));
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':duration' => $durationInt,
            ':speed_min' => round($speedMin, 2),
            ':speed_max' => round($speedMax, 2),
            ':speed_avg' => round($speedAvg, 2),
            ':id' => $queueId
        ]);
    }
    
    /**
     * Obtiene la IP local del servidor
     */
    private function getServerIp() {
        // Intentar obtener IP local
        $ip = null;
        
        // Método 1: IP de la interfaz de red principal
        if (function_exists('exec')) {
            $output = [];
            exec("hostname -I 2>/dev/null", $output);
            if (!empty($output[0])) {
                $ips = explode(' ', trim($output[0]));
                // Priorizar IPs privadas (192.168.x.x, 10.x.x.x, 172.16-31.x.x)
                foreach ($ips as $candidate) {
                    if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                        // Si es IP privada, usarla
                        if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                            $ip = $candidate;
                            break;
                        }
                    }
                }
                // Si no hay privada, usar la primera disponible
                if (!$ip && !empty($ips[0])) {
                    $ip = $ips[0];
                }
            }
        }
        
        // Método 2: IP desde $_SERVER
        if (!$ip && isset($_SERVER['SERVER_ADDR'])) {
            $ip = $_SERVER['SERVER_ADDR'];
        }
        
        // Método 3: IP desde conexión de red
        if (!$ip) {
            $ip = gethostbyname(gethostname());
            if ($ip === gethostname()) {
                $ip = '127.0.0.1'; // Fallback
            }
        }
        
        return $ip ?: 'N/A';
    }
    
    /**
     * Obtiene la IP pública (WAN) del servidor
     */
    private function getServerIpWan() {
        $ipWan = null;
        
        // Método 1: Consultar servicio externo (más confiable)
        $services = [
            'https://api.ipify.org',
            'https://icanhazip.com',
            'https://ifconfig.me/ip',
            'https://checkip.amazonaws.com'
        ];
        
        foreach ($services as $service) {
            try {
                $ch = curl_init($service);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 3);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($httpCode === 200 && $response) {
                    $ip = trim($response);
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        $ipWan = $ip;
                        break;
                    }
                }
            } catch (Exception $e) {
                // Continuar con el siguiente servicio
                continue;
            }
        }
        
        // Método 2: Intentar obtener desde hostname -I y filtrar IPs públicas
        if (!$ipWan && function_exists('exec')) {
            $output = [];
            exec("hostname -I 2>/dev/null", $output);
            if (!empty($output[0])) {
                $ips = explode(' ', trim($output[0]));
                foreach ($ips as $candidate) {
                    // Solo IPs públicas (no privadas)
                    if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        $ipWan = $candidate;
                        break;
                    }
                }
            }
        }
        
        // Método 3: Intentar desde $_SERVER si está disponible
        if (!$ipWan && isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($forwarded[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $ipWan = $ip;
            }
        }
        
        if (!$ipWan && isset($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $ipWan = $ip;
            }
        }
        
        return $ipWan ?: 'N/A';
    }
    
    /**
     * Actualiza o crea registro en r2_studies
     *
     * @param bool $clearSyncStatus Si true, pone r2_sync_status en idle (fin de re-sync)
     */
    private function updateR2Studies($orthancStudyId, $studyInstanceUid, $manifestPath, $totalInstances, $totalSize, $clearSyncStatus = false, $r2ZipKey = null) {
        $stmt = $this->db->prepare("SELECT id FROM r2_studies WHERE orthanc_study_id = ?");
        $stmt->execute([$orthancStudyId]);
        $exists = $stmt->fetch();
        
        $hasSyncCol = $this->r2StudiesHasColumn('r2_sync_status');
        $hasLastSynced = $this->r2StudiesHasColumn('last_synced_at');
        $syncSql = ($clearSyncStatus && $hasSyncCol) ? ', r2_sync_status = \'idle\'' : '';
        $lastSql = $hasLastSynced ? ', last_synced_at = NOW()' : '';
        
        if ($exists) {
            $stmt = $this->db->prepare("
                UPDATE r2_studies 
                SET r2_status = 'online',
                    r2_manifest_path = ?,
                    total_instances = ?,
                    total_size_bytes = ?,
                    uploaded_at = NOW(),
                    updated_at = NOW()
                    {$syncSql}
                    {$lastSql}
                WHERE orthanc_study_id = ?
            ");
            $stmt->execute([$manifestPath, $totalInstances, $totalSize, $orthancStudyId]);
        } else {
            if ($hasSyncCol && $hasLastSynced) {
                $stmt = $this->db->prepare("
                    INSERT INTO r2_studies 
                    (orthanc_study_id, study_instance_uid, r2_status, r2_sync_status, r2_manifest_path, 
                     total_instances, total_size_bytes, uploaded_at, last_synced_at, created_at, updated_at)
                    VALUES (?, ?, 'online', 'idle', ?, ?, ?, NOW(), NOW(), NOW(), NOW())
                ");
            } elseif ($hasSyncCol) {
                $stmt = $this->db->prepare("
                    INSERT INTO r2_studies 
                    (orthanc_study_id, study_instance_uid, r2_status, r2_sync_status, r2_manifest_path, 
                     total_instances, total_size_bytes, uploaded_at, created_at, updated_at)
                    VALUES (?, ?, 'online', 'idle', ?, ?, ?, NOW(), NOW(), NOW())
                ");
            } elseif ($hasLastSynced) {
                $stmt = $this->db->prepare("
                    INSERT INTO r2_studies 
                    (orthanc_study_id, study_instance_uid, r2_status, r2_manifest_path, 
                     total_instances, total_size_bytes, uploaded_at, last_synced_at, created_at, updated_at)
                    VALUES (?, ?, 'online', ?, ?, ?, NOW(), NOW(), NOW(), NOW())
                ");
            } else {
                $stmt = $this->db->prepare("
                    INSERT INTO r2_studies 
                    (orthanc_study_id, study_instance_uid, r2_status, r2_manifest_path, 
                     total_instances, total_size_bytes, uploaded_at, created_at, updated_at)
                    VALUES (?, ?, 'online', ?, ?, ?, NOW(), NOW(), NOW())
                ");
            }
            $stmt->execute([$orthancStudyId, $studyInstanceUid, $manifestPath, $totalInstances, $totalSize]);
        }

        // Objeto ZIP en R2 (subida modo zip): sin manifest.json suelto en el bucket
        if ($this->r2StudiesHasColumn('r2_zip_key')) {
            if ($r2ZipKey !== null && $r2ZipKey !== '') {
                $ux = $this->db->prepare('UPDATE r2_studies SET r2_zip_key = ?, r2_manifest_path = NULL WHERE orthanc_study_id = ?');
                $ux->execute([$r2ZipKey, $orthancStudyId]);
            } else {
                $ux = $this->db->prepare('UPDATE r2_studies SET r2_zip_key = NULL WHERE orthanc_study_id = ?');
                $ux->execute([$orthancStudyId]);
            }
        }
    }
    
    /**
     * Obtiene la ruta de la carpeta temp para ZIPs
     * Crea la carpeta si no existe
     */
    private function getZipTempDir() {
        $tempDir = $this->config['r2_zip_temp_dir'] ?? __DIR__ . '/../temp/zips';
        
        // Crear carpeta si no existe
        if (!is_dir($tempDir)) {
            if (!mkdir($tempDir, 0755, true)) {
                throw new Exception("No se pudo crear carpeta temp para ZIPs: $tempDir");
            }
        }
        
        return $tempDir;
    }
    
    /**
     * Obtiene o crea el directorio temporal para instancias pre-descargadas
     * 
     * @param string $studyInstanceUid StudyInstanceUID del estudio
     * @return string Ruta del directorio temporal
     */
    private function getInstancesTempDir($studyInstanceUid) {
        $baseDir = __DIR__ . '/../temp/instances';
        
        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0755, true);
        }
        
        $studyDir = $baseDir . '/' . $studyInstanceUid;
        if (!is_dir($studyDir)) {
            mkdir($studyDir, 0755, true);
        }
        
        return $studyDir;
    }
    
    /**
     * Limpia el directorio temporal de instancias para un estudio
     * 
     * @param string $tempDir Directorio temporal a limpiar
     */
    private function cleanupInstancesTempDir($tempDir) {
        if (is_dir($tempDir)) {
            // Eliminar todos los archivos y subdirectorios
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            
            foreach ($files as $fileinfo) {
                $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                @$todo($fileinfo->getRealPath());
            }
            
            @rmdir($tempDir);
        }
    }
    
    /**
     * Limpia archivos ZIP antiguos de la carpeta temp
     * Elimina archivos más antiguos de 1 hora
     */
    private function cleanupOldZipFiles() {
        try {
            $tempDir = $this->getZipTempDir();
            $maxAge = 3600; // 1 hora en segundos
            
            $files = glob($tempDir . '/*.zip');
            if (!$files) {
                return;
            }
            
            $cleaned = 0;
            foreach ($files as $file) {
                if (is_file($file) && (time() - filemtime($file)) > $maxAge) {
                    if (unlink($file)) {
                        $cleaned++;
                    }
                }
            }
            
            if ($cleaned > 0) {
                error_log("[R2_QUEUE_PROCESSOR] Limpiados $cleaned archivos ZIP antiguos de temp");
            }
        } catch (Exception $e) {
            error_log("[R2_QUEUE_PROCESSOR] Error limpiando ZIPs antiguos: " . $e->getMessage());
        }
    }
    
    /**
     * Reorganiza el ZIP de Orthanc para que tenga la estructura correcta para R2
     * Estructura Orthanc: series/{SeriesUID}/{SOP}.dcm
     * Estructura R2: studies/{StudyUID}/series/{SeriesUID}/{SOP}.dcm
     */
    private function reorganizeZipForR2($zipPath, $studyInstanceUid) {
        $tempZipPath = sys_get_temp_dir() . '/r2_reorganized_' . time() . '_' . basename($zipPath);
        $zip = new ZipArchive();
        
        // Abrir ZIP original
        if ($zip->open($zipPath) !== TRUE) {
            throw new Exception("No se pudo abrir ZIP: $zipPath");
        }
        
        // Crear nuevo ZIP con estructura correcta
        $newZip = new ZipArchive();
        if ($newZip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            $zip->close();
            throw new Exception("No se pudo crear ZIP reorganizado: $tempZipPath");
        }
        
        // Recorrer todos los archivos del ZIP original
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            
            if ($entryName === false) {
                continue;
            }
            
            // Saltar directorios
            if (substr($entryName, -1) === '/') {
                continue;
            }
            
            // Leer contenido del archivo
            $fileContent = $zip->getFromIndex($i);
            if ($fileContent === false) {
                continue;
            }
            
            // Reorganizar ruta
            // De: series/{SeriesUID}/{SOP}.dcm
            // A: studies/{StudyUID}/series/{SeriesUID}/{SOP}.dcm
            if (preg_match('#^series/([^/]+)/(.+)$#', $entryName, $matches)) {
                $seriesUid = $matches[1];
                $fileName = $matches[2];
                $newPath = "studies/{$studyInstanceUid}/series/{$seriesUid}/{$fileName}";
                $newZip->addFromString($newPath, $fileContent);
            } else {
                // Mantener otros archivos (por si acaso)
                $newZip->addFromString($entryName, $fileContent);
            }
        }
        
        $zip->close();
        $newZip->close();
        
        // Reemplazar ZIP original
        if (!unlink($zipPath)) {
            unlink($tempZipPath);
            throw new Exception("No se pudo eliminar ZIP original: $zipPath");
        }
        if (!rename($tempZipPath, $zipPath)) {
            throw new Exception("No se pudo renombrar ZIP reorganizado: $tempZipPath");
        }
        
        return $zipPath;
    }
    
    /**
     * Agrega manifest.json al ZIP reorganizado
     */
    private function addManifestToZip($zipPath, $manifest) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) !== TRUE) {
            throw new Exception("No se pudo abrir ZIP para agregar manifest: $zipPath");
        }
        
        $studyInstanceUid = $manifest['studyInstanceUID'];
        $manifestPath = "studies/{$studyInstanceUid}/manifest.json";
        
        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $zip->addFromString($manifestPath, $manifestJson);
        
        $zip->close();
    }
    
    /**
     * Sube un estudio a R2 usando método ZIP
     * @param string $orthancStudyId ID del estudio en Orthanc
     * @param int $queueId ID de la cola para actualizar progreso
     */
    private function uploadStudyToR2AsZip($orthancStudyId, $queueId = null) {
        try {
            // ETAPA 1: Preparando estudio
            if ($queueId) {
                $this->updateQueueStatus($queueId, 'preparando_estudio', null, null, null, null, null, null, false);
                $stmt = $this->db->prepare("UPDATE r2_queue SET upload_method = 'zip' WHERE id = ?");
                $stmt->execute([$queueId]);
            }
            
            // 1. Limpiar archivos ZIP antiguos
            $this->cleanupOldZipFiles();
            
            // ETAPA 2: Generando manifest
            if ($queueId) {
                $this->updateQueueStatus($queueId, 'generando_manifest', null, null, null, null, null, null, false);
            }
            
            // 2. Construir manifest
            $manifest = $this->manifestBuilder->buildManifest($orthancStudyId);
            $studyInstanceUid = $manifest['studyInstanceUID'];
            
            // ETAPA 3: Generando ZIP
            if ($queueId) {
                $this->updateQueueStatus($queueId, 'generando_zip', null, null, null, null, null, null, false);
            }
            
            // 3. Obtener carpeta temp
            $tempDir = $this->getZipTempDir();
            $tempZipPath = $tempDir . '/r2_zip_' . $orthancStudyId . '_' . time() . '.zip';
            
            // 4. Descargar ZIP de Orthanc con callback de progreso
            require_once __DIR__ . '/../../api/config/orthanc_config.php';
            $orthancUrl = OrthancConfig::getServerUrl();
            $credentials = OrthancConfig::getCredentials();
            $zipUrl = $orthancUrl . '/studies/' . $orthancStudyId . '/archive';
            
            // Variables para rastrear progreso de descarga
            $lastUpdateTime = microtime(true);
            $lastUpdateSize = 0;
            $self = $this; // Capturar $this para usar en la closure
            
            // Callback de progreso para actualizar tamaño parcial del ZIP
            // Nota: cURL progress callback debe retornar 0 para continuar
            $progressCallback = function($resource, $downloadSize, $downloaded, $uploadSize, $uploaded) use ($queueId, &$lastUpdateTime, &$lastUpdateSize, $tempZipPath, $self) {
                // Actualizar cada segundo aproximadamente
                $currentTime = microtime(true);
                if ($currentTime - $lastUpdateTime >= 1.0) {
                    // Obtener tamaño actual del archivo
                    $currentSize = file_exists($tempZipPath) ? filesize($tempZipPath) : 0;
                    
                    if ($currentSize > $lastUpdateSize && $queueId) {
                        // Actualizar bytes_uploaded con el tamaño parcial del ZIP
                        // Nota: usamos bytes_uploaded para mostrar progreso aunque aún no se haya subido
                        $self->updateQueueProgress(
                            $queueId,
                            null, // instances_uploaded (no aplica durante generación)
                            null, // total_instances (se establecerá después)
                            $currentSize, // bytes_uploaded (tamaño parcial del ZIP)
                            $downloadSize > 0 ? $downloadSize : null, // total_bytes (tamaño total si está disponible)
                            null, null, null, null, null, null // sin velocidades aún
                        );
                        
                        $lastUpdateSize = $currentSize;
                        $lastUpdateTime = $currentTime;
                    }
                }
                return 0; // Retornar 0 para continuar la descarga
            };
            
            $ch = curl_init($zipUrl);
            $fp = fopen($tempZipPath, 'w');
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $credentials['username'] . ':' . $credentials['password']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 600);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, $progressCallback);
            
            $curlSuccess = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($fp);
            
            if (!$curlSuccess || $httpCode !== 200) {
                if (file_exists($tempZipPath)) {
                    unlink($tempZipPath);
                }
                throw new Exception("Error descargando ZIP desde Orthanc: HTTP $httpCode");
            }
            
            // 5. Reorganizar estructura del ZIP
            $this->reorganizeZipForR2($tempZipPath, $studyInstanceUid);
            
            // ETAPA 4: Guardando manifest en ZIP
            if ($queueId) {
                $this->updateQueueStatus($queueId, 'guardando_manifest_en_zip', null, null, null, null, null, null, false);
            }
            
            // 6. Agregar manifest.json al ZIP
            $this->addManifestToZip($tempZipPath, $manifest);
            
            // 7. Obtener tamaño del ZIP final
            $zipSize = filesize($tempZipPath);
            
            // Enforcement de cuota antes de subir a R2 (ZIP + manifest)
            $this->enforceQuotaBeforeUpload($orthancStudyId, $queueId, (int)$zipSize);
            
            // 8. Calcular total de instancias para el progreso
            $totalInstances = 0;
            if (isset($manifest['series']) && is_array($manifest['series'])) {
                foreach ($manifest['series'] as $series) {
                    if (isset($series['instances']) && is_array($series['instances'])) {
                        $totalInstances += count($series['instances']);
                    }
                }
            }
            
            // 9. Inicializar progreso con el tamaño total del ZIP y capturar IPs
            $localIp = $this->getServerIp();
            $wanIp = $this->getServerIpWan();
            
            // ETAPA 5: Subiendo (inicializar progreso y mostrar velocidad)
            if ($queueId) {
                // Inicializar progreso con tamaño total del ZIP
                $this->updateQueueStatus(
                    $queueId, 
                    'subiendo', // Estado: subiendo (aquí se mostrará velocidad)
                    0, // retry_count
                    null, // last_error
                    0, // instances_uploaded (inicial)
                    $totalInstances, // total_instances
                    0, // bytes_uploaded inicial
                    $zipSize, // total_bytes (tamaño del ZIP)
                    false, // resetProgress (no resetear, establecer valores específicos)
                    $localIp, // upload_ip
                    $wanIp // upload_ip_wan
                );
            }
            
            // 10. Subir ZIP a R2 (medir tiempo de upload)
            $studyPrefix = $this->r2Driver->getStudyPrefix($studyInstanceUid);
            if (!is_string($studyPrefix)) {
                error_log("[R2_QUEUE_PROCESSOR] ERROR: getStudyPrefix devolvió " . gettype($studyPrefix) . " en lugar de string. Valor: " . var_export($studyPrefix, true));
                throw new Exception("Error: getStudyPrefix debe devolver string, recibido: " . gettype($studyPrefix));
            }
            $zipKey = $studyPrefix . 'study.zip';
            
            error_log("[R2_QUEUE_PROCESSOR] Preparando upload ZIP: Key=$zipKey, Path=$tempZipPath, Size=$zipSize bytes");
            
            // ETAPA 6: Enviando a R2
            if ($queueId) {
                $this->updateQueueStatus($queueId, 'enviando_a_r2', null, null, null, null, null, null, false);
            }
            
            // Variables para rastrear velocidades durante el upload
            $uploadStartTime = microtime(true);
            $finalSpeedMin = null;
            $finalSpeedMax = null;
            $finalSpeedAvg = null;
            $finalSpeedCurrent = null;
            
            // Callback para actualizar progreso durante el upload
            $self = $this; // Capturar $this para usar en la closure
            $progressCallback = function($bytesUploaded, $totalBytes, $currentSpeed, $speedMin, $speedMax, $speedAvg) use ($queueId, &$finalSpeedMin, &$finalSpeedMax, &$finalSpeedAvg, &$finalSpeedCurrent, $totalInstances, $self) {
                // Guardar velocidades para uso final
                $finalSpeedCurrent = $currentSpeed;
                $finalSpeedMin = $speedMin;
                $finalSpeedMax = $speedMax;
                $finalSpeedAvg = $speedAvg;
                
                // Actualizar progreso en tiempo real
                if ($queueId) {
                    $self->updateQueueProgress(
                        $queueId,
                        $totalInstances, // instances_uploaded (todas las instancias están en el ZIP)
                        $totalInstances, // total_instances
                        $bytesUploaded,  // bytes_uploaded (progreso del ZIP)
                        $totalBytes,     // total_bytes (tamaño del ZIP)
                        $currentSpeed,   // velocidad actual
                        $speedMin,       // velocidad mínima
                        $speedMax,       // velocidad máxima
                        $speedAvg,       // velocidad promedio
                        null,            // upload_ip (ya establecido)
                        null             // upload_ip_wan (ya establecido)
                    );
                }
            };
            
            // Subir ZIP con callback de progreso
            $this->r2Driver->putObjectFromFile($zipKey, $tempZipPath, 'application/zip', $progressCallback);
            $uploadEndTime = microtime(true);
            $uploadDuration = $uploadEndTime - $uploadStartTime;
            
            // ETAPA 7: Guardado en R2
            if ($queueId) {
                $this->updateQueueStatus($queueId, 'guardado_en_r2', null, null, null, null, null, null, false);
            }
            
            // 11. ELIMINAR ZIP LOCAL después de subir exitosamente
            if (file_exists($tempZipPath)) {
                if (unlink($tempZipPath)) {
                    error_log("[R2_QUEUE_PROCESSOR] ZIP eliminado localmente después de upload: $tempZipPath");
                } else {
                    error_log("[R2_QUEUE_PROCESSOR] WARNING: No se pudo eliminar ZIP local: $tempZipPath");
                }
            }
            
            // 12. Calcular velocidad final de upload (MB/s) como respaldo
            $uploadSpeedMbps = $uploadDuration > 0 ? ($zipSize / 1024 / 1024) / $uploadDuration : 0;
            
            // Usar velocidades del callback si están disponibles, sino usar la velocidad promedio total
            if ($finalSpeedMin === null) $finalSpeedMin = $uploadSpeedMbps;
            if ($finalSpeedMax === null) $finalSpeedMax = $uploadSpeedMbps;
            if ($finalSpeedAvg === null) $finalSpeedAvg = $uploadSpeedMbps;
            if ($finalSpeedCurrent === null) $finalSpeedCurrent = $uploadSpeedMbps;
            
            // 13. Actualizar progreso final con velocidad y duración
            if ($queueId) {
                // Actualizar bytes_uploaded al total (100% completado)
                $this->updateQueueProgress(
                    $queueId,
                    $totalInstances, // instances_uploaded
                    $totalInstances, // total_instances
                    $zipSize, // bytes_uploaded
                    $zipSize, // total_bytes
                    $finalSpeedCurrent, // velocidad actual
                    $finalSpeedMin,     // velocidad mínima
                    $finalSpeedMax,     // velocidad máxima
                    $finalSpeedAvg      // velocidad promedio
                );
                
                // Finalizar con estadísticas
                $this->finalizeQueueProgress(
                    $queueId,
                    $uploadDuration,
                    $finalSpeedMin, // min
                    $finalSpeedMax, // max
                    $finalSpeedAvg  // avg
                );
            }
            
            // 13. Marcar como completado (ZIP subido a R2)
            // NOTA: La extracción del ZIP en R2 se manejará externamente si es necesario
            // Por ahora, marcamos como 'done' ya que el ZIP está en R2
            if ($queueId) {
                $stmt = $this->db->prepare("
                    UPDATE r2_queue 
                    SET status = 'done', 
                        zip_path = ?,
                        upload_method = 'zip',
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$zipKey, $queueId]);
            }
            
            // 14. Actualizar r2_studies
            $manifestKey = $this->r2Driver->getManifestKey($studyInstanceUid);
            $totalInstances = 0;
            if (isset($manifest['series']) && is_array($manifest['series'])) {
                foreach ($manifest['series'] as $series) {
                    if (isset($series['instances']) && is_array($series['instances'])) {
                        $totalInstances += count($series['instances']);
                    }
                }
            }
            
            $this->updateR2Studies($orthancStudyId, $studyInstanceUid, null, $totalInstances, $zipSize, false, $zipKey);
            
            return [
                'success' => true,
                'zip_key' => $zipKey,
                'size' => $zipSize,
                'method' => 'zip'
            ];
            
        } catch (Exception $e) {
            // Limpiar ZIP en caso de error
            if (isset($tempZipPath) && file_exists($tempZipPath)) {
                if (unlink($tempZipPath)) {
                    error_log("[R2_QUEUE_PROCESSOR] ZIP eliminado después de error: $tempZipPath");
                } else {
                    error_log("[R2_QUEUE_PROCESSOR] WARNING: No se pudo eliminar ZIP después de error: $tempZipPath");
                }
            }
            
            error_log('[R2_QUEUE_PROCESSOR][ZIP_UPLOAD] Error: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Sube un estudio a R2 usando el método Pre-Download:
     * 1. Pre-descarga todas las instancias a disco
     * 2. Organiza con estructura correcta
     * 3. Sube desde disco a R2 (paralelo)
     * 4. Limpia directorio temporal
     * 
     * @param string $orthancStudyId ID del estudio en Orthanc
     * @param int $queueId ID de la cola para actualizar progreso
     * @return array Resultado del upload
     */
    private function uploadStudyToR2AsPreDownload($orthancStudyId, $queueId = null) {
        $tempDir = null;
        
        try {
            // ETAPA 1: Preparando estudio
            if ($queueId) {
                $this->updateQueueStatus($queueId, 'preparando_estudio', null, null, null, null, null, null, false);
                $stmt = $this->db->prepare("UPDATE r2_queue SET upload_method = 'pre-download' WHERE id = ?");
                $stmt->execute([$queueId]);
            }
            
            // 1. Construir manifest
            $manifest = $this->manifestBuilder->buildManifest($orthancStudyId);
            $studyInstanceUid = $manifest['studyInstanceUID'];
            
            // 2. Obtener directorio temporal
            $tempDir = $this->getInstancesTempDir($studyInstanceUid);
            
            // ETAPA 2: Descargando instancias a disco
            if ($queueId) {
                $this->updateQueueStatus($queueId, 'descargando_instancias', null, null, null, null, null, null, false);
            }
            
            // 3. Pre-descargar todas las instancias a disco
            $instancesToUpload = $this->downloadAllInstancesToDisk(
                $orthancStudyId,
                $tempDir,
                $studyInstanceUid,
                $queueId
            );
            
            $totalInstances = count($instancesToUpload);
            $totalRealSize = array_sum(array_column($instancesToUpload, 'size'));
            
            // 4. Inicializar progreso en BD
            if ($queueId) {
                $this->updateQueueProgress($queueId, 0, $totalInstances, 0, $totalRealSize);
            }

            // Enforcement de cuota antes de comenzar el upload desde disco
            $this->enforceQuotaBeforeUpload($orthancStudyId, $queueId, (int)$totalRealSize);
            
            // ETAPA 3: Subiendo desde disco a R2
            if ($queueId) {
                $this->updateQueueStatus($queueId, 'subiendo', null, null, null, null, null, null, false);
            }
            
            // 5. Obtener IPs del servidor
            $serverIp = $this->getServerIp();
            $serverIpWan = $this->getServerIpWan();
            
            // 6. Marcar inicio del upload
            $uploadStartTime = microtime(true);
            
            // 7. Subir instancias desde disco a R2 (paralelo usando procesos PHP)
            $uploadResults = $this->uploadInstancesFromDiskParallel(
                $instancesToUpload,
                $tempDir,
                $studyInstanceUid,
                $queueId,
                $serverIp,
                $serverIpWan
            );
            
            // 8. Procesar resultados y calcular velocidades finales
            $instancesUploaded = 0;
            $totalSize = 0;
            $uploadEndTime = microtime(true);
            $uploadDuration = $uploadEndTime - $uploadStartTime;
            
            foreach ($uploadResults as $result) {
                if ($result['success']) {
                    $instancesUploaded++;
                    $totalSize += $result['size'];
                } else {
                    error_log('[R2_QUEUE] Error subiendo instancia desde disco: ' . ($result['error'] ?? 'Unknown error'));
                }
            }
            
            // 9. Calcular velocidades finales
            $finalAvgSpeed = $uploadDuration > 0 ? ($totalSize / 1024 / 1024) / $uploadDuration : 0;
            
            // 10. Finalizar progreso con velocidades y tiempos finales
            if ($queueId) {
                // Obtener velocidades mín/max/avg del último updateQueueProgress
                // Si no hay datos, usar la velocidad promedio calculada
                $stmt = $this->db->prepare("
                    SELECT 
                        upload_speed_min_mbps,
                        upload_speed_max_mbps,
                        upload_speed_avg_mbps
                    FROM r2_queue 
                    WHERE id = ?
                ");
                $stmt->execute([$queueId]);
                $speedData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $finalSpeedMin = $speedData['upload_speed_min_mbps'] ?? $finalAvgSpeed;
                $finalSpeedMax = $speedData['upload_speed_max_mbps'] ?? $finalAvgSpeed;
                $finalSpeedAvg = $speedData['upload_speed_avg_mbps'] ?? $finalAvgSpeed;
                
                // Actualizar progreso final con total_bytes correcto
                $this->updateQueueProgress(
                    $queueId,
                    $instancesUploaded,
                    $totalInstances,
                    $totalSize,
                    $totalSize, // total_bytes = totalSize (ya subido)
                    $finalAvgSpeed,
                    $finalSpeedMin,
                    $finalSpeedMax,
                    $finalSpeedAvg,
                    $serverIp,
                    $serverIpWan
                );
                
                // Finalizar con estadísticas
                $this->finalizeQueueProgress(
                    $queueId,
                    $uploadDuration,
                    $finalSpeedMin,
                    $finalSpeedMax,
                    $finalSpeedAvg
                );
            }
            
            // 11. Limpiar directorio temporal
            $this->cleanupInstancesTempDir($tempDir);
            
            // 12. Guardar manifest en R2
            $manifestKey = $this->r2Driver->getManifestKey($studyInstanceUid);
            $this->r2Driver->putObject($manifestKey, json_encode($manifest, JSON_PRETTY_PRINT));
            
            // 13. Actualizar r2_studies
            $this->updateR2Studies($orthancStudyId, $studyInstanceUid, $manifestKey, $totalInstances, $totalSize);
            
            return [
                'success' => $instancesUploaded === $totalInstances,
                'study_instance_uid' => $studyInstanceUid,
                'manifest_path' => $manifestKey,
                'total_instances' => $totalInstances,
                'total_size' => $totalSize
            ];
            
        } catch (Exception $e) {
            // Limpiar en caso de error
            if ($tempDir) {
                $this->cleanupInstancesTempDir($tempDir);
            }
            
            error_log('[R2_QUEUE] Error en uploadStudyToR2AsPreDownload: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Sube un estudio a R2 usando el método ZIP-Extract-Upload:
     * 1. Descarga el ZIP directamente desde Orthanc (/studies/{id}/archive)
     * 2. Extrae el ZIP localmente
     * 3. Sube archivos extraídos usando workers PHP persistentes (reutilizan conexiones)
     * 4. Limpia archivos temporales
     * 
     * @param string $orthancStudyId ID del estudio en Orthanc
     * @param int $queueId ID de la cola para actualizar progreso
     * @return array Resultado del upload
     */
    private function uploadStudyToR2AsZipExtractUpload($orthancStudyId, $queueId = null) {
        $zipPath = null;
        $extractDir = null;
        
        try {
            // ETAPA 1: Preparando estudio
            if ($queueId) {
                $this->updateQueueStatus($queueId, 'preparando_estudio', null, null, null, null, null, null, false);
                $stmt = $this->db->prepare("UPDATE r2_queue SET upload_method = 'zip-extract-upload' WHERE id = ?");
                $stmt->execute([$queueId]);
            }
            
            // 1. Construir manifest para obtener StudyInstanceUID
            $manifest = $this->manifestBuilder->buildManifest($orthancStudyId);
            $studyInstanceUid = $manifest['studyInstanceUID'];
            
            // 2. Obtener directorio temporal
            $tempBaseDir = $this->config['r2_instances_temp_dir'] ?? __DIR__ . '/../temp/instances';
            if (!is_dir($tempBaseDir)) {
                if (!mkdir($tempBaseDir, 0755, true)) {
                    throw new Exception("No se pudo crear directorio temporal: $tempBaseDir");
                }
            }
            
            // Verificar permisos de escritura
            if (!is_writable($tempBaseDir)) {
                throw new Exception("Directorio temporal no tiene permisos de escritura: $tempBaseDir");
            }
            
            $extractDir = $tempBaseDir . '/extracted_' . $studyInstanceUid . '_' . uniqid();
            if (!mkdir($extractDir, 0755, true)) {
                throw new Exception("No se pudo crear directorio de extracción: $extractDir");
            }
            
            if (!is_writable($extractDir)) {
                throw new Exception("Directorio de extracción no tiene permisos de escritura: $extractDir");
            }
            
            error_log("[R2_QUEUE][ZIP_EXTRACT] Directorio de extracción creado: $extractDir (permisos OK)");
            
            // ETAPA 2: Descargando ZIP desde Orthanc
            if ($queueId) {
                $this->updateQueueStatus($queueId, 'descargando_instancias', null, null, null, null, null, null, false);
            }
            
            // 3. Descargar ZIP desde Orthanc
            $zipPath = $extractDir . '/study_archive.zip';
            $zipSize = $this->downloadStudyZipFromOrthanc($orthancStudyId, $zipPath, $queueId);
            
            // ETAPA 3: Extrayendo ZIP
            if ($queueId) {
                $this->updateQueueStatus($queueId, 'generando_zip', null, null, null, null, null, null, false);
            }
            
            // 4. Extraer ZIP localmente
            error_log("[R2_QUEUE][ZIP_EXTRACT] Extrayendo ZIP: $zipPath → $extractDir");
            $extractedFiles = $this->extractStudyZip($zipPath, $extractDir);
            $totalInstances = count($extractedFiles);
            
            error_log("[R2_QUEUE][ZIP_EXTRACT] ZIP extraído: $totalInstances archivos encontrados en $extractDir");
            
            if ($totalInstances === 0) {
                throw new Exception("No se encontraron archivos DICOM en el ZIP extraído. Verificar contenido del ZIP: $zipPath");
            }
            
            // Verificar que los archivos extraídos existen y son legibles
            $missingFiles = [];
            foreach ($extractedFiles as $filePath) {
                if (!file_exists($filePath)) {
                    $missingFiles[] = $filePath;
                } elseif (!is_readable($filePath)) {
                    error_log("[R2_QUEUE][ZIP_EXTRACT] ⚠️ Archivo no legible: $filePath");
                }
            }
            
            if (count($missingFiles) > 0) {
                error_log("[R2_QUEUE][ZIP_EXTRACT] ⚠️ Archivos faltantes después de extracción: " . implode(', ', array_slice($missingFiles, 0, 5)));
            }
            
            // 5. Preparar cola de archivos para workers
            $filesQueue = [];
            $storagePrefix = rtrim($this->config['r2_storage_prefix'] ?? 'studies/', '/');
            
            foreach ($extractedFiles as $idx => $filePath) {
                // Determinar r2_key basado en la estructura del ZIP de Orthanc
                // Orthanc genera: PatientID/StudyUID/SeriesUID/SOPInstanceUID.dcm
                $relativePath = str_replace($extractDir . '/', '', $filePath);
                $r2Key = $storagePrefix . '/' . $studyInstanceUid . '/extracted/' . $relativePath;
                
                $filesQueue[] = [
                    'id'         => (string)$idx,
                    'status'     => 'pending',
                    'local_path' => $filePath,
                    'r2_key'     => $r2Key,
                    'worker_id'  => null
                ];
            }
            
            // 6. Calcular tamaño total
            $totalSize = 0;
            foreach ($filesQueue as $file) {
                if (file_exists($file['local_path'])) {
                    $totalSize += filesize($file['local_path']);
                }
            }
            
            // 7. Inicializar progreso en BD
            if ($queueId) {
                $this->updateQueueProgress($queueId, 0, $totalInstances, 0, $totalSize);
            }

            // Enforcement de cuota antes de comenzar el upload de los archivos extraídos
            $this->enforceQuotaBeforeUpload($orthancStudyId, $queueId, (int)$totalSize);
            
            // ETAPA 4: Subiendo con workers persistentes
            if ($queueId) {
                $this->updateQueueStatus($queueId, 'subiendo', null, null, null, null, null, null, false);
            }
            
            // 8. Obtener IPs del servidor
            $serverIp = $this->getServerIp();
            $serverIpWan = $this->getServerIpWan();
            
            // 9. Marcar inicio del upload
            $uploadStartTime = microtime(true);
            
            // 10. Lanzar pool de workers persistentes
            $concurrency = $this->maxConcurrency;
            error_log("[R2_QUEUE][ZIP_EXTRACT] Lanzando $concurrency workers para $totalInstances archivos");
            $uploadResults = $this->launchPersistentWorkerPool($filesQueue, $studyInstanceUid, $queueId, $serverIp, $serverIpWan);
            
            error_log("[R2_QUEUE][ZIP_EXTRACT] Workers completados. Resultados recibidos: " . count($uploadResults));
            
            // 11. Procesar resultados y calcular velocidades finales
            $instancesUploaded = 0;
            $totalBytesUploaded = 0;
            $uploadEndTime = microtime(true);
            $uploadDuration = $uploadEndTime - $uploadStartTime;
            
            foreach ($uploadResults as $result) {
                if ($result['success']) {
                    $instancesUploaded++;
                    $totalBytesUploaded += $result['size'];
                } else {
                    error_log('[R2_QUEUE] Error subiendo archivo extraído: ' . ($result['error'] ?? 'Unknown error') . ' (r2_key: ' . ($result['r2_key'] ?? 'N/A') . ')');
                }
            }
            
            error_log("[R2_QUEUE][ZIP_EXTRACT] Resumen: $instancesUploaded/$totalInstances archivos subidos, $totalBytesUploaded bytes");
            
            // 12. Calcular velocidades finales
            $finalAvgSpeed = $uploadDuration > 0 ? ($totalBytesUploaded / 1024 / 1024) / $uploadDuration : 0;
            
            // 13. Finalizar progreso con velocidades y tiempos finales
            if ($queueId) {
                // Obtener velocidades mín/max/avg del último updateQueueProgress
                $stmt = $this->db->prepare("
                    SELECT 
                        upload_speed_min_mbps,
                        upload_speed_max_mbps,
                        upload_speed_avg_mbps
                    FROM r2_queue 
                    WHERE id = ?
                ");
                $stmt->execute([$queueId]);
                $speedData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $finalSpeedMin = $speedData['upload_speed_min_mbps'] ?? $finalAvgSpeed;
                $finalSpeedMax = $speedData['upload_speed_max_mbps'] ?? $finalAvgSpeed;
                $finalSpeedAvg = $speedData['upload_speed_avg_mbps'] ?? $finalAvgSpeed;
                
                // Actualizar progreso final
                $this->updateQueueProgress(
                    $queueId,
                    $instancesUploaded,
                    $totalInstances,
                    $totalBytesUploaded,
                    $totalBytesUploaded,
                    $finalAvgSpeed,
                    $finalSpeedMin,
                    $finalSpeedMax,
                    $finalSpeedAvg,
                    $serverIp,
                    $serverIpWan
                );
                
                // Finalizar con estadísticas
                $this->finalizeQueueProgress(
                    $queueId,
                    $uploadDuration,
                    $finalSpeedMin,
                    $finalSpeedMax,
                    $finalSpeedAvg
                );
            }
            
            // 14. Limpiar archivos temporales SOLO DESPUÉS de que todos los workers terminen
            // IMPORTANTE: Los workers eliminan los archivos individuales después de subirlos,
            // pero el directorio se elimina aquí solo si todos los uploads fueron exitosos
            if ($instancesUploaded > 0) {
                error_log("[R2_QUEUE][ZIP_EXTRACT] Limpiando archivos temporales...");
                
                // Eliminar ZIP
                if ($zipPath && file_exists($zipPath)) {
                    @unlink($zipPath);
                    error_log("[R2_QUEUE][ZIP_EXTRACT] ZIP eliminado: $zipPath");
                }
                
                // Eliminar directorio de extracción (solo si está vacío o casi vacío)
                // Los workers ya eliminaron los archivos individuales
                if ($extractDir && is_dir($extractDir)) {
                    // Verificar si quedan archivos
                    $remainingFiles = glob($extractDir . '/**/*', GLOB_BRACE);
                    $remainingFiles = array_filter($remainingFiles, 'is_file');
                    
                    if (count($remainingFiles) === 0) {
                        $this->deleteDirectory($extractDir);
                        error_log("[R2_QUEUE][ZIP_EXTRACT] Directorio de extracción eliminado: $extractDir");
                    } else {
                        error_log("[R2_QUEUE][ZIP_EXTRACT] ⚠️ Quedan " . count($remainingFiles) . " archivos en $extractDir (no se elimina)");
                    }
                }
            } else {
                error_log("[R2_QUEUE][ZIP_EXTRACT] ⚠️ No se subieron archivos, manteniendo temporales para diagnóstico");
            }
            
            // 15. Guardar manifest en R2
            $manifestKey = $this->r2Driver->getManifestKey($studyInstanceUid);
            $this->r2Driver->putObject($manifestKey, json_encode($manifest, JSON_PRETTY_PRINT));
            
            // 16. Actualizar r2_studies
            $this->updateR2Studies($orthancStudyId, $studyInstanceUid, $manifestKey, $totalInstances, $totalBytesUploaded);
            
            return [
                'success' => $instancesUploaded === $totalInstances,
                'study_instance_uid' => $studyInstanceUid,
                'manifest_path' => $manifestKey,
                'total_instances' => $totalInstances,
                'total_size' => $totalBytesUploaded
            ];
            
        } catch (Exception $e) {
            // Limpiar en caso de error
            if ($zipPath && file_exists($zipPath)) {
                @unlink($zipPath);
            }
            if ($extractDir && is_dir($extractDir)) {
                $this->deleteDirectory($extractDir);
            }
            
            error_log('[R2_QUEUE] Error en uploadStudyToR2AsZipExtractUpload: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Descarga el ZIP de un estudio directamente desde Orthanc
     * 
     * @param string $orthancStudyId ID del estudio en Orthanc
     * @param string $zipPath Ruta donde guardar el ZIP
     * @param int|null $queueId ID de la cola para actualizar progreso
     * @return int Tamaño del archivo descargado en bytes
     */
    private function downloadStudyZipFromOrthanc($orthancStudyId, $zipPath, $queueId = null) {
        require_once __DIR__ . '/../../api/config/orthanc_config.php';
        $orthancUrl = OrthancConfig::getServerUrl();
        $credentials = OrthancConfig::getCredentials();
        
        $orthancEndpoint = $orthancUrl . '/studies/' . $orthancStudyId . '/archive';
        
        $fp = fopen($zipPath, 'wb');
        if (!$fp) {
            throw new Exception("No se pudo crear archivo ZIP: $zipPath");
        }
        
        $lastProgressUpdate = 0;
        $self = $this;
        
        $ch = curl_init($orthancEndpoint);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $credentials['username'] . ':' . $credentials['password'],
            CURLOPT_TIMEOUT        => 600, // 10 minutos para estudios grandes
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_BUFFERSIZE     => 131072, // 128KB buffer
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_PROGRESSFUNCTION => function($ch, $dlTotal, $dlNow) use (&$lastProgressUpdate, $queueId, $self) {
                // Actualizar progreso cada 5MB
                if ($dlNow > 0 && ($dlNow - $lastProgressUpdate) >= (5 * 1024 * 1024)) {
                    if ($queueId) {
                        $self->updateQueueProgress(
                            $queueId,
                            0, // instances_uploaded (aún no hay instancias)
                            0, // total_instances (aún no sabemos)
                            $dlNow, // bytes_uploaded (bytes descargados)
                            $dlTotal > 0 ? $dlTotal : null, // total_bytes
                            null, null, null, null, null, null
                        );
                    }
                    $lastProgressUpdate = $dlNow;
                }
                return 0; // Continuar la descarga
            }
        ]);
        
        // HTTP/2 y keep-alive
        if (defined('CURL_HTTP_VERSION_2_0')) {
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
        }
        curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
        curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 60);
        
        $curlSuccess = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        curl_close($ch);
        fclose($fp);
        
        if (!$curlSuccess || $httpCode !== 200) {
            if (file_exists($zipPath)) {
                @unlink($zipPath);
            }
            throw new Exception("Error descargando ZIP desde Orthanc: HTTP $httpCode - $curlError");
        }
        
        $fileSize = filesize($zipPath);
        if ($fileSize === false || $fileSize === 0) {
            throw new Exception("ZIP descargado está vacío o no se pudo leer");
        }
        
        return $fileSize;
    }
    
    /**
     * Extrae un ZIP y retorna lista de archivos DICOM extraídos
     * 
     * @param string $zipPath Ruta del archivo ZIP
     * @param string $extractDir Directorio donde extraer
     * @return array Array de rutas de archivos .dcm extraídos
     */
    private function extractStudyZip($zipPath, $extractDir) {
        if (!file_exists($zipPath)) {
            throw new Exception("Archivo ZIP no existe: $zipPath");
        }
        
        if (!is_readable($zipPath)) {
            throw new Exception("Archivo ZIP no es legible: $zipPath");
        }
        
        if (!is_writable($extractDir)) {
            throw new Exception("Directorio de extracción no tiene permisos de escritura: $extractDir");
        }
        
        $zip = new ZipArchive();
        $result = $zip->open($zipPath);
        if ($result !== true) {
            $errorMessages = [
                ZipArchive::ER_OK => 'OK',
                ZipArchive::ER_MULTIDISK => 'Multi-disk zip archives not supported',
                ZipArchive::ER_RENAME => 'Renaming temporary file failed',
                ZipArchive::ER_CLOSE => 'Closing zip archive failed',
                ZipArchive::ER_SEEK => 'Seek error',
                ZipArchive::ER_READ => 'Read error',
                ZipArchive::ER_WRITE => 'Write error',
                ZipArchive::ER_CRC => 'CRC error',
                ZipArchive::ER_ZIPCLOSED => 'Containing zip archive was closed',
                ZipArchive::ER_NOENT => 'No such file',
                ZipArchive::ER_EXISTS => 'File already exists',
                ZipArchive::ER_OPEN => 'Can\'t open file',
                ZipArchive::ER_TMPOPEN => 'Failure to create temporary file',
                ZipArchive::ER_ZLIB => 'Zlib error',
                ZipArchive::ER_MEMORY => 'Memory allocation failure',
                ZipArchive::ER_CHANGED => 'Entry has been changed',
                ZipArchive::ER_COMPNOTSUPP => 'Compression method not supported',
                ZipArchive::ER_EOF => 'Premature EOF',
                ZipArchive::ER_INVAL => 'Invalid argument',
                ZipArchive::ER_NOZIP => 'Not a zip archive',
                ZipArchive::ER_INTERNAL => 'Internal error',
                ZipArchive::ER_INCONS => 'Zip archive inconsistent',
                ZipArchive::ER_REMOVE => 'Can\'t remove file',
                ZipArchive::ER_DELETED => 'Entry has been deleted'
            ];
            $errorMsg = $errorMessages[$result] ?? "Código de error desconocido: $result";
            throw new Exception("No se pudo abrir el ZIP: $errorMsg (código: $result)");
        }
        
        // Extraer preservando estructura de directorios
        if (!$zip->extractTo($extractDir)) {
            $zip->close();
            throw new Exception("Error al extraer ZIP a: $extractDir");
        }
        
        $extractedCount = $zip->numFiles;
        $zip->close();
        
        error_log("[R2_QUEUE][ZIP_EXTRACT] ZIP extraído: $extractedCount entradas extraídas a $extractDir");
        
        // Recolectar todos los archivos .dcm extraídos
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($extractDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $extension = strtolower($file->getExtension());
                if ($extension === 'dcm' || $extension === 'dicom') {
                    $filePath = $file->getPathname();
                    if (file_exists($filePath) && is_readable($filePath)) {
                        $files[] = $filePath;
                    } else {
                        error_log("[R2_QUEUE][ZIP_EXTRACT] ⚠️ Archivo extraído no accesible: $filePath");
                    }
                }
            }
        }
        
        error_log("[R2_QUEUE][ZIP_EXTRACT] Archivos .dcm encontrados: " . count($files));
        
        return $files;
    }

    /**
     * Tras un pase rclone asociado a queueId: ejecuta sincronizaciones incrementales
     * si quedó needs_followup_sync o needs_resync.
     */
    private function processRcloneDeferredFollowUps(int $queueId, string $orthancStudyId): void {
        $hasFollowup = $this->r2QueueHasColumn('needs_followup_sync');
        $hasNeeds = $this->r2StudiesHasColumn('needs_resync');
        if (!$hasFollowup && !$hasNeeds) {
            return;
        }

        $maxPasses = 5;
        while ($maxPasses-- > 0) {
            $this->db->beginTransaction();
            // Mismo orden que CloudStorageManager::enqueueStudy (r2_studies antes que r2_queue) para evitar deadlocks
            $need = false;
            if ($hasNeeds) {
                $s = $this->db->prepare('SELECT needs_resync FROM r2_studies WHERE orthanc_study_id = ? FOR UPDATE');
                $s->execute([$orthancStudyId]);
                $row = $s->fetch(PDO::FETCH_ASSOC);
                $need = $row && ((int) $row['needs_resync']) === 1;
            }
            $follow = false;
            if ($hasFollowup) {
                $s = $this->db->prepare('SELECT needs_followup_sync FROM r2_queue WHERE id = ? FOR UPDATE');
                $s->execute([$queueId]);
                $row = $s->fetch(PDO::FETCH_ASSOC);
                $follow = $row && ((int) $row['needs_followup_sync']) === 1;
            }
            $this->db->commit();

            if (!$follow && !$need) {
                break;
            }

            $this->uploadStudyToR2AsRcloneIncremental($orthancStudyId, null);

            if ($hasFollowup) {
                $c = $this->db->prepare('UPDATE r2_queue SET needs_followup_sync = 0 WHERE id = ?');
                $c->execute([$queueId]);
            }
            if ($hasNeeds) {
                $c = $this->db->prepare('
                    UPDATE r2_studies SET needs_resync = 0, resync_requested_at = NULL, updated_at = NOW()
                    WHERE orthanc_study_id = ?
                ');
                $c->execute([$orthancStudyId]);
            }
        }
    }

    /**
     * @return array{0:int,1:int} [totalInstances, totalBytes desde fileSize del manifest]
     */
    private function totalsFromManifest(array $manifest): array {
        $ti = 0;
        $tb = 0;
        foreach ($manifest['series'] ?? [] as $s) {
            foreach ($s['instances'] ?? [] as $inst) {
                $ti++;
                $tb += (int) ($inst['fileSize'] ?? 0);
            }
        }
        return [$ti, $tb];
    }

    /**
     * Lista rutas relativas (archivos) bajo el prefijo de estudio en R2 vía rclone lsf.
     *
     * @return string[] paths con / sin barra inicial
     */
    private function rcloneListRemoteRelativePaths(string $r2Dest): array {
        $bucketName = $this->config['r2_bucket_name'];
        $r2Remote = 'r2:' . $bucketName . '/' . $r2Dest;

        $rcloneTempDir = sys_get_temp_dir();
        $rcloneConfigFile = $rcloneTempDir . '/rclone_r2_lsf_' . uniqid() . '.conf';
        $endpoint = 'https://' . $this->config['r2_account_id'] . '.r2.cloudflarestorage.com';
        $region   = $this->config['r2_region'] ?? 'auto';

        $rcloneConfigContent = "[r2]\n"
            . "type = s3\n"
            . "provider = Cloudflare\n"
            . "access_key_id = " . $this->config['r2_access_key'] . "\n"
            . "secret_access_key = " . $this->config['r2_secret_key'] . "\n"
            . "endpoint = " . $endpoint . "\n"
            . "region = " . $region . "\n"
            . "no_check_bucket = true\n"
            . "env_auth = false\n";

        if (file_put_contents($rcloneConfigFile, $rcloneConfigContent) === false) {
            throw new Exception("No se pudo crear archivo de config temporal para rclone lsf: $rcloneConfigFile");
        }
        chmod($rcloneConfigFile, 0600);

        $env = [
            'HOME'           => $rcloneTempDir,
            'PATH'           => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'TMPDIR'         => $rcloneTempDir,
            'RCLONE_CONFIG'  => $rcloneConfigFile,
        ];

        $nativeBinary = __DIR__ . '/bin/rclone';
        $commonPaths = [
            $nativeBinary,
            '/usr/local/bin/rclone',
            '/usr/bin/rclone',
        ];
        $rclonePath = null;
        foreach ($commonPaths as $path) {
            if (file_exists($path) && is_executable($path)) {
                $rclonePath = $path;
                break;
            }
        }
        if (empty($rclonePath)) {
            $whichPath = trim((string) shell_exec('which rclone 2>/dev/null'));
            if ($whichPath !== '' && file_exists($whichPath) && strpos($whichPath, '/snap/') === false) {
                $rclonePath = $whichPath;
            }
        }
        if (empty($rclonePath)) {
            @unlink($rcloneConfigFile);
            throw new Exception('rclone nativo no encontrado para lsf');
        }

        $cmd = sprintf(
            '%s lsf %s --config %s --recursive --files-only',
            escapeshellarg($rclonePath),
            escapeshellarg($r2Remote),
            escapeshellarg($rcloneConfigFile)
        );

        $descriptorspec = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptorspec, $pipes, null, $env);
        if (!is_resource($process)) {
            @unlink($rcloneConfigFile);
            throw new Exception('No se pudo ejecutar rclone lsf');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = -1;
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = (int) ($status['exitcode'] ?? -1);
                break;
            }
            usleep(50000);
        }
        proc_close($process);
        @unlink($rcloneConfigFile);

        if ($exitCode !== 0) {
            error_log('[R2_QUEUE][RCLONE][LSF] stderr: ' . substr((string) $stderr, 0, 500));
            throw new Exception('rclone lsf falló con código ' . $exitCode);
        }

        $paths = [];
        foreach (preg_split("/\r\n|\n|\r/", (string) $stdout) as $line) {
            $line = trim(str_replace('\\', '/', $line), '/');
            if ($line === '') {
                continue;
            }
            if (basename($line) === 'manifest.json') {
                continue;
            }
            $paths[] = $line;
        }
        return $paths;
    }

    /**
     * Re-sync rclone: índice ZIP + rclone lsf + extrae solo delta + rclone copy + manifest.
     *
     * @return array Resultado del upload (compatible con uploadStudyToR2AsRclone)
     */
    private function uploadStudyToR2AsRcloneIncremental($orthancStudyId, $queueId = null) {
        $zipPath = null;
        $workDir = null;
        $extractSubDir = null;

        try {
            if ($queueId) {
                $this->updateQueueStatus($queueId, 'preparando_estudio', null, null, null, null, null, null, false);
                $stmt = $this->db->prepare("UPDATE r2_queue SET upload_method = 'rclone' WHERE id = ?");
                $stmt->execute([$queueId]);
            }

            $manifest = $this->manifestBuilder->buildManifest($orthancStudyId);
            $studyInstanceUid = $manifest['studyInstanceUID'];

            $tempBaseDirRaw = $this->config['r2_instances_temp_dir'] ?? __DIR__ . '/../temp/instances';
            if (is_dir($tempBaseDirRaw)) {
                $tempBaseDir = realpath($tempBaseDirRaw);
            } else {
                $basePath = realpath(__DIR__ . '/..');
                if (strpos($tempBaseDirRaw, __DIR__) === 0) {
                    $relativePath = str_replace(__DIR__ . '/', '', $tempBaseDirRaw);
                    $tempBaseDir = $basePath . '/' . str_replace('../', '', $relativePath);
                } else {
                    $tempBaseDir = $tempBaseDirRaw;
                }
            }
            if (!$tempBaseDir) {
                throw new Exception("No se pudo determinar ruta del directorio temporal");
            }
            if (!is_dir($tempBaseDir)) {
                if (!mkdir($tempBaseDir, 0755, true)) {
                    throw new Exception("No se pudo crear directorio temporal: $tempBaseDir");
                }
            }
            $tempBaseDir = realpath($tempBaseDir);
            if (!$tempBaseDir || !is_dir($tempBaseDir) || !is_writable($tempBaseDir)) {
                throw new Exception("Directorio temporal no usable: $tempBaseDir");
            }

            $workDir = $tempBaseDir . '/incr_' . $studyInstanceUid . '_' . uniqid();
            if (!mkdir($workDir, 0755, true)) {
                throw new Exception("No se pudo crear directorio de trabajo incremental");
            }
            $workDir = realpath($workDir);
            if (!$workDir) {
                throw new Exception('Directorio incremental inválido');
            }

            if ($queueId) {
                $this->updateQueueStatus($queueId, 'descargando_instancias', null, null, null, null, null, null, false);
            }

            $zipPath = $workDir . '/study_archive.zip';
            $this->downloadStudyZipFromOrthanc($orthancStudyId, $zipPath, $queueId);

            if ($queueId) {
                $this->updateQueueStatus($queueId, 'generando_zip', null, null, null, null, null, null, false);
            }

            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new Exception('No se pudo abrir ZIP para sincronización incremental');
            }
            $dicomEntries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false) {
                    continue;
                }
                $lastCh = substr($name, -1);
                if ($lastCh === '/' || $lastCh === '\\') {
                    continue;
                }
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if ($ext !== 'dcm' && $ext !== 'dicom') {
                    continue;
                }
                $norm = str_replace('\\', '/', $name);
                $norm = ltrim($norm, '/');
                $dicomEntries[] = ['entry' => $name, 'norm' => $norm];
            }
            $zip->close();

            $storagePrefix = rtrim($this->config['r2_storage_prefix'] ?? 'studies/', '/');
            $r2Dest = $storagePrefix . '/' . $studyInstanceUid . '/';

            // En incremental rclone, el manifest debe conservar los "path" reales hacia keys del bucket,
            // que corresponden al layout del ZIP (no el layout canónico series/{SeriesUID}/{SOPUID}.dcm).
            // Por eso cargamos el manifest previo en R2 y reutilizamos los path de instancias existentes.
            $manifestKey = $this->r2Driver->getManifestKey($studyInstanceUid);
            $oldManifest = null;
            $oldSopUidToPath = [];
            $oldManifestLooksCanonical = false;

            try {
                $oldManifestJson = $this->r2Driver->getObject($manifestKey);
                $oldManifest = json_decode($oldManifestJson, true);
            } catch (Exception $e) {
                $oldManifest = null;
            }

            if (is_array($oldManifest) && isset($oldManifest['series']) && is_array($oldManifest['series'])) {
                foreach ($oldManifest['series'] as $s) {
                    foreach ($s['instances'] ?? [] as $inst) {
                        $sopUid = $inst['sopInstanceUID'] ?? null;
                        $path = $inst['path'] ?? null;
                        if (!empty($sopUid) && is_string($path) && $path !== '') {
                            $oldSopUidToPath[$sopUid] = $path;
                            if (strpos($path, '/series/') !== false) {
                                $oldManifestLooksCanonical = true;
                            }
                        }
                    }
                }
            }

            $remoteList = $this->rcloneListRemoteRelativePaths($r2Dest);
            $remoteFlip = array_fill_keys($remoteList, true);

            $deltaEntries = [];
            foreach ($dicomEntries as $item) {
                if (!isset($remoteFlip[$item['norm']])) {
                    $deltaEntries[] = $item['entry'];
                }
            }

            error_log('[R2_QUEUE][RCLONE][INCR] dicom_zip=' . count($dicomEntries) . ' remoto=' . count($remoteList) . ' delta=' . count($deltaEntries));

            if (count($deltaEntries) === 0) {
                if (file_exists($zipPath)) {
                    unlink($zipPath);
                }
                if ($workDir && is_dir($workDir)) {
                    $this->deleteDirectory($workDir);
                }

                // Conservamos el manifest previo para mantener los paths reales del layout del ZIP (rclone).
                // Si el manifest previo es inexistente o canónico, el incremental no puede corregirlo sin un re-sync completo.
                if (!is_array($oldManifest) || $oldManifestLooksCanonical) {
                    throw new Exception('Manifest anterior inexistente o canónico en incremental rclone: requiere re-sync completo (no incremental) para regenerar paths reales.');
                }

                [$ti, $tb] = $this->totalsFromManifest($oldManifest);
                $manifestJson = json_encode($oldManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                $this->r2Driver->putObject($manifestKey, $manifestJson, 'application/json');
                $this->updateR2Studies($orthancStudyId, $studyInstanceUid, $manifestKey, $ti, $tb, true);

                return [
                    'success' => true,
                    'study_instance_uid' => $studyInstanceUid,
                    'manifest_path' => $manifestKey,
                    'total_instances' => $ti,
                    'total_size' => $tb,
                    'incremental' => true,
                    'delta_files' => 0,
                ];
            }

            if ($zip->open($zipPath) !== true) {
                throw new Exception('No se pudo reabrir ZIP para extracción delta');
            }
            $extractSubDir = $workDir . '/upload_partial';
            if (!mkdir($extractSubDir, 0755, true)) {
                throw new Exception("No se pudo crear directorio delta: $extractSubDir");
            }
            if (!$zip->extractTo($extractSubDir, $deltaEntries)) {
                $zip->close();
                throw new Exception('Error extrayendo entradas DICOM nuevas del ZIP');
            }
            $zip->close();

            if (file_exists($zipPath)) {
                unlink($zipPath);
                $zipPath = null;
            }

            $totalSize = 0;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($extractSubDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $totalSize += (int) $file->getSize();
                }
            }
            $totalInstances = count($deltaEntries);

            if ($queueId) {
                $this->updateQueueProgress($queueId, 0, $totalInstances, 0, $totalSize);
            }

            $this->enforceQuotaBeforeUpload($orthancStudyId, $queueId, (int) $totalSize);

            if ($queueId) {
                $this->updateQueueStatus($queueId, 'subiendo', null, null, null, null, null, null, false);
            }

            $serverIp = $this->getServerIp();
            $serverIpWan = $this->getServerIpWan();
            $uploadStartTime = microtime(true);

            error_log("[R2_QUEUE][RCLONE][INCR] rclone copy delta: $extractSubDir → r2:{$this->config['r2_bucket_name']}/$r2Dest");
            $uploadResult = $this->executeRcloneCopy($extractSubDir, $r2Dest, $queueId, $totalInstances, $totalSize);

            $uploadEndTime = microtime(true);
            $uploadDuration = $uploadEndTime - $uploadStartTime;
            $instancesUploaded = $uploadResult['files_uploaded'];
            $bytesUploaded = $uploadResult['bytes_uploaded'];
            $finalAvgSpeed = $uploadDuration > 0 ? ($bytesUploaded / 1024 / 1024) / $uploadDuration : 0;

            if ($queueId) {
                $this->updateQueueProgress(
                    $queueId,
                    $instancesUploaded,
                    $totalInstances,
                    $bytesUploaded,
                    $totalSize,
                    $finalAvgSpeed,
                    $finalAvgSpeed,
                    $finalAvgSpeed,
                    $finalAvgSpeed,
                    $serverIp,
                    $serverIpWan
                );
                $this->finalizeQueueProgress(
                    $queueId,
                    $uploadDuration,
                    $finalAvgSpeed,
                    $finalAvgSpeed,
                    $finalAvgSpeed
                );
            }

            // Antes de limpiar, generamos el mapa SOPInstanceUID => path real del delta recién extraído.
            // Esto permite corregir el manifest (paths) sin volver a usar el layout canónico en modo rclone.
            $r2DestTrimmed = rtrim($r2Dest, '/');
            $deltaSopUidToPath = [];
            if ($extractSubDir && is_dir($extractSubDir)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($extractSubDir, RecursiveDirectoryIterator::SKIP_DOTS)
                );
                foreach ($iterator as $file) {
                    if (!$file->isFile()) {
                        continue;
                    }

                    $extension = strtolower($file->getExtension());
                    if ($extension !== 'dcm' && $extension !== 'dicom') {
                        continue;
                    }

                    $filePath = $file->getPathname();
                    if (!is_readable($filePath)) {
                        continue;
                    }

                    $relativePath = str_replace($extractSubDir . '/', '', $filePath);
                    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
                    if ($relativePath === '') {
                        continue;
                    }

                    $sopUid = $this->getSopInstanceUidFromDicomWithDcmtk($filePath);
                    if (empty($sopUid)) {
                        continue;
                    }

                    if (!isset($deltaSopUidToPath[$sopUid])) {
                        $deltaSopUidToPath[$sopUid] = $r2DestTrimmed . '/' . $relativePath;
                    }
                }
            }

            if ($extractSubDir && is_dir($extractSubDir)) {
                $this->deleteDirectory($extractSubDir);
                $extractSubDir = null;
            }
            if ($workDir && is_dir($workDir)) {
                $this->deleteDirectory($workDir);
                $workDir = null;
            }

            if (!is_array($oldManifest) || $oldManifestLooksCanonical) {
                throw new Exception('Manifest anterior inexistente o canónico en incremental rclone: requiere re-sync completo (no incremental) para regenerar paths reales.');
            }

            // Reconstruimos metadata desde Orthanc, pero corregimos paths contra el manifest previo + delta.
            $manifestFresh = $this->manifestBuilder->buildManifest($orthancStudyId);
            foreach ($manifestFresh['series'] ?? [] as &$series) {
                $newInstances = [];
                foreach ($series['instances'] ?? [] as $inst) {
                    $sopUid = $inst['sopInstanceUID'] ?? null;
                    if (empty($sopUid)) {
                        continue;
                    }

                    if (isset($oldSopUidToPath[$sopUid])) {
                        $inst['path'] = $oldSopUidToPath[$sopUid];
                        $newInstances[] = $inst;
                    } elseif (isset($deltaSopUidToPath[$sopUid])) {
                        $inst['path'] = $deltaSopUidToPath[$sopUid];
                        $newInstances[] = $inst;
                    }
                }
                $series['instances'] = $newInstances;
            }
            unset($series);

            $manifestFresh['series'] = array_values(array_filter($manifestFresh['series'] ?? [], function ($s) {
                return isset($s['instances']) && is_array($s['instances']) && count($s['instances']) > 0;
            }));

            if (count($manifestFresh['series']) === 0) {
                throw new Exception('Manifest incremental rclone: no se pudieron mapear instancias contra paths reales del bucket.');
            }

            [$ti, $tb] = $this->totalsFromManifest($manifestFresh);
            $manifestJson = json_encode($manifestFresh, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $this->r2Driver->putObject($manifestKey, $manifestJson, 'application/json');
            $this->updateR2Studies($orthancStudyId, $studyInstanceUid, $manifestKey, $ti, $tb, true);

            return [
                'success' => $instancesUploaded >= $totalInstances,
                'study_instance_uid' => $studyInstanceUid,
                'manifest_path' => $manifestKey,
                'total_instances' => $ti,
                'total_size' => $tb,
                'incremental' => true,
                'delta_files' => $totalInstances,
            ];
        } catch (Exception $e) {
            if ($zipPath !== null && file_exists($zipPath)) {
                @unlink($zipPath);
            }
            if ($extractSubDir && is_dir($extractSubDir)) {
                $this->deleteDirectory($extractSubDir);
            }
            if ($workDir && is_dir($workDir)) {
                $this->deleteDirectory($workDir);
            }
            error_log('[R2_QUEUE] Error en uploadStudyToR2AsRcloneIncremental: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Sube un estudio a R2 usando rclone:
     * 1. Descarga el ZIP directamente desde Orthanc (/studies/{id}/archive)
     * 2. Extrae el ZIP localmente
     * 3. Usa rclone copy para subir toda la carpeta (rclone maneja concurrencia y pool HTTP interno)
     * 4. Limpia archivos temporales
     * 
     * @param string $orthancStudyId ID del estudio en Orthanc
     * @param int|null $queueId ID de la cola para actualizar progreso
     * @param bool $isResync Job de re-sync (incremental por índice ZIP + rclone)
     * @return array Resultado del upload
     */
    private function uploadStudyToR2AsRclone($orthancStudyId, $queueId = null, $isResync = false) {
        if ($isResync) {
            return $this->uploadStudyToR2AsRcloneIncremental($orthancStudyId, $queueId);
        }

        $zipPath = null;
        $extractDir = null;
        
        try {
            // ETAPA 1: Preparando estudio
            if ($queueId) {
                $this->updateQueueStatus($queueId, 'preparando_estudio', null, null, null, null, null, null, false);
                $stmt = $this->db->prepare("UPDATE r2_queue SET upload_method = 'rclone' WHERE id = ?");
                $stmt->execute([$queueId]);
            }
            
            // 1. Construir manifest para obtener StudyInstanceUID
            $manifest = $this->manifestBuilder->buildManifest($orthancStudyId);
            $studyInstanceUid = $manifest['studyInstanceUID'];
            
            // 2. Obtener directorio temporal y resolver ruta absoluta
            $tempBaseDirRaw = $this->config['r2_instances_temp_dir'] ?? __DIR__ . '/../temp/instances';
            
            // Resolver ruta absoluta antes de crear (realpath solo funciona si existe)
            // Si no existe, construir la ruta absoluta manualmente
            if (is_dir($tempBaseDirRaw)) {
                $tempBaseDir = realpath($tempBaseDirRaw);
            } else {
                // Construir ruta absoluta desde __DIR__
                $basePath = realpath(__DIR__ . '/..');
                if (strpos($tempBaseDirRaw, __DIR__) === 0) {
                    // Es una ruta relativa desde __DIR__
                    $relativePath = str_replace(__DIR__ . '/', '', $tempBaseDirRaw);
                    $tempBaseDir = $basePath . '/' . str_replace('../', '', $relativePath);
                } else {
                    // Es una ruta absoluta o relativa desde otro lugar
                    $tempBaseDir = $tempBaseDirRaw;
                }
            }
            
            if (!$tempBaseDir) {
                throw new Exception("No se pudo determinar ruta del directorio temporal");
            }
            
            // Crear directorio si no existe
            if (!is_dir($tempBaseDir)) {
                if (!mkdir($tempBaseDir, 0755, true)) {
                    throw new Exception("No se pudo crear directorio temporal: $tempBaseDir");
                }
            }
            
            // Verificar que ahora existe y obtener ruta absoluta real
            $tempBaseDir = realpath($tempBaseDir);
            if (!$tempBaseDir || !is_dir($tempBaseDir)) {
                throw new Exception("Directorio temporal no existe o no es accesible: $tempBaseDir");
            }
            
            if (!is_writable($tempBaseDir)) {
                throw new Exception("Directorio temporal no tiene permisos de escritura: $tempBaseDir");
            }
            
            // Crear directorio de extracción con ruta absoluta
            $extractDir = $tempBaseDir . '/extracted_' . $studyInstanceUid . '_' . uniqid();
            if (!mkdir($extractDir, 0755, true)) {
                throw new Exception("No se pudo crear directorio de extracción: $extractDir");
            }
            
            // Resolver ruta absoluta del directorio de extracción
            $extractDir = realpath($extractDir);
            if (!$extractDir || !is_dir($extractDir)) {
                throw new Exception("Directorio de extracción no existe o no es accesible: $extractDir");
            }
            
            if (!is_writable($extractDir)) {
                throw new Exception("Directorio de extracción no tiene permisos de escritura: $extractDir");
            }
            
            error_log("[R2_QUEUE][RCLONE] Directorio de extracción creado: $extractDir (permisos OK)");
            
            // ETAPA 2: Descargando ZIP desde Orthanc
            if ($queueId) {
                $this->updateQueueStatus($queueId, 'descargando_instancias', null, null, null, null, null, null, false);
            }
            
            // 3. Descargar ZIP desde Orthanc
            $zipPath = $extractDir . '/study_archive.zip';
            $zipSize = $this->downloadStudyZipFromOrthanc($orthancStudyId, $zipPath, $queueId);
            
            // ETAPA 3: Extrayendo ZIP
            if ($queueId) {
                $this->updateQueueStatus($queueId, 'generando_zip', null, null, null, null, null, null, false);
            }
            
            // 4. Extraer ZIP localmente
            error_log("[R2_QUEUE][RCLONE] Extrayendo ZIP: $zipPath → $extractDir");
            $extractedFiles = $this->extractStudyZip($zipPath, $extractDir);
            $totalInstances = count($extractedFiles);
            
            error_log("[R2_QUEUE][RCLONE] ZIP extraído: $totalInstances archivos encontrados en $extractDir");
            
            if ($totalInstances === 0) {
                throw new Exception("No se encontraron archivos DICOM en el ZIP extraído");
            }
            
            // 5. Calcular tamaño total
            $totalSize = 0;
            foreach ($extractedFiles as $filePath) {
                if (file_exists($filePath)) {
                    $totalSize += filesize($filePath);
                }
            }
            
            // 5.1. Eliminar el ZIP después de extraerlo (no debe subirse a R2)
            if (file_exists($zipPath)) {
                unlink($zipPath);
                error_log("[R2_QUEUE][RCLONE] ZIP eliminado después de extraer: $zipPath");
            }
            
            // 6. Inicializar progreso en BD
            if ($queueId) {
                $this->updateQueueProgress($queueId, 0, $totalInstances, 0, $totalSize);
            }

            // Enforcement de cuota antes de comenzar el upload con rclone
            $this->enforceQuotaBeforeUpload($orthancStudyId, $queueId, (int)$totalSize);
            
            // ETAPA 4: Subiendo con rclone
            if ($queueId) {
                $this->updateQueueStatus($queueId, 'subiendo', null, null, null, null, null, null, false);
            }
            
            // 7. Obtener IPs del servidor
            $serverIp = $this->getServerIp();
            $serverIpWan = $this->getServerIpWan();
            
            // 8. Marcar inicio del upload
            $uploadStartTime = microtime(true);
            
            // 9. Construir destino en R2
            $storagePrefix = rtrim($this->config['r2_storage_prefix'] ?? 'studies/', '/');
            $r2Dest = $storagePrefix . '/' . $studyInstanceUid . '/';
            
            // 10. $extractDir ya está resuelto como ruta absoluta arriba, solo verificar
            if (!is_dir($extractDir)) {
                throw new Exception("Directorio de extracción no existe: $extractDir");
            }

            // 9.1. Armar mapa SOPInstanceUID => ruta relativa real del ZIP extraído.
            // Con rclone, el bucket conserva la estructura del ZIP de Orthanc (p.ej. Patient/Study/Series/CT000000.dcm),
            // por lo que el manifest debe apuntar a esas keys reales y NO al layout canónico tipo series/{SeriesUID}/{SOPUID}.dcm.
            $sopUidToRelativePath = [];
            error_log('[R2_QUEUE][RCLONE] Construyendo mapa SOP->path real desde ZIP extraído...');
            foreach ($extractedFiles as $filePath) {
                if (!is_readable($filePath)) {
                    continue;
                }

                $relativePath = str_replace($extractDir . '/', '', $filePath);
                $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
                if ($relativePath === '') {
                    continue;
                }

                $sopUid = $this->getSopInstanceUidFromDicomWithDcmtk($filePath);
                if (empty($sopUid)) {
                    continue;
                }

                // Si por algún motivo hubiera colisiones (no esperado), conservamos el primero.
                if (!isset($sopUidToRelativePath[$sopUid])) {
                    $sopUidToRelativePath[$sopUid] = $relativePath;
                }
            }

            // 9.2. Reescribir instance.path del manifest hacia keys reales del bucket.
            $r2DestTrimmed = rtrim($r2Dest, '/');
            $mappedInstances = 0;
            $removedInstances = 0;

            if (!isset($manifest['series']) || !is_array($manifest['series'])) {
                throw new Exception('Manifest inválido: falta series[]');
            }

            foreach ($manifest['series'] as &$series) {
                if (!isset($series['instances']) || !is_array($series['instances'])) {
                    $series['instances'] = [];
                    continue;
                }

                $newInstances = [];
                foreach ($series['instances'] as $instance) {
                    $sopUid = $instance['sopInstanceUID'] ?? null;
                    if (!empty($sopUid) && isset($sopUidToRelativePath[$sopUid])) {
                        $instance['path'] = $r2DestTrimmed . '/' . $sopUidToRelativePath[$sopUid];
                        $newInstances[] = $instance;
                        $mappedInstances++;
                    } else {
                        $removedInstances++;
                    }
                }

                $series['instances'] = $newInstances;
            }
            unset($series);

            // Eliminar series que quedaron sin instancias mapeadas (para evitar que el visor reciba URLs 404).
            $manifest['series'] = array_values(array_filter($manifest['series'], function ($s) {
                return isset($s['instances']) && is_array($s['instances']) && count($s['instances']) > 0;
            }));

            if (count($manifest['series']) === 0) {
                throw new Exception('No se pudo mapear ninguna instancia del manifest contra keys reales del ZIP extraído (rclone).');
            }

            error_log(sprintf(
                '[R2_QUEUE][RCLONE] Manifest actualizado a paths reales: mappedInstances=%d removedInstances=%d series=%d',
                (int)$mappedInstances,
                (int)$removedInstances,
                (int)count($manifest['series'])
            ));

            error_log("[R2_QUEUE][RCLONE] Iniciando upload con rclone: $extractDir → r2:{$this->config['r2_bucket_name']}/$r2Dest");
            $uploadResult = $this->executeRcloneCopy($extractDir, $r2Dest, $queueId, $totalInstances, $totalSize);
            
            $uploadEndTime = microtime(true);
            $uploadDuration = $uploadEndTime - $uploadStartTime;
            
            // 11. Calcular velocidades finales
            $instancesUploaded = $uploadResult['files_uploaded'];
            $bytesUploaded = $uploadResult['bytes_uploaded'];
            $finalAvgSpeed = $uploadDuration > 0 ? ($bytesUploaded / 1024 / 1024) / $uploadDuration : 0;
            
            // 12. Finalizar progreso con velocidades y tiempos finales
            if ($queueId) {
                $this->updateQueueProgress(
                    $queueId,
                    $instancesUploaded,
                    $totalInstances,
                    $bytesUploaded,
                    $totalSize,
                    $finalAvgSpeed,
                    $finalAvgSpeed, // min
                    $finalAvgSpeed, // max
                    $finalAvgSpeed, // avg
                    $serverIp,
                    $serverIpWan
                );
                
                $this->finalizeQueueProgress(
                    $queueId,
                    $uploadDuration,
                    $finalAvgSpeed,
                    $finalAvgSpeed,
                    $finalAvgSpeed
                );
            }
            
            // 13. Limpiar archivos temporales
            if ($zipPath && file_exists($zipPath)) {
                @unlink($zipPath);
                error_log("[R2_QUEUE][RCLONE] ZIP eliminado: $zipPath");
            }
            if ($extractDir && is_dir($extractDir)) {
                $this->deleteDirectory($extractDir);
                error_log("[R2_QUEUE][RCLONE] Directorio de extracción eliminado: $extractDir");
            }
            
            // 14. Guardar manifest en R2
            $manifestKey = $this->r2Driver->getManifestKey($studyInstanceUid);
            $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $this->r2Driver->putObject($manifestKey, $manifestJson, 'application/json');
            
            // 15. Actualizar r2_studies
            $this->updateR2Studies($orthancStudyId, $studyInstanceUid, $manifestKey, $totalInstances, $bytesUploaded, false);
            
            return [
                'success' => $instancesUploaded === $totalInstances,
                'study_instance_uid' => $studyInstanceUid,
                'manifest_path' => $manifestKey,
                'total_instances' => $totalInstances,
                'total_size' => $bytesUploaded
            ];
            
        } catch (Exception $e) {
            // Limpiar en caso de error
            if ($zipPath && file_exists($zipPath)) {
                @unlink($zipPath);
            }
            if ($extractDir && is_dir($extractDir)) {
                $this->deleteDirectory($extractDir);
            }
            
            error_log('[R2_QUEUE] Error en uploadStudyToR2AsRclone: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Ejecuta rclone copy para subir archivos a R2
     * 
     * @param string $sourceDir Directorio fuente (carpeta extraída)
     * @param string $r2Dest Ruta destino en R2 (sin bucket, ej: 'studies/UID/')
     * @param int|null $queueId ID de la cola para actualizar progreso
     * @param int $totalFiles Total de archivos esperados
     * @param int $totalSize Tamaño total esperado en bytes
     * @return array ['files_uploaded' => int, 'bytes_uploaded' => int]
     */
    private function executeRcloneCopy($sourceDir, $r2Dest, $queueId = null, $totalFiles = 0, $totalSize = 0) {
        $bucketName = $this->config['r2_bucket_name'];
        $r2Remote = "r2:$bucketName/$r2Dest";
        
        // Generar archivo de config temporal para rclone
        // Más robusto que env vars: evita problemas con HOME/getent/config file lookup
        $rcloneTempDir = sys_get_temp_dir();
        $rcloneConfigFile = $rcloneTempDir . '/rclone_r2_' . uniqid() . '.conf';
        $endpoint = 'https://' . $this->config['r2_account_id'] . '.r2.cloudflarestorage.com';
        $region   = $this->config['r2_region'] ?? 'auto';
        
        $rcloneConfigContent = "[r2]\n"
            . "type = s3\n"
            . "provider = Cloudflare\n"
            . "access_key_id = " . $this->config['r2_access_key'] . "\n"
            . "secret_access_key = " . $this->config['r2_secret_key'] . "\n"
            . "endpoint = " . $endpoint . "\n"
            . "region = " . $region . "\n"
            . "no_check_bucket = true\n"
            . "env_auth = false\n";
        
        if (file_put_contents($rcloneConfigFile, $rcloneConfigContent) === false) {
            throw new Exception("No se pudo crear archivo de config temporal para rclone: $rcloneConfigFile");
        }
        chmod($rcloneConfigFile, 0600); // Solo lectura para el propietario
        
        // Entorno mínimo para que rclone funcione desde cron (sin HOME ni PATH de usuario)
        $env = [
            'HOME'           => $rcloneTempDir,
            'PATH'           => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'TMPDIR'         => $rcloneTempDir,
            'RCLONE_CONFIG'  => $rcloneConfigFile,
        ];
        
        // Encontrar ruta de rclone - priorizar binario nativo sobre snap
        // El rclone snap tiene confinamiento y NO puede acceder a /var/www/
        $nativeBinary = __DIR__ . '/bin/rclone';
        $commonPaths = [
            $nativeBinary,          // Binario nativo en el módulo (sin confinamiento snap)
            '/usr/local/bin/rclone', // Instalación nativa del sistema
            '/usr/bin/rclone',       // Paquete apt
        ];
        
        $rclonePath = null;
        foreach ($commonPaths as $path) {
            if (file_exists($path) && is_executable($path)) {
                $rclonePath = $path;
                break;
            }
        }
        
        // Fallback: usar 'which' pero solo si NO apunta a snap (snap tiene confinamiento)
        if (empty($rclonePath)) {
            $whichPath = trim(shell_exec('which rclone 2>/dev/null'));
            if (!empty($whichPath) && file_exists($whichPath) && strpos($whichPath, '/snap/') === false) {
                $rclonePath = $whichPath;
            }
        }
        
        if (empty($rclonePath)) {
            throw new Exception("rclone nativo no encontrado. El binario nativo está en: $nativeBinary. Verifica permisos de ejecución.");
        }
        
        error_log("[R2_QUEUE][RCLONE] Usando rclone: $rclonePath");
        
        // Archivo de log temporal: guardaremos SOLO aquí las estadísticas/logs de rclone
        // (así el formato es consistente cuando PHP está ejecutándose sin TTY).
        $rcloneLogFile = $rcloneTempDir . '/rclone_log_' . uniqid() . '.log';
        
        // Comando rclone SIN redirecciones shell: stdout/stderr a /dev/null y rclone escribe a --log-file
        $cmd = sprintf(
            '%s copy %s %s --config %s --transfers 20 --checkers 10 --s3-upload-concurrency 8 --s3-chunk-size 16M --buffer-size 64M --no-traverse --s3-no-check-bucket --stats 3s --stats-one-line --log-file %s --log-level INFO --stats-log-level INFO',
            escapeshellarg($rclonePath),
            escapeshellarg($sourceDir),
            escapeshellarg($r2Remote),
            escapeshellarg($rcloneConfigFile),
            escapeshellarg($rcloneLogFile)
        );
        
        error_log("[R2_QUEUE][RCLONE] Ejecutando: $cmd");
        error_log("[R2_QUEUE][RCLONE] Log: $rcloneLogFile");
        
        // stdout/stderr a /dev/null: rclone escribe estadísticas/logs en $rcloneLogFile vía --log-file
        $descriptorspec = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        
        $process = proc_open($cmd, $descriptorspec, $pipes, null, $env);
        
        if (!is_resource($process)) {
            @unlink($rcloneConfigFile);
            throw new Exception("No se pudo iniciar proceso rclone");
        }
        
        $lastUpdateTime = microtime(true);
        $lastReadPos    = 0; // legado (ya no dependemos del crecimiento del log)
        $exitCode  = -1; // Valor por defecto; se sobreescribe con proc_get_status cuando termina
        $speedMin  = null;
        $speedMax  = null;
        $speedSum  = 0.0;
        $speedCount = 0;
        $output    = '';
        $parseBuffer = ''; // Buffer para parsear stats aunque vengan sin "\n"
        
        // Monitorear el log file mientras rclone corre (polling cada 2 segundos)
        // Importante: rclone puede actualizar stats en el mismo renglón usando '\r' (sin aumentar el tamaño).
        // Por eso NO dependemos de "filesize > lastReadPos"; tomamos siempre el último fragmento del log.
        while (true) {
            sleep(2);
            
            if (!file_exists($rcloneLogFile)) continue;
            
            clearstatcache(true, $rcloneLogFile);
            $statSize = @filesize($rcloneLogFile);
            if ($statSize === false || $statSize <= 0) continue;
            
            $readStart = max(0, $statSize - 32768); // último ~32KB
            $chunk = @file_get_contents($rcloneLogFile, false, null, $readStart);
            if ($chunk === false || $chunk === '') continue;
            
            // Normalizamos "\r" a "\n" para que regex sea consistente
            $parseBuffer = str_replace("\r", "\n", $chunk);
            
            // Parsear stats tomando el "último match" encontrado en el buffer.
            // Variante 1: con "Transferred:"
            $transferredBytes = null;
            $speedMBs = 0.0;

            $pattern1 = '/Transferred:\s*([\d.]+)\s*([A-Za-z]+)\s*\/\s*([\d.]+)\s*([A-Za-z]+).*?,' .
                         '\s*([\d.]+)\s*([A-Za-z]+)\/s/i';
            $m1all = [];
            if (preg_match_all($pattern1, $parseBuffer, $m1all, PREG_SET_ORDER)) {
                $last = end($m1all);
                $transferredBytes = $this->parseSizeToBytes($last[1], $last[2]);
                $speedBytesPerSec = $this->parseSizeToBytes($last[5], $last[6]);
                $speedMBs = $speedBytesPerSec / 1048576;
            } else {
                // Variante 2: sin "Transferred:" (formato típico en logs)
                $pattern2 = '/([0-9.]+)\s*([A-Za-z]+)\s*\/\s*([0-9.]+)\s*([A-Za-z]+)\s*,\s*[0-9.]+%\s*,' .
                            '\s*([0-9.]+)\s*([A-Za-z]+)\/s/i';
                $m2all = [];
                if (preg_match_all($pattern2, $parseBuffer, $m2all, PREG_SET_ORDER)) {
                    $last = end($m2all);
                    $transferredBytes = $this->parseSizeToBytes($last[1], $last[2]);
                    // $pattern2 captura:
                    //  [1]=transferred_value [2]=transferred_unit
                    //  [3]=total_value       [4]=total_unit
                    //  [5]=speed_value       [6]=speed_unit
                    $speedBytesPerSec = $this->parseSizeToBytes($last[5], $last[6]);
                    $speedMBs = $speedBytesPerSec / 1048576;
                }
            }

            if ($transferredBytes !== null) {
                // Actualizar min/max/avg si el speed es > 0.
                if ($speedMBs > 0) {
                    $speedMin = ($speedMin === null) ? $speedMBs : min($speedMin, $speedMBs);
                    $speedMax = ($speedMax === null) ? $speedMBs : max($speedMax, $speedMBs);
                    $speedSum += $speedMBs;
                    $speedCount++;
                }
                $speedAvg = $speedCount > 0 ? $speedSum / $speedCount : 0.0;

                // Actualizar progreso en BD (cada ~2s como máximo)
                $now = microtime(true);
                if ($queueId && ($now - $lastUpdateTime >= 2.0)) {
                                // Evitar inconsistencias de unidades/redondeos: el UI no debería mostrar bytes parciales > total.
                                $bytesForDb = ($totalSize > 0)
                                    ? min($transferredBytes, $totalSize)
                                    : $transferredBytes;

                    $filesEst = ($totalSize > 0 && $totalFiles > 0)
                                    ? (int)round($bytesForDb / ($totalSize / $totalFiles))
                        : 0;
                    error_log(sprintf(
                        "[R2_QUEUE][RCLONE][STATS] queue_id=%s uploaded=%.2fMB/%s speed=%.2fMB/s",
                        (string)$queueId,
                                    $bytesForDb / 1048576,
                        $totalSize > 0 ? round($totalSize / 1048576, 2) . "MB" : "?",
                        $speedMBs
                    ));
                    $this->updateQueueProgress(
                        $queueId,
                        min($filesEst, $totalFiles),
                        $totalFiles,
                                    $bytesForDb,
                                    $totalSize > 0 ? $totalSize : $bytesForDb,
                        $speedMBs,
                        $speedMin ?? 0,
                        $speedMax ?? 0,
                        $speedAvg,
                        null,
                        null
                    );
                    $lastUpdateTime = $now;
                }
            }
            
            // Verificar si rclone terminó
            $status = proc_get_status($process);
            if (!$status['running']) {
                // Capturar exit code AHORA (proc_get_status solo lo tiene disponible una vez)
                // proc_close() en este sistema siempre devuelve -1, NO usar su valor
                $exitCode = (int)$status['exitcode'];
                
                // Leer todo el log restante para diagnóstico
                if (file_exists($rcloneLogFile)) {
                    $remaining = @file_get_contents($rcloneLogFile);
                    if ($remaining) {
                        $output .= $remaining;
                        // Solo logueamos las últimas líneas; el progreso final se setea después con $totalSize/$totalFiles.
                        $remainingNorm = str_replace("\r", "\n", $remaining);
                        $lines = explode("\n", $remainingNorm);
                        $tail = array_slice($lines, max(0, count($lines) - 50));
                        foreach ($tail as $line) {
                            $line = trim($line);
                            if ($line !== '') error_log("[R2_QUEUE][RCLONE] " . $line);
                        }
                    }
                }
                break;
            }
        }
        
        proc_close($process); // Solo para liberar recursos, ignorar su valor de retorno
        
        // Limpiar archivos temporales
        if (file_exists($rcloneConfigFile)) @unlink($rcloneConfigFile);
        if (file_exists($rcloneLogFile))   @unlink($rcloneLogFile);
        
        if ($exitCode !== 0) {
            error_log("[R2_QUEUE][RCLONE] rclone falló con exit code $exitCode");
            error_log("[R2_QUEUE][RCLONE] Output completo: " . substr($output, 0, 3000));
            throw new Exception("rclone falló con código $exitCode: " . substr($output, 0, 800));
        }
        
        // Parsear resultado final de rclone
        $filesUploaded = $totalFiles; // Si exit code=0, todos subieron
        $bytesUploaded = $totalSize;
        
        // Extraer conteo de archivos transferidos del output final
        if (preg_match('/Transferred:\s*(\d+)\s*\/\s*(\d+)/i', $output, $finalMatches)) {
            $filesUploaded = (int)$finalMatches[1];
        }
        // Extraer bytes transferidos del output final
        if (preg_match('/Transferred:\s*([\d.]+)\s*(\w+)\s*\/\s*([\d.]+)\s*(\w+)/i', $output, $sizeMatches)) {
            $bytesUploaded = $this->parseSizeToBytes($sizeMatches[1], $sizeMatches[2]);
        }
        
        error_log("[R2_QUEUE][RCLONE] Upload completado: $filesUploaded archivos, " . round($bytesUploaded / 1048576, 2) . " MB");
        error_log("[R2_QUEUE][RCLONE] Velocidades: min=" . round($speedMin ?? 0, 2) . " max=" . round($speedMax ?? 0, 2) . " avg=" . round($speedSum > 0 ? $speedSum / max($speedCount, 1) : 0, 2) . " MB/s");
        
        return [
            'files_uploaded' => $filesUploaded,
            'bytes_uploaded' => $bytesUploaded
        ];
    }
    
    /**
     * Convierte tamaño con unidad a bytes
     * 
     * @param float $value Valor numérico
     * @param string $unit Unidad (B, KB, MB, GB, etc.)
     * @return int Bytes
     */
    private function parseSizeToBytes($value, $unit) {
        $value = (float)$value;
        $unit = strtoupper(trim($unit));
        
        // Soportar unidades de rclone: B, KiB, MiB, GiB, TiB, KB, MB, GB, TB
        // También: KBYTE, MBYTE, GBYTE (rclone --stats-one-line puede usar estas)
        $multipliers = [
            'B'     => 1,
            // rclone suele usar sufijos IEC ("KiB","MiB") pero en algunos entornos puede usar "MB"/"GB".
            // Interpretamos:
            // - IEC: KiB/MiB/GiB/TiB => 1024^n
            // - Decimal: K/KB/M/MB/G/GB/T/TB => 1000^n
            'K'     => 1000,
            'KB'    => 1000,
            'KIB'   => 1024,
            'KBYTE' => 1024,
            'KBYTES'=> 1024,
            'M'     => 1000 * 1000,
            'MB'    => 1000 * 1000,
            'MIB'   => 1024 * 1024,
            'MBYTE' => 1024 * 1024,
            'MBYTES'=> 1024 * 1024,
            'G'     => 1000 * 1000 * 1000,
            'GB'    => 1000 * 1000 * 1000,
            'GIB'   => 1024 * 1024 * 1024,
            'GBYTE' => 1024 * 1024 * 1024,
            'GBYTES'=> 1024 * 1024 * 1024,
            'T'     => 1000 * 1000 * 1000 * 1000,
            'TB'    => 1000 * 1000 * 1000 * 1000,
            'TIB'   => 1024 * 1024 * 1024 * 1024,
        ];
        
        $multiplier = $multipliers[$unit] ?? 1;
        return (int)($value * $multiplier);
    }

    /**
     * Ruta al binario dcmdump (DCMTK). Cache estática por request.
     */
    private static $dcmdumpBinaryCache = null;

    private function resolveDcmdumpBinary(): ?string {
        if (self::$dcmdumpBinaryCache !== null) {
            return self::$dcmdumpBinaryCache === '' ? null : self::$dcmdumpBinaryCache;
        }
        $candidates = [
            '/usr/bin/dcmdump',
            '/usr/local/bin/dcmdump',
            trim((string) shell_exec('command -v dcmdump 2>/dev/null')),
        ];
        foreach ($candidates as $p) {
            if ($p !== '' && is_file($p) && is_executable($p)) {
                self::$dcmdumpBinaryCache = $p;
                return $p;
            }
        }
        self::$dcmdumpBinaryCache = '';
        error_log('[R2_QUEUE][DCMTK] dcmdump no encontrado en PATH ni en rutas típicas');
        return null;
    }

    /**
     * Lee SOPInstanceUID del DICOM local usando DCMTK (sin decodificar píxeles).
     * Devuelve null si no se pudo leer o si el comando falla.
     *
     * Nota: en DCMTK 3.6.x la opción silenciosa es -q / --quiet, NO +q (inválida).
     * +u tampoco existe en dcmdump; eso hacía fallar el comando silenciado con 2>/dev/null.
     */
    private function getSopInstanceUidFromDicomWithDcmtk(string $filePath): ?string {
        $dcmdump = $this->resolveDcmdumpBinary();
        if ($dcmdump === null || !is_readable($filePath)) {
            return null;
        }

        // -q: sin warnings; +E: intentar leer aunque haya errores menores; +P: solo tag SOP Instance UID
        $cmd = sprintf(
            '%s -q +E +P 0008,0018 %s 2>/dev/null',
            escapeshellarg($dcmdump),
            escapeshellarg($filePath)
        );

        $output = shell_exec($cmd);
        if (!is_string($output) || trim($output) === '') {
            return null;
        }

        // Salida típica: (0008,0018) UI [1.2.840...]  # ...
        if (preg_match('/\bUI\s*\[\s*([0-9.]+)\s*\]/', $output, $m)) {
            return trim((string) $m[1]);
        }

        // Respaldo: primer UID estilo OID
        if (preg_match('/\b([0-9]+(?:\.[0-9]+)+)\b/', $output, $m)) {
            return trim((string) $m[1]);
        }

        return null;
    }
    
    /**
     * Lanza un pool de workers PHP persistentes para subir archivos
     * 
     * @param array $filesQueue Array de archivos con ['id', 'status', 'local_path', 'r2_key']
     * @param string $studyInstanceUid StudyInstanceUID
     * @param int|null $queueId ID de la cola
     * @param string $serverIp IP local
     * @param string $serverIpWan IP WAN
     * @return array Resultados del upload
     */
    private function launchPersistentWorkerPool($filesQueue, $studyInstanceUid, $queueId, $serverIp, $serverIpWan) {
        $totalFiles = count($filesQueue);
        $startTime = microtime(true);
        
        // Crear archivo de cola JSON
        $queueFile = sys_get_temp_dir() . '/r2_queue_' . $studyInstanceUid . '_' . uniqid() . '.json';
        $queueJson = json_encode($filesQueue, JSON_PRETTY_PRINT);
        
        if (file_put_contents($queueFile, $queueJson) === false) {
            throw new Exception("No se pudo crear archivo de cola: $queueFile");
        }
        
        // Verificar que los archivos en la cola existen
        $missingInQueue = [];
        foreach ($filesQueue as $file) {
            if (!file_exists($file['local_path'])) {
                $missingInQueue[] = $file['local_path'];
            } elseif (!is_readable($file['local_path'])) {
                error_log("[R2_QUEUE][WORKER_POOL] ⚠️ Archivo en cola no legible: " . $file['local_path']);
            }
        }
        
        if (count($missingInQueue) > 0) {
            error_log("[R2_QUEUE][WORKER_POOL] ⚠️ Archivos faltantes en cola: " . count($missingInQueue) . " de " . count($filesQueue));
        }
        
        error_log("[R2_QUEUE][WORKER_POOL] Cola creada: $queueFile con " . count($filesQueue) . " archivos (tamaño JSON: " . strlen($queueJson) . " bytes)");
        
        // Variables para rastrear velocidades
        $lastUpdateTime = $startTime;
        $lastUpdateBytes = 0;
        $speedSamples = [];
        $speedMin = null;
        $speedMax = null;
        $totalBytesUploaded = 0;
        
        // Lanzar N workers persistentes
        $concurrency = $this->maxConcurrency;
        $processes = [];
        // R2QueueProcessor.php está en /var/www/tjsiddse/modules/cloud-storage/
        // worker_upload.php está en /var/www/tjsiddse/modules/cloud-storage/workers/
        $workerScript = realpath(__DIR__ . '/workers/worker_upload.php');
        
        if (!$workerScript || !file_exists($workerScript)) {
            $attemptedPath = __DIR__ . '/workers/worker_upload.php';
            throw new Exception("Worker script no encontrado: $attemptedPath (resuelto: " . ($workerScript ?: 'null') . ")");
        }
        
        // Obtener directorio base del proyecto para ejecutar desde ahí
        $projectRoot = realpath(__DIR__ . '/../../');
        
        for ($w = 0; $w < $concurrency; $w++) {
            $resultFile = sys_get_temp_dir() . '/r2_worker_' . $studyInstanceUid . '_' . $w . '_' . uniqid() . '.json';
            
            // Ejecutar desde el directorio raíz del proyecto
            $cmd = sprintf(
                'cd %s && php %s %s %s %s',
                escapeshellarg($projectRoot),
                escapeshellarg($workerScript),
                escapeshellarg($queueFile),
                escapeshellarg("worker_$w"),
                escapeshellarg($resultFile)
            );
            
            error_log("[R2_QUEUE][WORKER_POOL] Comando worker $w: $cmd");
            
            // Redirigir stderr a un archivo de log para cada worker (para debugging)
            $workerLogFile = sys_get_temp_dir() . '/r2_worker_' . $studyInstanceUid . '_' . $w . '_' . uniqid() . '.log';
            
            $descriptorspec = [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', $workerLogFile, 'a']  // stderr al archivo de log
            ];
            
            // Crear archivo de log antes de iniciar el proceso
            if (!file_exists($workerLogFile)) {
                touch($workerLogFile);
                chmod($workerLogFile, 0644);
            }
            
            $process = proc_open($cmd, $descriptorspec, $pipes, $projectRoot, null);
            
            if (is_resource($process)) {
                $processes[$w] = [
                    'proc'        => $process,
                    'result_file' => $resultFile,
                    'log_file'    => $workerLogFile,
                    'pipes'       => $pipes,
                    'cmd'         => $cmd  // Guardar comando para debugging
                ];
                error_log("[R2_QUEUE][WORKER_POOL] Worker $w iniciado (log: $workerLogFile, result: $resultFile)");
                
                // Verificar inmediatamente si el proceso sigue corriendo
                usleep(100000); // 100ms
                $status = proc_get_status($process);
                if (!$status['running']) {
                    error_log("[R2_QUEUE][WORKER_POOL] ⚠️ Worker $w terminó inmediatamente (exit code: " . ($status['exitcode'] ?? 'N/A') . ")");
                    // Leer log si existe
                    if (file_exists($workerLogFile)) {
                        $logContent = file_get_contents($workerLogFile);
                        if ($logContent) {
                            error_log("[R2_QUEUE][WORKER_POOL] Worker $w log:\n" . $logContent);
                        } else {
                            error_log("[R2_QUEUE][WORKER_POOL] Worker $w log vacío");
                        }
                    } else {
                        error_log("[R2_QUEUE][WORKER_POOL] Worker $w log no existe: $workerLogFile");
                    }
                }
            } else {
                error_log("[R2_QUEUE][WORKER_POOL] ❌ Error iniciando worker $w (proc_open retornó false)");
                error_log("[R2_QUEUE][WORKER_POOL] Comando: $cmd");
                error_log("[R2_QUEUE][WORKER_POOL] Script existe: " . (file_exists($workerScript) ? 'Sí' : 'No'));
                error_log("[R2_QUEUE][WORKER_POOL] Project root: $projectRoot");
            }
        }
        
        // Monitorear progreso y esperar a que todos terminen
        while (true) {
            // VERIFICAR SI EL JOB FUE CANCELADO
            if ($queueId && $this->isJobCancelled($queueId)) {
                error_log("[R2_QUEUE] Job $queueId cancelado. Deteniendo workers.");
                
                // Matar todos los procesos activos
                foreach ($processes as $w => $p) {
                    if (is_resource($p['proc'])) {
                        proc_terminate($p['proc'], SIGTERM);
                        usleep(100000);
                        if (proc_get_status($p['proc'])['running']) {
                            proc_terminate($p['proc'], SIGKILL);
                        }
                        proc_close($p['proc']);
                    }
                }
                
                @unlink($queueFile);
                throw new Exception("Job cancelado por el usuario");
            }
            
            // Verificar si todos los procesos terminaron
            $allDone = true;
            foreach ($processes as $w => $p) {
                if (is_resource($p['proc'])) {
                    $status = proc_get_status($p['proc']);
                    if ($status['running']) {
                        $allDone = false;
                    }
                }
            }
            
            // Actualizar progreso desde la cola
            $this->updateProgressFromQueue($queueFile, $queueId, $serverIp, $serverIpWan, $startTime, $lastUpdateTime, $lastUpdateBytes, $speedSamples, $speedMin, $speedMax, $totalBytesUploaded);
            
            if ($allDone) {
                break;
            }
            
            usleep(500000); // 500ms
        }
        
        // Consolidar resultados de todos los workers
        $allResults = [];
        foreach ($processes as $w => $p) {
            if (is_resource($p['proc'])) {
                $status = proc_get_status($p['proc']);
                proc_close($p['proc']);
                error_log("[R2_QUEUE][WORKER_POOL] Worker $w terminado (exit code: " . ($status['exitcode'] ?? 'N/A') . ")");
            }
            
            // Leer log del worker si existe
            if (isset($p['log_file']) && file_exists($p['log_file'])) {
                $logContent = file_get_contents($p['log_file']);
                if ($logContent) {
                    error_log("[R2_QUEUE][WORKER_POOL] Worker $w log:\n" . $logContent);
                }
                @unlink($p['log_file']);
            }
            
            if (file_exists($p['result_file'])) {
                $content = file_get_contents($p['result_file']);
                if ($content) {
                    $workerResults = json_decode($content, true);
                    if (is_array($workerResults)) {
                        error_log("[R2_QUEUE][WORKER_POOL] Worker $w retornó " . count($workerResults) . " resultados");
                        $allResults = array_merge($allResults, $workerResults);
                    } else {
                        error_log("[R2_QUEUE][WORKER_POOL] Worker $w: JSON inválido en resultado: " . substr($content, 0, 200));
                    }
                } else {
                    error_log("[R2_QUEUE][WORKER_POOL] Worker $w: archivo de resultado vacío");
                }
                @unlink($p['result_file']);
            } else {
                error_log("[R2_QUEUE][WORKER_POOL] Worker $w: archivo de resultado no existe: " . $p['result_file']);
            }
        }
        
        error_log("[R2_QUEUE][WORKER_POOL] Total resultados consolidados: " . count($allResults));
        
        // Limpiar archivo de cola
        @unlink($queueFile);
        @unlink($queueFile . '.lock');
        
        return $allResults;
    }
    
    /**
     * Actualiza el progreso desde el archivo de cola JSON
     */
    private function updateProgressFromQueue($queueFile, $queueId, $serverIp, $serverIpWan, $startTime, &$lastUpdateTime, &$lastUpdateBytes, &$speedSamples, &$speedMin, &$speedMax, &$totalBytesUploaded) {
        if (!$queueId || !file_exists($queueFile)) {
            return;
        }
        
        $queue = json_decode(file_get_contents($queueFile), true) ?? [];
        $doneCount = 0;
        $totalBytes = 0;
        
        foreach ($queue as $item) {
            if (isset($item['status']) && $item['status'] === 'done') {
                $doneCount++;
                // Estimar bytes basado en archivos completados (aproximado)
                if (isset($item['local_path']) && file_exists($item['local_path'])) {
                    $totalBytes += filesize($item['local_path']);
                }
            }
        }
        
        $totalBytesUploaded = $totalBytes;
        $currentTime = microtime(true);
        $timeSinceLastUpdate = $currentTime - $lastUpdateTime;
        $bytesSinceLastUpdate = $totalBytesUploaded - $lastUpdateBytes;
        
        if ($timeSinceLastUpdate >= 1.0 && $bytesSinceLastUpdate > 0) {
            $instantSpeed = ($bytesSinceLastUpdate / 1024 / 1024) / $timeSinceLastUpdate;
            
            if ($instantSpeed > 0 && $instantSpeed < 1000) {
                if ($speedMin === null || $instantSpeed < $speedMin) {
                    $speedMin = $instantSpeed;
                }
                if ($speedMax === null || $instantSpeed > $speedMax) {
                    $speedMax = $instantSpeed;
                }
                $speedSamples[] = $instantSpeed;
            }
            
            $lastUpdateTime = $currentTime;
            $lastUpdateBytes = $totalBytesUploaded;
        }
        
        $elapsedTime = $currentTime - $startTime;
        $avgSpeed = ($elapsedTime > 0 && $totalBytesUploaded > 0) 
            ? ($totalBytesUploaded / 1024 / 1024) / $elapsedTime 
            : 0;
        
        $currentSpeedMbps = count($speedSamples) > 0 ? end($speedSamples) : $avgSpeed;
        
        // Actualizar progreso cada segundo
        $this->updateQueueProgress(
            $queueId,
            $doneCount,
            count($queue),
            $totalBytesUploaded,
            null,
            $currentSpeedMbps,
            $speedMin ?? $avgSpeed,
            $speedMax ?? $avgSpeed,
            $avgSpeed,
            $serverIp,
            $serverIpWan
        );
    }
    
    /**
     * Elimina un directorio recursivamente
     */
    private function deleteDirectory($dir) {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
    
    /**
     * Pre-descarga todas las instancias de un estudio a disco
     * 
     * @param string $orthancStudyId ID del estudio en Orthanc
     * @param string $tempDir Directorio temporal donde guardar
     * @param string $studyInstanceUid StudyInstanceUID
     * @param int|null $queueId ID de la cola para actualizar progreso
     * @return array Array de instancias con rutas de archivos
     */
    private function downloadAllInstancesToDisk($orthancStudyId, $tempDir, $studyInstanceUid, $queueId = null) {
        // 1. Obtener todas las instancias
        $instances = $this->getInstancesForStudy($orthancStudyId);
        $instancesToUpload = [];
        $totalInstances = count($instances);
        $downloaded = 0;
        
        // 2. Descargar cada instancia a disco
        foreach ($instances as $instance) {
            $instanceId = $instance['ID'];
            $sopUid = $instance['MainDicomTags']['SOPInstanceUID'] ?? null;
            $seriesId = $instance['ParentSeries'] ?? null;
            
            if (!$sopUid || !$seriesId) {
                continue;
            }
            
            // Obtener SeriesInstanceUID
            $seriesInfo = $this->getSeriesInfo($seriesId);
            $seriesInstanceUid = $seriesInfo['MainDicomTags']['SeriesInstanceUID'] ?? null;
            
            if (!$seriesInstanceUid) {
                continue;
            }
            
            // Obtener tamaño
            $instanceSize = isset($instance['FileSize']) && $instance['FileSize'] > 0 
                ? (int)$instance['FileSize'] 
                : 500 * 1024;
            
            // Crear estructura de directorios: temp/studies/{StudyUID}/series/{SeriesUID}/
            $seriesDir = $tempDir . '/series/' . $seriesInstanceUid;
            if (!is_dir($seriesDir)) {
                mkdir($seriesDir, 0755, true);
            }
            
            // Ruta del archivo destino
            $filePath = $seriesDir . '/' . $sopUid . '.dcm';
            
            // Descargar desde Orthanc directamente a archivo
            $this->downloadInstanceToFile($instanceId, $filePath);
            
            $instancesToUpload[] = [
                'instanceId' => $instanceId,
                'sopInstanceUid' => $sopUid,
                'studyInstanceUid' => $studyInstanceUid,
                'seriesInstanceUid' => $seriesInstanceUid,
                'size' => $instanceSize,
                'filePath' => $filePath
            ];
            
            $downloaded++;
            
            // Actualizar progreso cada 10 instancias
            if ($queueId && ($downloaded % 10 == 0 || $downloaded == $totalInstances)) {
                // Usar bytes_uploaded para mostrar progreso de descarga
                $bytesDownloaded = array_sum(array_column(array_slice($instancesToUpload, 0, $downloaded), 'size'));
                $this->updateQueueProgress(
                    $queueId,
                    $downloaded,
                    $totalInstances,
                    $bytesDownloaded,
                    null, // total_bytes se establecerá después
                    null, null, null, null, null, null
                );
            }
        }
        
        return $instancesToUpload;
    }
    
    /**
     * Descarga una instancia desde Orthanc directamente a un archivo
     * 
     * @param string $instanceId ID de la instancia en Orthanc
     * @param string $filePath Ruta del archivo destino
     */
    private function downloadInstanceToFile($instanceId, $filePath) {
        require_once __DIR__ . '/../../api/config/orthanc_config.php';
        $orthancUrl = OrthancConfig::getServerUrl();
        $credentials = OrthancConfig::getCredentials();
        
        $orthancEndpoint = $orthancUrl . '/instances/' . $instanceId . '/file';
        
        $ch = curl_init($orthancEndpoint);
        $fp = fopen($filePath, 'w');
        
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $credentials['username'] . ':' . $credentials['password']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        
        // HTTP/2 y keep-alive
        if (defined('CURL_HTTP_VERSION_2_0')) {
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
        }
        curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
        curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 60);
        
        $curlSuccess = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        curl_close($ch);
        fclose($fp);
        
        if (!$curlSuccess || $httpCode !== 200) {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            throw new Exception("Error descargando instancia desde Orthanc: HTTP $httpCode - $curlError");
        }
    }
    
    /**
     * Sube instancias desde disco a R2 en PARALELO usando procesos PHP
     * Mantiene N procesos activos simultáneamente (Worker Pool Pattern)
     * 
     * @param array $instancesToUpload Array de instancias con filePath
     * @param string $tempDir Directorio temporal base
     * @param string $studyInstanceUid StudyInstanceUID
     * @param int|null $queueId ID de la cola
     * @param string $serverIp IP local del servidor
     * @param string $serverIpWan IP WAN del servidor
     * @return array Resultados del upload
     */
    private function uploadInstancesFromDiskParallel($instancesToUpload, $tempDir, $studyInstanceUid, $queueId, $serverIp, $serverIpWan) {
        $totalInstances = count($instancesToUpload);
        $startTime = microtime(true);
        
        // Variables para rastrear velocidades
        $lastUpdateTime = $startTime;
        $lastUpdateBytes = 0;
        $speedSamples = [];
        $speedMin = null;
        $speedMax = null;
        $totalBytesUploaded = 0;
        $instancesUploaded = 0;
        
        $self = $this;
        $results = [];
        
        // Verificar si proc_open está disponible
        if (!function_exists('proc_open')) {
            error_log('[R2_QUEUE] proc_open no disponible, usando upload secuencial');
            return $this->uploadInstancesFromDiskSequential($instancesToUpload, $tempDir, $studyInstanceUid, $queueId, $serverIp, $serverIpWan);
        }
        
        // Worker Pool: mantener siempre maxConcurrency procesos activos
        $maxConcurrency = $this->maxConcurrency;
        $activeProcesses = []; // [instanceIndex => ['process' => resource, 'pipes' => array, 'instance' => array, 'startTime' => float]]
        $nextInstanceIndex = 0;
        $completedResults = [];
        
        // Inicializar pool: empezar con maxConcurrency procesos
        while (count($activeProcesses) < $maxConcurrency && $nextInstanceIndex < $totalInstances) {
            $this->startUploadProcess($instancesToUpload[$nextInstanceIndex], $activeProcesses, $nextInstanceIndex);
            $nextInstanceIndex++;
        }
        
        // Worker Pool Loop: mantener siempre el pool lleno
        while (count($activeProcesses) > 0 || $nextInstanceIndex < $totalInstances) {
            // VERIFICAR SI EL JOB FUE CANCELADO
            if ($queueId && $this->isJobCancelled($queueId)) {
                error_log("[R2_QUEUE] Job $queueId cancelado. Deteniendo procesos activos.");
                
                // Matar todos los procesos activos
                foreach ($activeProcesses as $idx => $procData) {
                    if (is_resource($procData['process'])) {
                        proc_terminate($procData['process'], SIGTERM);
                        usleep(100000); // 100ms
                        if (proc_get_status($procData['process'])['running']) {
                            proc_terminate($procData['process'], SIGKILL);
                        }
                        proc_close($procData['process']);
                    }
                    // Limpiar archivos temporales
                    foreach (['scriptPath', 'resultPath', 'logPath'] as $key) {
                        if (!empty($procData[$key]) && file_exists($procData[$key])) {
                            @unlink($procData[$key]);
                        }
                    }
                }
                
                throw new Exception("Job cancelado por el usuario");
            }
            
            // Verificar procesos completados
            foreach ($activeProcesses as $idx => $procData) {
                $status = proc_get_status($procData['process']);
                
                if (!$status['running']) {
                    // Proceso completado — cerrar proceso
                    proc_close($procData['process']);
                    
                    // Leer resultado del proceso
                    $result = $this->readProcessResult($procData, $status['exitcode']);
                    $completedResults[] = $result;
                    $instancesUploaded++;
                    
                    // Acumular bytes
                    if ($result['success']) {
                        $totalBytesUploaded += $result['size'];
                    }
                    
                    // Calcular velocidad
                    $currentTime = microtime(true);
                    $timeSinceLastUpdate = $currentTime - $lastUpdateTime;
                    $bytesSinceLastUpdate = $totalBytesUploaded - $lastUpdateBytes;
                    
                    if ($timeSinceLastUpdate >= 1.0 && $bytesSinceLastUpdate > 0) {
                        $instantSpeed = ($bytesSinceLastUpdate / 1024 / 1024) / $timeSinceLastUpdate;
                        
                        if ($instantSpeed > 0 && $instantSpeed < 1000) {
                            if ($speedMin === null || $instantSpeed < $speedMin) {
                                $speedMin = $instantSpeed;
                            }
                            if ($speedMax === null || $instantSpeed > $speedMax) {
                                $speedMax = $instantSpeed;
                            }
                            $speedSamples[] = $instantSpeed;
                        }
                        
                        $lastUpdateTime = $currentTime;
                        $lastUpdateBytes = $totalBytesUploaded;
                    }
                    
                    $elapsedTime = $currentTime - $startTime;
                    $avgSpeed = ($elapsedTime > 0 && $totalBytesUploaded > 0) 
                        ? ($totalBytesUploaded / 1024 / 1024) / $elapsedTime 
                        : 0;
                    
                    $currentSpeedMbps = count($speedSamples) > 0 ? end($speedSamples) : $avgSpeed;
                    
                    // Actualizar progreso cada 5 instancias o al final
                    if ($queueId && (($instancesUploaded % 5 == 0) || $instancesUploaded == $totalInstances)) {
                        $self->updateQueueProgress(
                            $queueId,
                            $instancesUploaded,
                            $totalInstances,
                            $totalBytesUploaded,
                            null,
                            $currentSpeedMbps,
                            $speedMin ?? $avgSpeed,
                            $speedMax ?? $avgSpeed,
                            $avgSpeed,
                            $serverIp,
                            $serverIpWan
                        );
                    }
                    
                    // Remover proceso completado
                    unset($activeProcesses[$idx]);
                    
                    // Iniciar siguiente proceso inmediatamente (mantener pool lleno)
                    if ($nextInstanceIndex < $totalInstances) {
                        $this->startUploadProcess($instancesToUpload[$nextInstanceIndex], $activeProcesses, $nextInstanceIndex);
                        $nextInstanceIndex++;
                    }
                }
            }
            
            // Llenar pool si hay espacio
            while (count($activeProcesses) < $maxConcurrency && $nextInstanceIndex < $totalInstances) {
                $this->startUploadProcess($instancesToUpload[$nextInstanceIndex], $activeProcesses, $nextInstanceIndex);
                $nextInstanceIndex++;
            }
            
            // Pequeña pausa para no saturar CPU
            usleep(50000); // 50ms
        }
        
        return $completedResults;
    }
    
    /**
     * Inicia un proceso PHP para subir un archivo a R2
     * Usa un archivo de resultado (.result) en lugar de pipes para evitar problemas de buffer
     * 
     * @param array $instance Datos de la instancia con filePath
     * @param array &$activeProcesses Array de procesos activos (por referencia)
     * @param int $instanceIndex Índice de la instancia
     */
    private function startUploadProcess($instance, &$activeProcesses, $instanceIndex) {
        $r2Key = $this->r2Driver->getInstanceKey(
            $instance['studyInstanceUid'],
            $instance['seriesInstanceUid'],
            $instance['sopInstanceUid']
        );
        
        $filePath = $instance['filePath'];
        $fileSize = $instance['size'];
        
        // Crear script PHP temporal que suba el archivo
        $scriptBase = sys_get_temp_dir() . '/r2_upload_' . uniqid();
        $scriptPath = $scriptBase . '.php';
        $resultPath = $scriptBase . '.result';
        $logPath    = $scriptBase . '.log';
        
        $scriptContent = $this->generateUploadScript($r2Key, $filePath, $resultPath, $logPath);
        file_put_contents($scriptPath, $scriptContent);
        chmod($scriptPath, 0644);
        
        // Ejecutar script en proceso separado
        // Redirigir stdin/stdout/stderr a /dev/null para evitar bloqueos de buffer
        $descriptorspec = [
            0 => ['file', '/dev/null', 'r'],  // stdin de /dev/null
            1 => ['file', '/dev/null', 'w'],  // stdout a /dev/null (resultado va al archivo .result)
            2 => ['file', $logPath, 'a'],     // stderr al archivo de log
        ];
        
        $process = proc_open(
            'php ' . escapeshellarg($scriptPath),
            $descriptorspec,
            $pipes,
            null,
            null
        );
        
        if (is_resource($process)) {
            $activeProcesses[$instanceIndex] = [
                'process'    => $process,
                'instance'   => $instance,
                'startTime'  => microtime(true),
                'scriptPath' => $scriptPath,
                'resultPath' => $resultPath,
                'logPath'    => $logPath,
                'r2Key'      => $r2Key,
                'fileSize'   => $fileSize
            ];
        } else {
            error_log('[R2_QUEUE] Error iniciando proceso para: ' . $filePath);
            @unlink($scriptPath);
            @unlink($resultPath);
        }
    }
    
    /**
     * Genera un script PHP temporal que sube un archivo a R2
     * El resultado se escribe en $resultPath (JSON), errores en $logPath
     * 
     * @param string $r2Key     Key del objeto en R2
     * @param string $filePath  Ruta del archivo a subir
     * @param string $resultPath Ruta donde escribir el resultado JSON
     * @param string $logPath   Ruta donde escribir errores/logs
     * @return string Contenido del script PHP
     */
    private function generateUploadScript($r2Key, $filePath, $resultPath, $logPath) {
        // Obtener ruta base del proyecto (absoluta)
        $projectRoot = realpath(__DIR__ . '/../../');  // modules/cloud-storage -> project root = 2 levels up (cloud-storage -> modules -> root)
        if (!$projectRoot) {
            $projectRoot = dirname(dirname(__DIR__));
        }
        
        $filePathEscaped   = addslashes($filePath);
        $r2KeyEscaped      = addslashes($r2Key);
        $resultPathEscaped = addslashes($resultPath);
        $logPathEscaped    = addslashes($logPath);
        
        $script = <<<PHP
<?php
// Script temporal para subir archivo a R2
// Generado automáticamente por R2QueueProcessor
error_reporting(E_ALL);
ini_set('memory_limit', '256M');
ini_set('max_execution_time', '300');

\$resultPath = '{$resultPathEscaped}';
\$logPath    = '{$logPathEscaped}';

function writeResult(\$data) {
    global \$resultPath;
    file_put_contents(\$resultPath, json_encode(\$data));
}

function writeLog(\$msg) {
    global \$logPath;
    file_put_contents(\$logPath, date('[H:i:s] ') . \$msg . PHP_EOL, FILE_APPEND);
}

// Cambiar al directorio base del proyecto
chdir('{$projectRoot}');

// Cargar autoloader de Composer
require_once '{$projectRoot}/modules/cloud-storage/vendor/autoload.php';

// Cargar configuración (sin database.php)
require_once '{$projectRoot}/modules/cloud-storage/config/cloud_storage_config.php';

use Aws\\S3\\S3Client;
use Aws\\S3\\MultipartUploader;
use Aws\\Exception\\AwsException;

try {
    // Cargar configuración R2 (sólo desde .env para evitar conexión DB innecesaria)
    \$config = CloudStorageConfig::load();
    
    if (empty(\$config['r2_access_key']) || empty(\$config['r2_secret_key']) || empty(\$config['r2_account_id'])) {
        throw new Exception("Credenciales R2 incompletas");
    }
    
    // Inicializar cliente S3 para R2
    \$s3Client = new S3Client([
        'version'              => 'latest',
        'region'               => \$config['r2_region'] ?? 'auto',
        'endpoint'             => 'https://' . \$config['r2_account_id'] . '.r2.cloudflarestorage.com',
        'credentials'          => [
            'key'    => \$config['r2_access_key'],
            'secret' => \$config['r2_secret_key']
        ],
        'use_path_style_endpoint' => true,
        'http' => [
            'verify'  => (bool)(\$config['r2_verify_ssl'] ?? false),
            'timeout' => 120,
            'connect_timeout' => 10,
        ]
    ]);
    
    // Parámetros del upload
    \$r2Key     = '{$r2KeyEscaped}';
    \$filePath  = '{$filePathEscaped}';
    \$bucketName = \$config['r2_bucket_name'];
    
    if (!file_exists(\$filePath)) {
        throw new Exception("Archivo no existe: \$filePath");
    }
    
    \$fileSize = filesize(\$filePath);
    writeLog("Subiendo \$filePath (" . number_format(\$fileSize) . " bytes) → \$r2Key");
    
    // Usar MultipartUpload para archivos >5MB, putObject para archivos pequeños
    if (\$fileSize > 5 * 1024 * 1024) {
        // MultipartUpload para archivos grandes
        \$uploader = new MultipartUploader(\$s3Client, \$filePath, [
            'bucket'     => \$bucketName,
            'key'        => \$r2Key,
            'params'     => ['ContentType' => 'application/dicom'],
            'part_size'  => 5 * 1024 * 1024
        ]);
        \$uploader->upload();
    } else {
        // putObject simple para archivos pequeños
        \$fileContent = file_get_contents(\$filePath);
        if (\$fileContent === false) {
            throw new Exception("No se pudo leer el archivo: \$filePath");
        }
        \$s3Client->putObject([
            'Bucket'      => \$bucketName,
            'Key'         => \$r2Key,
            'Body'        => \$fileContent,
            'ContentType' => 'application/dicom'
        ]);
    }
    
    writeLog("OK: \$r2Key (\$fileSize bytes)");
    writeResult([
        'success' => true,
        'r2_key'  => \$r2Key,
        'size'    => \$fileSize,
        'error'   => null
    ]);
    
} catch (AwsException \$e) {
    \$errorMsg  = \$e->getAwsErrorMessage() ?: \$e->getMessage();
    \$errorCode = \$e->getAwsErrorCode() ?: 'AwsError';
    writeLog("AWS Error [\$errorCode]: \$errorMsg");
    writeResult([
        'success' => false,
        'r2_key'  => '{$r2KeyEscaped}',
        'size'    => 0,
        'error'   => "AWS Error [\$errorCode]: \$errorMsg"
    ]);
    exit(1);
} catch (Exception \$e) {
    writeLog("Error: " . \$e->getMessage());
    writeResult([
        'success' => false,
        'r2_key'  => '{$r2KeyEscaped}',
        'size'    => 0,
        'error'   => \$e->getMessage()
    ]);
    exit(1);
}
PHP;
        
        return $script;
    }
    
    /**
     * Lee el resultado de un proceso completado
     * 
     * @param array $procData Datos del proceso
     * @param int $exitCode Código de salida del proceso
     * @return array Resultado del upload
     */
    private function readProcessResult($procData, $exitCode) {
        $result = [
            'success' => false,
            'r2_key'  => $procData['r2Key'],
            'size'    => 0,
            'error'   => 'Unknown error'
        ];
        
        // Leer archivo de resultado (.result)
        $resultPath = $procData['resultPath'] ?? null;
        if ($resultPath && file_exists($resultPath)) {
            $content = file_get_contents($resultPath);
            if ($content) {
                $decoded = json_decode($content, true);
                if ($decoded && is_array($decoded)) {
                    $result = $decoded;
                } else {
                    $result['error'] = 'Invalid JSON in result file: ' . substr($content, 0, 200);
                    error_log('[R2_QUEUE] Archivo resultado con JSON inválido: ' . substr($content, 0, 500));
                }
            } else {
                // Archivo vacío o no leíble
                $result['error'] = "Result file empty (exit code: $exitCode)";
            }
            @unlink($resultPath);
        } else {
            $result['error'] = "No result file (exit code: $exitCode)";
        }
        
        // Leer archivo de log (.log) si hay error
        $logPath = $procData['logPath'] ?? null;
        if ($logPath && file_exists($logPath)) {
            $logContent = file_get_contents($logPath);
            if ($logContent) {
                if (!$result['success']) {
                    $result['error'] .= ' | log: ' . trim($logContent);
                }
                error_log('[R2_QUEUE] Script log [exit=' . $exitCode . ']: ' . trim($logContent));
            }
            @unlink($logPath);
        }
        
        // Si el exit code no es 0, marcar como error aunque no tengamos mensaje
        if ($exitCode !== 0 && $result['success']) {
            $result['success'] = false;
            $result['error']   = "Process exited with code $exitCode";
        }
        
        // Limpiar script temporal
        if (isset($procData['scriptPath']) && file_exists($procData['scriptPath'])) {
            @unlink($procData['scriptPath']);
        }
        
        return $result;
    }
    
    /**
     * Sube instancias desde disco a R2 de forma SECUENCIAL (fallback si proc_open no está disponible)
     * 
     * @param array $instancesToUpload Array de instancias con filePath
     * @param string $tempDir Directorio temporal base
     * @param string $studyInstanceUid StudyInstanceUID
     * @param int|null $queueId ID de la cola
     * @param string $serverIp IP local del servidor
     * @param string $serverIpWan IP WAN del servidor
     * @return array Resultados del upload
     */
    private function uploadInstancesFromDiskSequential($instancesToUpload, $tempDir, $studyInstanceUid, $queueId, $serverIp, $serverIpWan) {
        $totalInstances = count($instancesToUpload);
        $startTime = microtime(true);
        
        // Variables para rastrear velocidades
        $lastUpdateTime = $startTime;
        $lastUpdateBytes = 0;
        $speedSamples = [];
        $speedMin = null;
        $speedMax = null;
        $totalBytesUploaded = 0;
        $instancesUploaded = 0;
        
        $self = $this;
        $results = [];
        
        // Subir cada instancia usando putObjectFromFile (que ya tiene MultipartUpload para archivos >5MB)
        foreach ($instancesToUpload as $index => $instance) {
            $r2Key = $this->r2Driver->getInstanceKey(
                $instance['studyInstanceUid'],
                $instance['seriesInstanceUid'],
                $instance['sopInstanceUid']
            );
            
            $filePath = $instance['filePath'];
            $fileSize = $instance['size'];
            
            try {
                // putObjectFromFile ya tiene MultipartUpload para archivos >5MB
                $this->r2Driver->putObjectFromFile($r2Key, $filePath, 'application/dicom');
                
                $instancesUploaded++;
                $totalBytesUploaded += $fileSize;
                
                $results[] = [
                    'success' => true,
                    'r2_key' => $r2Key,
                    'size' => $fileSize,
                    'error' => null
                ];
                
                // Calcular velocidad y actualizar progreso cada 5 instancias
                $currentTime = microtime(true);
                $timeSinceLastUpdate = $currentTime - $lastUpdateTime;
                $bytesSinceLastUpdate = $totalBytesUploaded - $lastUpdateBytes;
                
                if ($timeSinceLastUpdate >= 1.0 && $bytesSinceLastUpdate > 0) {
                    $instantSpeed = ($bytesSinceLastUpdate / 1024 / 1024) / $timeSinceLastUpdate;
                    
                    if ($instantSpeed > 0 && $instantSpeed < 1000) {
                        if ($speedMin === null || $instantSpeed < $speedMin) {
                            $speedMin = $instantSpeed;
                        }
                        if ($speedMax === null || $instantSpeed > $speedMax) {
                            $speedMax = $instantSpeed;
                        }
                        $speedSamples[] = $instantSpeed;
                    }
                    
                    $lastUpdateTime = $currentTime;
                    $lastUpdateBytes = $totalBytesUploaded;
                }
                
                $elapsedTime = $currentTime - $startTime;
                $avgSpeed = ($elapsedTime > 0 && $totalBytesUploaded > 0) 
                    ? ($totalBytesUploaded / 1024 / 1024) / $elapsedTime 
                    : 0;
                
                $currentSpeedMbps = count($speedSamples) > 0 ? end($speedSamples) : $avgSpeed;
                
                // Actualizar progreso cada 5 instancias o al final
                if ($queueId && (($instancesUploaded % 5 == 0) || $instancesUploaded == $totalInstances)) {
                    $self->updateQueueProgress(
                        $queueId,
                        $instancesUploaded,
                        $totalInstances,
                        $totalBytesUploaded,
                        null, // total_bytes se mantiene desde la inicialización
                        $currentSpeedMbps,
                        $speedMin ?? $avgSpeed,
                        $speedMax ?? $avgSpeed,
                        $avgSpeed,
                        $serverIp,
                        $serverIpWan
                    );
                }
                
            } catch (Exception $e) {
                error_log('[R2_QUEUE] Error subiendo instancia desde disco: ' . $e->getMessage());
                $results[] = [
                    'success' => false,
                    'r2_key' => $r2Key,
                    'size' => 0,
                    'error' => 'Error subiendo a R2: ' . $e->getMessage()
                ];
            }
        }
        
        return $results;
    }
    
}
