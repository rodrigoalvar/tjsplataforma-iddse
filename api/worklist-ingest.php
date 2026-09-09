<?php
/**
 * Endpoint técnico para ingesta automática (push).
 * Autenticación: X-API-Key + allowlist opcional de IP.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, X-Source-System, X-Request-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/WorklistIngestionService.php';

try {
    $db = getDBConnection();
    if (!$db) {
        throw new Exception('No se pudo conectar a la base de datos');
    }

    $service = new WorklistIngestionService($db);
    $service->ensureSchema();
    ensureWorklistConfigTableForIngest($db);

    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($apiKey === '') {
        throw new Exception('X-API-Key requerido');
    }

    $keyInfo = validateApiKey($db, $apiKey);
    if (!$keyInfo) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'API key inválida o inactiva']);
        exit();
    }

    $config = $service->getWorklistConfig();
    if ((int)($config['push_enabled'] ?? 0) !== 1) {
        throw new Exception('La recepción push está deshabilitada');
    }

    $clientIp = resolveClientIp();
    if (!isIpAllowed($clientIp, (string)($config['push_allowed_ips'] ?? ''), (string)($keyInfo['allowed_ips'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => "IP no permitida: $clientIp"]);
        exit();
    }

    $txtContent = null;
    $sourceFile = 'push-api.txt';

    if (isset($_FILES['txt']) && $_FILES['txt']['error'] === UPLOAD_ERR_OK) {
        $sourceFile = $_FILES['txt']['name'] ?: $sourceFile;
        $txtContent = file_get_contents($_FILES['txt']['tmp_name']);
    } else {
        $input = json_decode(file_get_contents('php://input'), true);
        if (is_array($input) && !empty($input['txt_content'])) {
            $txtContent = (string)$input['txt_content'];
            if (!empty($input['source_file'])) {
                $sourceFile = (string)$input['source_file'];
            }
        }
    }

    if ($txtContent === null || trim($txtContent) === '') {
        throw new Exception('Debe enviar txt (multipart) o txt_content (JSON)');
    }

    $sourceSystem = $_SERVER['HTTP_X_SOURCE_SYSTEM'] ?? 'unknown-system';
    $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? uniqid('wl_', true);
    $sourceDetail = sprintf(
        'system=%s;file=%s;ip=%s;request_id=%s',
        $sourceSystem,
        $sourceFile,
        $clientIp,
        $requestId
    );

    $result = $service->ingestTxtContent($txtContent, 'PUSH_API', $sourceDetail, null, true);
    touchApiKeyUsage($db, (int)$keyInfo['id']);

    echo json_encode([
        'success' => true,
        'message' => 'Turno procesado',
        'result' => $result
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

function validateApiKey(PDO $db, string $plainApiKey): ?array
{
    $stmt = $db->query("SELECT * FROM worklist_api_keys WHERE is_active = 1");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $hash = (string)($row['key_hash'] ?? '');
        if ($hash !== '' && password_verify($plainApiKey, $hash)) {
            return $row;
        }
    }
    return null;
}

function touchApiKeyUsage(PDO $db, int $id): void
{
    $stmt = $db->prepare("UPDATE worklist_api_keys SET last_used_at = NOW() WHERE id = ?");
    $stmt->execute([$id]);
}

function resolveClientIp(): string
{
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded !== '') {
        $parts = explode(',', $forwarded);
        return trim($parts[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function isIpAllowed(string $ip, string $globalAllowlist, string $keyAllowlist): bool
{
    $list = [];
    foreach ([$globalAllowlist, $keyAllowlist] as $raw) {
        foreach (preg_split('/[\s,;]+/', trim($raw)) as $entry) {
            if ($entry !== '') {
                $list[] = $entry;
            }
        }
    }
    if (empty($list)) {
        return true;
    }
    foreach ($list as $allowed) {
        if ($allowed === $ip) {
            return true;
        }
    }
    return false;
}

function ensureWorklistConfigTableForIngest(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS `worklist_config` (
          `id` INT PRIMARY KEY DEFAULT 1,
          `orthanc_worklist_path` VARCHAR(500) NOT NULL DEFAULT '/var/lib/orthanc/db/WorklistsDatabase',
          `orthanc_host` VARCHAR(255) DEFAULT 'localhost',
          `orthanc_worklist_mode` ENUM('filesystem','rest') DEFAULT 'filesystem',
          `orthanc_rest_base_url` VARCHAR(500) DEFAULT NULL,
          `orthanc_rest_user` VARCHAR(255) DEFAULT NULL,
          `orthanc_rest_pass` VARCHAR(255) DEFAULT NULL,
          `orthanc_rest_verify_ssl` TINYINT(1) DEFAULT 0,
          `orthanc_rest_timeout` INT DEFAULT 15,
          `pull_enabled` TINYINT(1) DEFAULT 0,
          `pull_input_path` VARCHAR(500) DEFAULT NULL,
          `pull_processed_path` VARCHAR(500) DEFAULT NULL,
          `pull_failed_path` VARCHAR(500) DEFAULT NULL,
          `push_enabled` TINYINT(1) DEFAULT 0,
          `push_allowed_ips` TEXT DEFAULT NULL,
          `sync_interval` INT DEFAULT 300
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}
