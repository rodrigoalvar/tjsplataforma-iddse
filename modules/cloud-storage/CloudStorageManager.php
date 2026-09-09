<?php
/**
 * Gestor principal del módulo Cloud Storage
 * Sistema TJSMEDICAL - Cloud Storage Module
 */

require_once __DIR__ . '/config/cloud_storage_config.php';
require_once __DIR__ . '/drivers/R2StorageDriver.php';
require_once __DIR__ . '/../../config/database.php';

class CloudStorageManager {
    private $db;
    private $config;
    private $driver;

    /** @var array<string,bool>|null */
    private static $r2QueueColumns = null;
    /** @var array<string,bool>|null */
    private static $r2StudiesColumns = null;
    
    public function __construct() {
        $this->config = CloudStorageConfig::load();
        
        if (!($this->config['r2_enabled'] ?? false)) {
            throw new Exception('Cloud Storage R2 no está habilitado');
        }
        
        $database = new Database();
        $this->db = $database->getConnection();
        
        if (!$this->db) {
            throw new Exception('No se pudo conectar a la base de datos');
        }
        
        $this->driver = new R2StorageDriver($this->config);
    }

    private function r2QueueHasColumn(string $name): bool {
        if (self::$r2QueueColumns === null) {
            self::$r2QueueColumns = [];
            try {
                $s = $this->db->query('SHOW COLUMNS FROM r2_queue');
                while ($r = $s->fetch(PDO::FETCH_ASSOC)) {
                    if (!empty($r['Field'])) {
                        self::$r2QueueColumns[(string) $r['Field']] = true;
                    }
                }
            } catch (Exception $e) {
                error_log('[CLOUD_STORAGE] SHOW COLUMNS r2_queue: ' . $e->getMessage());
            }
        }
        return isset(self::$r2QueueColumns[$name]);
    }

    private function r2StudiesHasColumn(string $name): bool {
        if (self::$r2StudiesColumns === null) {
            self::$r2StudiesColumns = [];
            try {
                $s = $this->db->query('SHOW COLUMNS FROM r2_studies');
                while ($r = $s->fetch(PDO::FETCH_ASSOC)) {
                    if (!empty($r['Field'])) {
                        self::$r2StudiesColumns[(string) $r['Field']] = true;
                    }
                }
            } catch (Exception $e) {
                error_log('[CLOUD_STORAGE] SHOW COLUMNS r2_studies: ' . $e->getMessage());
            }
        }
        return isset(self::$r2StudiesColumns[$name]);
    }

    /**
     * Estados de cola que implican trabajo en curso (no encolar duplicado).
     */
    private static function isActiveQueueStatus(string $status): bool {
        $terminal = ['done', 'error', 'cancelled'];
        return !in_array($status, $terminal, true);
    }

    /**
     * Cuenta instancias en Orthanc para un estudio (GET /studies/{id}/instances).
     */
    private function countOrthancStudyInstances(string $orthancStudyId): ?int {
        $orthancConfig = __DIR__ . '/../../api/config/orthanc_config.php';
        if (!file_exists($orthancConfig)) {
            $orthancConfig = __DIR__ . '/../../../api/config/orthanc_config.php';
        }
        if (!file_exists($orthancConfig)) {
            return null;
        }
        require_once $orthancConfig;
        if (!class_exists('OrthancConfig')) {
            return null;
        }
        $baseUrl = OrthancConfig::getServerUrl();
        $credentials = OrthancConfig::getCredentials();

        $ch = curl_init(rtrim($baseUrl, '/') . '/studies/' . rawurlencode($orthancStudyId) . '/instances');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $credentials['username'] . ':' . $credentials['password']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !is_string($response)) {
            return null;
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return null;
        }
        return count($decoded);
    }

    private function setR2StudySyncPending(string $orthancStudyId): void {
        if (!$this->r2StudiesHasColumn('r2_sync_status')) {
            return;
        }
        try {
            $stmt = $this->db->prepare('
                UPDATE r2_studies SET r2_sync_status = \'pending\', updated_at = NOW()
                WHERE orthanc_study_id = ?
            ');
            $stmt->execute([$orthancStudyId]);
        } catch (Exception $e) {
            error_log('[CLOUD_STORAGE] r2_sync_status pending: ' . $e->getMessage());
        }
    }
    
    /**
     * Encola un estudio para subida a R2 (o re-sync si ya está online y hay más instancias en Orthanc).
     * Con job activo: marca needs_followup_sync / needs_resync en lugar de duplicar la cola.
     *
     * @param string $orthancStudyId ID del estudio en Orthanc
     * @return array{success:bool, queue_id?:int, message:string, resync?:bool, deferred?:bool}
     */
    public function enqueueStudy($orthancStudyId) {
        $hasIsResyncCol = $this->r2QueueHasColumn('is_resync');
        $hasFollowupCol = $this->r2QueueHasColumn('needs_followup_sync');
        $hasNeedsResyncCol = $this->r2StudiesHasColumn('needs_resync');

        try {
            $this->db->beginTransaction();

            $stmtStudy = $this->db->prepare('SELECT * FROM r2_studies WHERE orthanc_study_id = ? FOR UPDATE');
            $stmtStudy->execute([$orthancStudyId]);
            $r2Study = $stmtStudy->fetch(PDO::FETCH_ASSOC);

            $selectCols = 'id, status';
            if ($hasIsResyncCol) {
                $selectCols .= ', is_resync';
            }
            if ($hasFollowupCol) {
                $selectCols .= ', needs_followup_sync';
            }

            $stmt = $this->db->prepare("
                SELECT {$selectCols} FROM r2_queue
                WHERE orthanc_study_id = ?
                ORDER BY id DESC
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$orthancStudyId]);
            $latest = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($latest && self::isActiveQueueStatus((string) $latest['status'])) {
                if ($hasFollowupCol) {
                    $u = $this->db->prepare('
                        UPDATE r2_queue SET needs_followup_sync = 1, updated_at = NOW() WHERE id = ?
                    ');
                    $u->execute([(int) $latest['id']]);
                }
                $deferredStudy = false;
                if ($hasNeedsResyncCol && $r2Study && ($r2Study['r2_status'] ?? '') === 'online') {
                    $orthancN = $this->countOrthancStudyInstances($orthancStudyId);
                    $r2N = (int) ($r2Study['total_instances'] ?? 0);
                    if ($orthancN !== null && $orthancN > $r2N) {
                        $u2 = $this->db->prepare('
                            UPDATE r2_studies SET needs_resync = 1, resync_requested_at = NOW(), updated_at = NOW()
                            WHERE orthanc_study_id = ?
                        ');
                        $u2->execute([$orthancStudyId]);
                        $deferredStudy = true;
                    }
                }

                $this->db->commit();

                return [
                    'success' => true,
                    'queue_id' => (int) $latest['id'],
                    'message' => ($deferredStudy || $hasFollowupCol)
                        ? 'Trabajo en curso; la sincronización pendiente se aplicará al finalizar'
                        : 'Estudio ya está en cola',
                    'resync' => !empty($latest['is_resync']),
                    'deferred' => true,
                ];
            }

            if ($latest && ($latest['status'] ?? '') === 'done' && $r2Study && ($r2Study['r2_status'] ?? '') === 'online') {
                $orthancN = $this->countOrthancStudyInstances($orthancStudyId);
                $r2N = (int) ($r2Study['total_instances'] ?? 0);

                if ($orthancN !== null && $orthancN > $r2N) {
                    $uploadMethod = $this->config['r2_upload_method'] ?? 'instance';
                    if ($hasIsResyncCol && $hasFollowupCol) {
                        $ins = $this->db->prepare('
                            INSERT INTO r2_queue (orthanc_study_id, status, upload_method, needs_followup_sync, is_resync, created_at, updated_at)
                            VALUES (?, \'pending\', ?, 0, 1, NOW(), NOW())
                        ');
                        $ins->execute([$orthancStudyId, $uploadMethod]);
                    } elseif ($hasIsResyncCol) {
                        $ins = $this->db->prepare('
                            INSERT INTO r2_queue (orthanc_study_id, status, upload_method, is_resync, created_at, updated_at)
                            VALUES (?, \'pending\', ?, 1, NOW(), NOW())
                        ');
                        $ins->execute([$orthancStudyId, $uploadMethod]);
                    } else {
                        $ins = $this->db->prepare('
                            INSERT INTO r2_queue (orthanc_study_id, status, upload_method, created_at, updated_at)
                            VALUES (?, \'pending\', ?, NOW(), NOW())
                        ');
                        $ins->execute([$orthancStudyId, $uploadMethod]);
                    }
                    $queueId = (int) $this->db->lastInsertId();
                    $this->setR2StudySyncPending($orthancStudyId);
                    $this->db->commit();

                    return [
                        'success' => true,
                        'queue_id' => $queueId,
                        'message' => 'Re-sincronización encolada: hay más instancias en Orthanc que en R2',
                        'resync' => true,
                        'orthanc_instances' => $orthancN,
                        'r2_instances' => $r2N,
                    ];
                }

                $this->db->commit();

                return [
                    'success' => true,
                    'queue_id' => (int) $latest['id'],
                    'message' => 'Estudio ya está en R2',
                    'resync' => false,
                ];
            }

            $uploadMethod = $this->config['r2_upload_method'] ?? 'instance';

            if ($hasIsResyncCol && $hasFollowupCol) {
                $stmt = $this->db->prepare('
                    INSERT INTO r2_queue (orthanc_study_id, status, upload_method, needs_followup_sync, is_resync, created_at, updated_at)
                    VALUES (?, \'pending\', ?, 0, 0, NOW(), NOW())
                ');
                $stmt->execute([$orthancStudyId, $uploadMethod]);
            } elseif ($hasIsResyncCol) {
                $stmt = $this->db->prepare('
                    INSERT INTO r2_queue (orthanc_study_id, status, upload_method, is_resync, created_at, updated_at)
                    VALUES (?, \'pending\', ?, 0, NOW(), NOW())
                ');
                $stmt->execute([$orthancStudyId, $uploadMethod]);
            } else {
                $stmt = $this->db->prepare('
                    INSERT INTO r2_queue (orthanc_study_id, status, upload_method, created_at, updated_at)
                    VALUES (?, \'pending\', ?, NOW(), NOW())
                ');
                $stmt->execute([$orthancStudyId, $uploadMethod]);
            }

            $queueId = (int) $this->db->lastInsertId();
            $this->db->commit();

            return [
                'success' => true,
                'queue_id' => $queueId,
                'message' => 'Estudio encolado correctamente',
                'resync' => false,
            ];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[CLOUD_STORAGE] Error encolando estudio: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Obtiene el estado de un estudio en R2
     * @param string $orthancStudyId ID del estudio en Orthanc
     * @return array Estado del estudio
     */
    public function getStudyStatus($orthancStudyId) {
        try {
            $stmt = $this->db->prepare('
                SELECT * FROM r2_queue 
                WHERE orthanc_study_id = ?
                ORDER BY created_at DESC
                LIMIT 1
            ');
            $stmt->execute([$orthancStudyId]);
            $queueItem = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $stmt = $this->db->prepare('
                SELECT * FROM r2_studies 
                WHERE orthanc_study_id = ?
            ');
            $stmt->execute([$orthancStudyId]);
            $r2Study = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'orthanc_study_id' => $orthancStudyId,
                'queue_status' => $queueItem['status'] ?? 'none',
                'r2_status' => $r2Study['r2_status'] ?? 'none',
                'r2_sync_status' => $r2Study['r2_sync_status'] ?? 'idle',
                'r2_manifest_path' => $r2Study['r2_manifest_path'] ?? null,
                'total_instances' => $r2Study['total_instances'] ?? 0,
                'total_size_bytes' => $r2Study['total_size_bytes'] ?? 0,
                'uploaded_at' => $r2Study['uploaded_at'] ?? null,
                'last_error' => $queueItem['last_error'] ?? null,
            ];
            
        } catch (Exception $e) {
            error_log('[CLOUD_STORAGE] Error obteniendo estado: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Obtiene el driver de almacenamiento
     */
    public function getDriver() {
        return $this->driver;
    }
}
