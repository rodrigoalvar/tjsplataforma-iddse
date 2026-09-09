<?php
/**
 * Auditoría R2:
 * - Huérfanos: existen en R2 pero no en r2_studies online
 * - Faltantes: figuran online en r2_studies pero no existen en R2
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

function getRclonePath() {
    $nativeBinary = __DIR__ . '/../bin/rclone';
    $commonPaths = [$nativeBinary, '/usr/local/bin/rclone', '/usr/bin/rclone'];
    foreach ($commonPaths as $path) {
        if (file_exists($path) && is_executable($path)) return $path;
    }
    $whichPath = trim(shell_exec('which rclone 2>/dev/null'));
    if (!empty($whichPath) && file_exists($whichPath) && strpos($whichPath, '/snap/') === false) {
        return $whichPath;
    }
    throw new Exception("rclone nativo no encontrado. Verifica el binario en: $nativeBinary");
}

function buildRcloneConfigFile($config) {
    $rcloneTempDir = sys_get_temp_dir();
    $rcloneConfigFile = $rcloneTempDir . '/rclone_r2_audit_' . uniqid() . '.conf';
    $endpoint = 'https://' . $config['r2_account_id'] . '.r2.cloudflarestorage.com';
    $region = $config['r2_region'] ?? 'auto';

    $content = "[r2]\n"
        . "type = s3\n"
        . "provider = Cloudflare\n"
        . "access_key_id = " . $config['r2_access_key'] . "\n"
        . "secret_access_key = " . $config['r2_secret_key'] . "\n"
        . "endpoint = " . $endpoint . "\n"
        . "region = " . $region . "\n"
        . "no_check_bucket = true\n"
        . "env_auth = false\n";

    if (file_put_contents($rcloneConfigFile, $content) === false) {
        throw new Exception("No se pudo crear archivo temporal rclone config");
    }
    chmod($rcloneConfigFile, 0600);
    return $rcloneConfigFile;
}

try {
    require_once __DIR__ . '/../../../config/database.php';
    require_once __DIR__ . '/../config/cloud_storage_config.php';

    $database = new Database();
    $db = $database->getConnection();
    $config = CloudStorageConfig::load();

    $bucket = trim((string)($config['r2_bucket_name'] ?? ''));
    $prefix = trim((string)($config['r2_storage_prefix'] ?? 'studies/'));
    $accountId = trim((string)($config['r2_account_id'] ?? ''));
    $accessKey = trim((string)($config['r2_access_key'] ?? ''));
    $secretKey = trim((string)($config['r2_secret_key'] ?? ''));

    if (!$bucket || !$prefix || !$accountId || !$accessKey || !$secretKey) {
        throw new Exception('Configuración R2 incompleta (bucket/prefix/account/keys)');
    }

    $prefix = trim($prefix, '/');
    $remote = "r2:$bucket/$prefix/";

    $rclonePath = getRclonePath();
    $rcloneConfig = buildRcloneConfigFile($config);
    $logFile = sys_get_temp_dir() . '/rclone_audit_' . uniqid() . '.log';
    $env = [
        'HOME' => sys_get_temp_dir(),
        'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        'TMPDIR' => sys_get_temp_dir(),
        'RCLONE_CONFIG' => $rcloneConfig,
    ];

    // Listar "directorios" de primer nivel (UIDs) bajo el prefix.
    $cmd = sprintf(
        '%s lsf %s --config %s --dirs-only --fast-list --log-level ERROR',
        escapeshellarg($rclonePath),
        escapeshellarg($remote),
        escapeshellarg($rcloneConfig)
    );

    $desc = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', $logFile, 'w'],
        2 => ['file', $logFile, 'a'],
    ];
    $proc = proc_open($cmd, $desc, $pipes, null, $env);
    if (!is_resource($proc)) {
        @unlink($rcloneConfig);
        @unlink($logFile);
        throw new Exception('No se pudo iniciar rclone lsf');
    }

    while (true) {
        $status = proc_get_status($proc);
        if (!$status['running']) {
            $exitCode = (int)($status['exitcode'] ?? -1);
            break;
        }
        usleep(200000);
    }
    proc_close($proc);

    $out = file_exists($logFile) ? (string)@file_get_contents($logFile) : '';
    @unlink($rcloneConfig);
    @unlink($logFile);

    if ($exitCode !== 0) {
        throw new Exception('rclone lsf falló: ' . substr($out, 0, 1200));
    }

    $uidsInR2 = [];
    foreach (preg_split("/\r?\n/", $out) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $uid = rtrim($line, '/');
        if (preg_match('/^[0-9.]+$/', $uid)) {
            $uidsInR2[] = $uid;
        }
    }
    $uidsInR2 = array_values(array_unique($uidsInR2));

    // UIDs ONLINE en BD
    $stmt = $db->query("SELECT study_instance_uid FROM r2_studies WHERE r2_status = 'online'");
    $uidsInDbOnline = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $uidsInDbOnline = array_values(array_unique(array_filter($uidsInDbOnline, function ($v) {
        return is_string($v) && preg_match('/^[0-9.]+$/', $v);
    })));

    $orphans = array_values(array_diff($uidsInR2, $uidsInDbOnline));
    $missingInR2 = array_values(array_diff($uidsInDbOnline, $uidsInR2));

    echo json_encode([
        'success' => true,
        'data' => [
            'bucket' => $bucket,
            'prefix' => $prefix,
            'uids_in_r2_count' => count($uidsInR2),
            'uids_in_db_online_count' => count($uidsInDbOnline),
            'orphans_count' => count($orphans),
            'missing_in_r2_count' => count($missingInR2),
            'orphans' => $orphans,
            'missing_in_r2' => $missingInR2,
            'updated_at' => date('Y-m-d H:i:s')
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('[CLOUD_STORAGE][R2_AUDIT] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

