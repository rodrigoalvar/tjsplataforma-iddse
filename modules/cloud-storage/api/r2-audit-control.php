<?php
/**
 * Acciones de auditoría R2 (purge de huérfanos)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

function sendJsonResponse($payload, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
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
    $rcloneConfigFile = $rcloneTempDir . '/rclone_r2_audit_control_' . uniqid() . '.conf';
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
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        sendJsonResponse(['success' => false, 'error' => 'Método no permitido'], 405);
    }

    require_once __DIR__ . '/../config/cloud_storage_config.php';

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        sendJsonResponse(['success' => false, 'error' => 'Datos inválidos'], 400);
    }

    $action = $input['action'] ?? '';
    if ($action !== 'purge_orphan') {
        sendJsonResponse(['success' => false, 'error' => 'Acción no válida'], 400);
    }

    $studyUid = trim((string)($input['study_instance_uid'] ?? ''));
    if (!preg_match('/^[0-9.]+$/', $studyUid)) {
        sendJsonResponse(['success' => false, 'error' => 'study_instance_uid inválido'], 400);
    }

    $config = CloudStorageConfig::load();
    $bucket = trim((string)($config['r2_bucket_name'] ?? ''));
    $prefix = trim((string)($config['r2_storage_prefix'] ?? 'studies/'));
    $accountId = trim((string)($config['r2_account_id'] ?? ''));
    $accessKey = trim((string)($config['r2_access_key'] ?? ''));
    $secretKey = trim((string)($config['r2_secret_key'] ?? ''));

    if (!$bucket || !$prefix || !$accountId || !$accessKey || !$secretKey) {
        throw new Exception('Configuración R2 incompleta');
    }

    $prefix = trim($prefix, '/');
    $r2Remote = "r2:$bucket/$prefix/$studyUid/";

    $rclonePath = getRclonePath();
    $rcloneConfig = buildRcloneConfigFile($config);
    $logFile = sys_get_temp_dir() . '/rclone_audit_purge_' . uniqid() . '.log';

    $env = [
        'HOME' => sys_get_temp_dir(),
        'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        'TMPDIR' => sys_get_temp_dir(),
        'RCLONE_CONFIG' => $rcloneConfig,
    ];

    $cmd = sprintf(
        '%s purge %s --config %s --s3-no-check-bucket --log-level ERROR --stats 0',
        escapeshellarg($rclonePath),
        escapeshellarg($r2Remote),
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
        throw new Exception('No se pudo iniciar rclone purge');
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
        throw new Exception('rclone purge falló: ' . substr($out, 0, 1200));
    }

    sendJsonResponse([
        'success' => true,
        'message' => 'Huérfano eliminado de R2',
        'study_instance_uid' => $studyUid
    ]);
} catch (Exception $e) {
    error_log('[CLOUD_STORAGE][R2_AUDIT_CONTROL] Error: ' . $e->getMessage());
    sendJsonResponse([
        'success' => false,
        'error' => $e->getMessage()
    ], 500);
}

