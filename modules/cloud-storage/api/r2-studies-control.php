<?php
/**
 * Control de estudios en R2 (operaciones destructivas)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

function sendJsonResponse($payload, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        sendJsonResponse(['success' => false, 'error' => 'Método no permitido'], 405);
    }

    require_once __DIR__ . '/../../../config/database.php';
    require_once __DIR__ . '/../config/cloud_storage_config.php';

    $database = new Database();
    $db = $database->getConnection();

    $config = CloudStorageConfig::load();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        sendJsonResponse(['success' => false, 'error' => 'Datos inválidos'], 400);
    }

    $action = $input['action'] ?? '';
    if (!in_array($action, ['delete', 'lock', 'unlock'], true)) {
        sendJsonResponse(['success' => false, 'error' => 'Acción no válida'], 400);
    }

    $orthancStudyId = $input['orthanc_study_id'] ?? null;
    if (!$orthancStudyId || !is_string($orthancStudyId)) {
        sendJsonResponse(['success' => false, 'error' => 'orthanc_study_id no especificado'], 400);
    }

    $orthancStudyId = trim($orthancStudyId);
    // Orthanc usa IDs tipo UUID, pero permitimos un set seguro razonable.
    if (!preg_match('/^[a-zA-Z0-9_.-]{1,255}$/', $orthancStudyId)) {
        sendJsonResponse(['success' => false, 'error' => 'orthanc_study_id inválido'], 400);
    }

    // Soportar instalaciones existentes sin los campos de bloqueo:
    // - delete debe funcionar igual (toma is_locked=0 por defecto)
    // - lock/unlock requieren la migración
    $columns = [];
    $colsStmt = $db->query("SHOW COLUMNS FROM r2_studies");
    while ($col = $colsStmt->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($col['Field'])) {
            $columns[] = (string)$col['Field'];
        }
    }
    $hasIsLocked = in_array('is_locked', $columns, true);
    $hasLockReason = in_array('lock_reason', $columns, true);
    $hasLockedAt = in_array('locked_at', $columns, true);

    // lock/unlock pueden hacerse solo si el estudio existe en r2_studies
    // delete además valida que esté online y que NO esté bloqueado (si existen campos)
    $stmt = $db->prepare("
        SELECT 
            study_instance_uid,
            r2_status,
            " . ($hasIsLocked ? "is_locked" : "0 AS is_locked") . ",
            " . ($hasLockReason ? "lock_reason" : "NULL AS lock_reason") . "
        FROM r2_studies
        WHERE orthanc_study_id = ?
        LIMIT 1
    ");
    $stmt->execute([$orthancStudyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        sendJsonResponse(['success' => false, 'error' => 'Estudio no encontrado en r2_studies'], 404);
    }

    if (($row['r2_status'] ?? 'none') !== 'online') {
        // En general solo tiene sentido bloquear/reciclar lo que está online en R2.
        if ($action !== 'delete') {
            sendJsonResponse(['success' => false, 'error' => 'El estudio no está marcado como ONLINE en R2'], 409);
        }
    }

    if ($action === 'lock' || $action === 'unlock') {
        if (!$hasIsLocked || !$hasLockReason || !$hasLockedAt) {
            sendJsonResponse([
                'success' => false,
                'error' => 'Campos de bloqueo no disponibles en BD. Ejecutar migración del módulo cloud-storage (add_r2_studies_lock_fields_safe.sql).'
            ], 500);
        }
        if ($action === 'lock') {
            $lockReason = $input['lock_reason'] ?? null;
            if ($lockReason !== null) {
                if (!is_string($lockReason)) {
                    sendJsonResponse(['success' => false, 'error' => 'lock_reason inválido'], 400);
                }
                $lockReason = trim($lockReason);
                if ($lockReason === '') {
                    $lockReason = null;
                }
                if ($lockReason !== null && mb_strlen($lockReason) > 500) {
                    sendJsonResponse(['success' => false, 'error' => 'lock_reason demasiado largo'], 400);
                }
            }

            // Si ya está bloqueado, igual devolvemos OK (idempotencia)
            $update = $db->prepare("
                UPDATE r2_studies
                SET is_locked = 1,
                    lock_reason = ?,
                    locked_at = NOW()
                WHERE orthanc_study_id = ?
            ");
            $update->execute([$lockReason, $orthancStudyId]);

            sendJsonResponse([
                'success' => true,
                'message' => 'Estudio bloqueado en R2 (no se podrá eliminar/reciclar)',
                'orthanc_study_id' => $orthancStudyId,
            ]);
        } else {
            $update = $db->prepare("
                UPDATE r2_studies
                SET is_locked = 0,
                    lock_reason = NULL,
                    locked_at = NULL
                WHERE orthanc_study_id = ?
            ");
            $update->execute([$orthancStudyId]);

            sendJsonResponse([
                'success' => true,
                'message' => 'Estudio desbloqueado en R2',
                'orthanc_study_id' => $orthancStudyId,
            ]);
        }
    }

    $studyInstanceUid = $row['study_instance_uid'] ?? '';
    // Para delete necesitamos que el estudio esté online y que NO esté bloqueado
    if (($row['is_locked'] ?? 0) == 1) {
        sendJsonResponse(['success' => false, 'error' => 'El estudio está bloqueado en R2 y no se puede eliminar'], 409);
    }
    if (($row['r2_status'] ?? 'none') !== 'online') {
        sendJsonResponse(['success' => false, 'error' => 'El estudio no está marcado como ONLINE en R2'], 409);
    }
    if (!preg_match('/^[0-9.]+$/', $studyInstanceUid)) {
        sendJsonResponse(['success' => false, 'error' => 'study_instance_uid inválido'], 400);
    }

    $bucketName = $config['r2_bucket_name'] ?? '';
    $accountId  = $config['r2_account_id'] ?? '';
    $accessKey  = $config['r2_access_key'] ?? '';
    $secretKey  = $config['r2_secret_key'] ?? '';
    $region     = $config['r2_region'] ?? 'auto';
    $storagePrefix = rtrim(($config['r2_storage_prefix'] ?? 'studies/'), '/');

    if (!$bucketName || !$accountId || !$accessKey || !$secretKey) {
        sendJsonResponse(['success' => false, 'error' => 'Credenciales/configuración R2 incompleta'], 500);
    }

    // 2) Construir prefijo exacto: <storagePrefix>/<studyInstanceUid>/
    $r2Dest = $storagePrefix . '/' . $studyInstanceUid . '/';
    $r2Remote = "r2:$bucketName/$r2Dest";

    // 3) Crear config temporal de rclone (evita problemas con HOME/getent)
    $rcloneTempDir = sys_get_temp_dir();
    $rcloneConfigFile = $rcloneTempDir . '/rclone_r2_delete_' . uniqid() . '.conf';
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
        throw new Exception("No se pudo crear archivo de config temporal para rclone: $rcloneConfigFile");
    }
    chmod($rcloneConfigFile, 0600);

    // 4) Encontrar rclone binario (priorizar nativo en el módulo)
    $nativeBinary = __DIR__ . '/../bin/rclone';
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
        throw new Exception("rclone nativo no encontrado. Verifica el binario en: $nativeBinary");
    }

    // 5) Ejecutar rclone purge sobre el prefijo exacto
    $rcloneLogFile = $rcloneTempDir . '/rclone_purge_delete_' . uniqid() . '.log';
    $env = [
        'HOME' => $rcloneTempDir,
        'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        'TMPDIR' => $rcloneTempDir,
        'RCLONE_CONFIG' => $rcloneConfigFile,
    ];

    // Importante: usamos purge para borrar recursivamente TODO bajo ese prefijo.
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
        throw new Exception('No se pudo iniciar el proceso rclone purge');
    }

    // Esperar a que termine (poll cada 1s)
    while (true) {
        $status = proc_get_status($process);
        if (!$status['running']) {
            $exitCode = (int)($status['exitcode'] ?? -1);
            break;
        }
        sleep(1);
    }

    proc_close($process); // no confiamos en return code de proc_close en este sistema

    $rcloneLogTail = '';
    if (file_exists($rcloneLogFile)) {
        $content = @file_get_contents($rcloneLogFile);
        if ($content !== false) {
            $lines = preg_split("/\r?\n/", $content);
            $rcloneLogTail = implode("\n", array_slice($lines, -80));
        }
    }

    @unlink($rcloneConfigFile);
    @unlink($rcloneLogFile);

    if ($exitCode !== 0) {
        throw new Exception("rclone purge falló con exit code $exitCode: " . substr($rcloneLogTail, 0, 1200));
    }

    // 6) Actualizar DB: marcar como no existente en R2
    $update = $db->prepare("
        UPDATE r2_studies
        SET r2_status = 'none',
            r2_manifest_path = NULL,
            r2_manifest_url = NULL,
            total_instances = 0,
            total_size_bytes = 0,
            uploaded_at = NULL,
            updated_at = NOW()
        WHERE orthanc_study_id = ?
    ");
    $update->execute([$orthancStudyId]);

    // IMPORTANTE:
    // NO cambiar r2_queue.status a 'pending' aquí.
    // Si se marca como pending, el worker automático lo toma y re-dispara un upload no deseado.
    // Solo dejamos una traza mínima en updated_at para que la UI pueda refrescar datos recientes.
    $touchQueue = $db->prepare("
        UPDATE r2_queue
        SET updated_at = NOW()
        WHERE orthanc_study_id = ?
    ");
    $touchQueue->execute([$orthancStudyId]);

    sendJsonResponse([
        'success' => true,
        'message' => 'Estudio eliminado de R2',
        'orthanc_study_id' => $orthancStudyId,
        'study_instance_uid' => $studyInstanceUid
    ]);
} catch (Exception $e) {
    error_log('[CLOUD_STORAGE][R2_STUDIES_CONTROL] Error: ' . $e->getMessage());
    sendJsonResponse([
        'success' => false,
        'error' => $e->getMessage()
    ], 500);
}

