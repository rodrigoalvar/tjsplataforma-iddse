<?php
/**
 * API para consultar uso/cuota actual de R2 via Cloudflare API
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

function formatBytes($bytes) {
    $bytes = (int)$bytes;
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

try {
    require_once __DIR__ . '/../config/cloud_storage_config.php';

    $config = CloudStorageConfig::load();
    $accountId = trim((string)($config['r2_account_id'] ?? ''));
    $apiToken  = trim((string)($config['r2_cf_api_token_read'] ?? ''));
    $bucket    = trim((string)($config['r2_bucket_name'] ?? ''));

    if ($accountId === '' || $apiToken === '' || $bucket === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Falta configuración: account_id, bucket_name o token de lectura de uso R2'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $url = "https://api.cloudflare.com/client/v4/accounts/{$accountId}/r2/buckets/{$bucket}/usage";
    $cacheKeyRaw = $accountId . '|' . $bucket;
    $cacheFile = sys_get_temp_dir() . '/r2_usage_cache_' . md5($cacheKeyRaw) . '.json';

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
    $curlErrno = curl_errno($ch);
    curl_close($ch);

    if ($raw === false || $curlError) {
        // Si hay timeout/conectividad, intentar devolver caché reciente (si existe)
        $isTimeout = in_array($curlErrno, [CURLE_OPERATION_TIMEDOUT, CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST], true);
        if ($isTimeout && file_exists($cacheFile)) {
            $cached = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached['data'])) {
                echo json_encode([
                    'success' => true,
                    'data' => $cached['data'],
                    'cached' => true,
                    'warning' => 'Uso R2 servido desde caché por timeout de Cloudflare API'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        // No responder 500 para no romper UX; devolvemos error controlado
        echo json_encode([
            'success' => false,
            'error' => 'Error de conexión Cloudflare API: ' . $curlError
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $response = json_decode($raw, true);
    if (!is_array($response)) {
        throw new Exception('Respuesta inválida de Cloudflare API');
    }

    $apiOk = !empty($response['success']);
    if ($httpCode !== 200 || !$apiOk) {
        $errors = isset($response['errors']) ? json_encode($response['errors'], JSON_UNESCAPED_UNICODE) : '[]';
        // Fallback a caché ante error de API
        if (file_exists($cacheFile)) {
            $cached = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached['data'])) {
                echo json_encode([
                    'success' => true,
                    'data' => $cached['data'],
                    'cached' => true,
                    'warning' => "Uso R2 servido desde caché por error API (HTTP {$httpCode})"
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        echo json_encode([
            'success' => false,
            'error' => "Error Cloudflare API (HTTP {$httpCode}): {$errors}"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = $response['result'] ?? [];
    $payload = (int)($result['payloadSize'] ?? 0);
    $metadata = (int)($result['metadataSize'] ?? 0);
    $total = $payload + $metadata;
    $objectCount = (int)($result['objectCount'] ?? 0);
    $uploadCount = (int)($result['uploadCount'] ?? 0);

    $payload = [
        'bytes' => $total,
        'human' => formatBytes($total),
        'gb' => round($total / 1073741824, 3),
        'payload_bytes' => $payload,
        'metadata_bytes' => $metadata,
        'object_count' => $objectCount,
        'upload_count' => $uploadCount,
        'bucket' => $bucket,
        'updated_at' => date('Y-m-d H:i:s')
    ];

    // Guardar caché de último valor exitoso
    @file_put_contents($cacheFile, json_encode([
        'saved_at' => date('Y-m-d H:i:s'),
        'data' => $payload
    ], JSON_UNESCAPED_UNICODE));

    echo json_encode([
        'success' => true,
        'data' => $payload,
        'cached' => false
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('[CLOUD_STORAGE][R2_USAGE] Error: ' . $e->getMessage());
    // Error controlado en 200 para evitar 500 repetitivos en polling frontend
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

