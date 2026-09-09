<?php
/**
 * Study routing: enlaces de visor/descarga vía PACS local o R2 (manifest / ZIP).
 * Depende de CloudStorageConfig (misma BD/.env que cloud-storage).
 */

class StudyRoutingService
{
    public const PERM_USE = 'study_routing';
    public const PERM_GUI = 'gui_study_routing';
    public const PERM_MANAGE = 'study_routing_manage';

    /**
     * @param array<int|string> $userPermisos
     */
    public static function userHasStudyRoutingUse(array $userPermisos): bool
    {
        $p = array_map('strtolower', array_map('strval', $userPermisos));
        return in_array('all', $p, true) || in_array(self::PERM_USE, $p, true);
    }

    public static function getGlobalMode(PDO $db): string
    {
        $stmt = $db->prepare('SELECT valor FROM configuracion WHERE clave = ?');
        $stmt->execute(['study_routing_global_mode']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $v = strtolower(trim((string)($row['valor'] ?? 'local')));
        return in_array($v, ['local', 'r2'], true) ? $v : 'local';
    }

    /**
     * Modo efectivo: local | r2 (sin permiso study_routing → siempre local).
     *
     * @param array<int|string> $userPermisos
     */
    public static function getEffectiveMode(PDO $db, ?int $userId, array $userPermisos, string $userLevel = ''): string
    {
        if (!self::userHasStudyRoutingUse($userPermisos)) {
            return 'local';
        }
        $global = self::getGlobalMode($db);
        if ($userId === null || $userId <= 0) {
            return $global;
        }
        $override = self::getUserRoutingOverride($db, $userId);
        if ($override === 'local' || $override === 'r2') {
            return $override;
        }
        return $global;
    }

    public static function getUserRoutingOverride(PDO $db, int $userId): ?string
    {
        if (!self::usuariosHasStudyRoutingColumn($db)) {
            return null;
        }
        try {
            $stmt = $db->prepare('SELECT study_routing_mode FROM usuarios WHERE id = ? AND activo = 1');
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $m = strtolower(trim((string)($row['study_routing_mode'] ?? '')));
            if ($m === '' || $m === 'inherit' || $m === 'global') {
                return null;
            }
            if (in_array($m, ['local', 'r2'], true)) {
                return $m;
            }
        } catch (Exception $e) {
            error_log('[STUDY_ROUTING] getUserRoutingOverride: ' . $e->getMessage());
        }
        return null;
    }

    public static function usuariosHasStudyRoutingColumn(PDO $db): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        try {
            $stmt = $db->query("SHOW COLUMNS FROM usuarios LIKE 'study_routing_mode'");
            $cache = $stmt && $stmt->rowCount() > 0;
        } catch (Exception $e) {
            $cache = false;
        }
        return $cache;
    }

    public static function r2StudiesHasZipKeyColumn(PDO $db): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        try {
            $stmt = $db->query("SHOW COLUMNS FROM r2_studies LIKE 'r2_zip_key'");
            $cache = $stmt && $stmt->rowCount() > 0;
        } catch (Exception $e) {
            $cache = false;
        }
        return $cache;
    }

    /**
     * URL pública base del portal para manifest.php (sin barra final).
     */
    public static function getManifestPublicBaseUrl(): string
    {
        $base = '';
        try {
            if (!class_exists('CloudStorageConfig')) {
                $path = __DIR__ . '/../modules/cloud-storage/config/cloud_storage_config.php';
                if (file_exists($path)) {
                    require_once $path;
                }
            }
            if (class_exists('CloudStorageConfig')) {
                $cfg = CloudStorageConfig::load();
                $base = trim((string)($cfg['r2_public_portal_base_url'] ?? ''));
            }
        } catch (Exception $e) {
            error_log('[STUDY_ROUTING] getManifestPublicBaseUrl config: ' . $e->getMessage());
        }
        if ($base === '' && !empty($_SERVER['HTTP_HOST'])) {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
            $scheme = $https ? 'https' : 'http';
            $base = $scheme . '://' . $_SERVER['HTTP_HOST'];
        }
        return rtrim($base, '/');
    }

    public static function getManifestApiUrl(string $orthancStudyId): string
    {
        $base = self::getManifestPublicBaseUrl();
        $path = '/modules/cloud-storage/api/manifest.php?id=' . rawurlencode($orthancStudyId);
        return $base !== '' ? ($base . $path) : $path;
    }

    /**
     * @return array<string, mixed>
     */
    public static function batchLoadR2Context(PDO $db, array $orthancStudyIds): array
    {
        $orthancStudyIds = array_values(array_filter(array_unique(array_map('strval', $orthancStudyIds))));
        if ($orthancStudyIds === []) {
            return [];
        }
        $hasZipCol = self::r2StudiesHasZipKeyColumn($db);
        $zipSel = $hasZipCol ? 'r2_zip_key' : 'NULL AS r2_zip_key';
        $out = [];
        // Lotes pequeños evitan consultas enormes, timeouts de MySQL/PHP y 502 en días con muchos estudios
        $chunkSize = 300;
        foreach (array_chunk($orthancStudyIds, $chunkSize) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $sql = "SELECT orthanc_study_id, study_instance_uid, r2_status, r2_manifest_path, {$zipSel}
                    FROM r2_studies WHERE orthanc_study_id IN ($placeholders)";
            $stmt = $db->prepare($sql);
            $stmt->execute($chunk);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $out[$row['orthanc_study_id']] = $row;
            }
            $queueZip = self::batchLatestDoneQueue($db, $chunk);
            foreach ($chunk as $id) {
                if (!isset($out[$id])) {
                    $out[$id] = [
                        'orthanc_study_id' => $id,
                        'study_instance_uid' => '',
                        'r2_status' => 'none',
                        'r2_manifest_path' => null,
                        'r2_zip_key' => null,
                    ];
                }
                $out[$id]['_queue_done'] = $queueZip[$id] ?? null;
            }
        }
        return $out;
    }

    /**
     * @param array<int, string> $orthancStudyIds
     * @return array<string, array{zip_path:?string,upload_method:?string}>
     */
    private static function batchLatestDoneQueue(PDO $db, array $orthancStudyIds): array
    {
        $result = [];
        try {
            $db->query('SELECT zip_path, upload_method FROM r2_queue LIMIT 1');
        } catch (Exception $e) {
            return $result;
        }
        $placeholders = implode(',', array_fill(0, count($orthancStudyIds), '?'));
        $sql = "
            SELECT q.orthanc_study_id, q.zip_path, q.upload_method
            FROM r2_queue q
            INNER JOIN (
                SELECT orthanc_study_id, MAX(id) AS max_id
                FROM r2_queue
                WHERE status = 'done' AND orthanc_study_id IN ($placeholders)
                GROUP BY orthanc_study_id
            ) t ON q.id = t.max_id AND q.orthanc_study_id = t.orthanc_study_id
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($orthancStudyIds);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[$row['orthanc_study_id']] = [
                'zip_path' => $row['zip_path'] ?? null,
                'upload_method' => $row['upload_method'] ?? null,
            ];
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $r2row Fila de batchLoadR2Context
     * @return 'manifest'|'zip'|null
     */
    public static function detectDelivery(array $r2row): ?string
    {
        if (($r2row['r2_status'] ?? '') !== 'online') {
            return null;
        }
        $zipKey = trim((string)($r2row['r2_zip_key'] ?? ''));
        if ($zipKey !== '') {
            return 'zip';
        }
        $q = $r2row['_queue_done'] ?? null;
        if (is_array($q) && !empty($q['zip_path']) && ($q['upload_method'] ?? '') === 'zip') {
            return 'zip';
        }
        $mp = trim((string)($r2row['r2_manifest_path'] ?? ''));
        if ($mp !== '') {
            return 'manifest';
        }
        return null;
    }

    /**
     * @return array{viewer_url:string, download_url:string, study_routing:array}
     */
    public static function resolve(
        PDO $db,
        string $orthancStudyId,
        string $studyInstanceUid,
        string $localViewerUrl,
        string $localDownloadUrl,
        string $effectiveMode,
        array $r2row
    ): array {
        $meta = [
            'mode_requested' => $effectiveMode,
            'source_viewer' => 'local',
            'source_download' => 'local',
            'delivery' => null,
            'fallback' => null,
        ];

        $r2Enabled = false;
        try {
            if (!class_exists('CloudStorageConfig')) {
                require_once __DIR__ . '/../modules/cloud-storage/config/cloud_storage_config.php';
            }
            $r2Enabled = !empty(CloudStorageConfig::load()['r2_enabled']);
        } catch (Exception $e) {
            error_log('[STUDY_ROUTING] CloudStorageConfig: ' . $e->getMessage());
        }

        if ($effectiveMode !== 'r2' || !$r2Enabled) {
            return [
                'viewer_url' => $localViewerUrl,
                'download_url' => $localDownloadUrl,
                'study_routing' => $meta,
            ];
        }

        $delivery = self::detectDelivery($r2row);
        $meta['delivery'] = $delivery;

        if ($delivery === null) {
            $meta['fallback'] = 'study_not_online_in_r2';
            // No logear por estudio individual: con muchos estudios llena el buffer FastCGI y Nginx devuelve 502
            return [
                'viewer_url' => $localViewerUrl,
                'download_url' => $localDownloadUrl,
                'study_routing' => $meta,
            ];
        }

        // ZIP en R2: descarga presignada; visor sigue en PACS local (sin manifest)
        if ($delivery === 'zip') {
            $zipKey = trim((string)($r2row['r2_zip_key'] ?? ''));
            if ($zipKey === '') {
                $q = $r2row['_queue_done'] ?? [];
                $zipKey = trim((string)($q['zip_path'] ?? ''));
            }
            $meta['source_viewer'] = 'local';
            $meta['source_download'] = 'local';
            $downloadUrl = $localDownloadUrl;
            if ($zipKey !== '') {
                try {
                    require_once __DIR__ . '/../modules/cloud-storage/CloudStorageManager.php';
                    $manager = new CloudStorageManager();
                    $driver = $manager->getDriver();
                    $ttl = (int)(CloudStorageConfig::load()['r2_presigned_ttl'] ?? 600);
                    $downloadUrl = $driver->generatePresignedUrl($zipKey, $ttl);
                    $meta['source_download'] = 'r2_zip';
                } catch (Exception $e) {
                    $meta['fallback'] = 'r2_zip_presign_failed';
                    error_log('[STUDY_ROUTING] Fallback descarga local (presign ZIP): ' . $e->getMessage());
                }
            }
            return [
                'viewer_url' => $localViewerUrl,
                'download_url' => $downloadUrl,
                'study_routing' => $meta,
            ];
        }

        // Manifest: visor con URL del API manifest; descarga sigue siendo ZIP local Orthanc
        $manifestUrl = self::getManifestApiUrl($orthancStudyId);
        $param = 'manifestUrl';
        try {
            $stmt = $db->prepare('SELECT valor FROM configuracion WHERE clave = ?');
            $stmt->execute(['study_routing_manifest_query_param']);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $p = trim((string)($row['valor'] ?? ''));
            if ($p !== '') {
                $param = preg_replace('/[^a-zA-Z0-9_\-]/', '', $p) ?: 'manifestUrl';
            }
        } catch (Exception $e) {
            // ignore
        }

        $sep = strpos($localViewerUrl, '?') !== false ? '&' : '?';
        $viewerUrl = $localViewerUrl . $sep . $param . '=' . rawurlencode($manifestUrl);

        $meta['source_viewer'] = 'r2_manifest';
        $meta['source_download'] = 'local';

        return [
            'viewer_url' => $viewerUrl,
            'download_url' => $localDownloadUrl,
            'study_routing' => $meta,
        ];
    }

    /**
     * URL de descarga ZIP vía API Orthanc (/studies/{id}/archive), misma convención que el frontend.
     */
    public static function buildLocalOrthancArchiveUrl(
        string $orthancStudyId,
        string $patientId = '',
        string $patientName = '',
        string $studyDate = '',
        string $studyDescription = ''
    ): string {
        if (!class_exists('OrthancConfig')) {
            require_once __DIR__ . '/config/orthanc_config.php';
        }
        $base = rtrim(OrthancConfig::getDownloadUrl(), '/');
        $formattedDate = '';
        if ($studyDate !== '') {
            $formattedDate = substr(str_replace('-', '', $studyDate), 0, 8);
        }
        $parts = [];
        if (trim($patientId) !== '') {
            $parts[] = trim($patientId);
        }
        if (trim($patientName) !== '') {
            $parts[] = trim($patientName);
        }
        if ($formattedDate !== '') {
            $parts[] = $formattedDate;
        }
        if (trim($studyDescription) !== '') {
            $parts[] = trim($studyDescription);
        }
        $filename = $parts !== [] ? (implode('-', $parts) . '.zip') : ('study_' . $orthancStudyId . '.zip');
        return $base . '/studies/' . rawurlencode($orthancStudyId) . '/archive?filename=' . rawurlencode($filename);
    }
}
