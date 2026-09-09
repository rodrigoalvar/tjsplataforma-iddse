<?php
/**
 * Health check del servidor de transcripción (Whisper / whisper-cli).
 * Usa URL de ai_config (whisper_cli_api_url o whisper_api_url según método).
 */

if (!function_exists('transcriptionGetWhisperConfigFromDb')) {
    function transcriptionGetWhisperConfigFromDb(PDO $db): array
    {
        $defaults = [
            'whisper_method' => 'whisper-server',
            'whisper_api_url' => 'http://localhost:8080',
            'whisper_cli_api_url' => 'http://localhost:3001',
            'whisper_timeout' => 600,
        ];
        try {
            if ($db->query("SHOW TABLES LIKE 'ai_config'")->rowCount() === 0) {
                return $defaults;
            }
            $stmt = $db->query('SELECT * FROM ai_config WHERE id = 1 LIMIT 1');
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            if (!$row) {
                return $defaults;
            }
            return array_merge($defaults, [
                'whisper_method' => $row['whisper_method'] ?? $defaults['whisper_method'],
                'whisper_api_url' => $row['whisper_api_url'] ?? $defaults['whisper_api_url'],
                'whisper_cli_api_url' => $row['whisper_cli_api_url'] ?? $defaults['whisper_cli_api_url'],
                'whisper_timeout' => isset($row['whisper_timeout']) ? (int) $row['whisper_timeout'] : $defaults['whisper_timeout'],
            ]);
        } catch (Exception $e) {
            return $defaults;
        }
    }
}

if (!function_exists('transcriptionCheckHealth')) {
    /**
     * @param array|null $configOverride opcional (p.ej. URL desde el formulario de config)
     * @return array{success:bool,ready:bool,status:string,message:string,http_code?:int,url?:string,raw?:mixed,allow_enqueue:bool,degraded:bool}
     */
    function transcriptionCheckHealth(?PDO $db = null, ?array $configOverride = null, int $timeoutSeconds = 4): array
    {
        require_once __DIR__ . '/../utils/WhisperClient.php';

        if ($configOverride !== null) {
            $config = array_merge([
                'whisper_method' => 'whisper-cli',
                'whisper_api_url' => 'http://localhost:8080',
                'whisper_cli_api_url' => 'http://localhost:3001',
            ], $configOverride);
        } else {
            if (!$db) {
                require_once __DIR__ . '/../config/database.php';
                $db = getDBConnection();
            }
            $config = transcriptionGetWhisperConfigFromDb($db);
        }

        $client = new WhisperClient([
            'whisper_method' => $config['whisper_method'] ?? 'whisper-server',
            'whisper_api_url' => $config['whisper_api_url'] ?? 'http://localhost:8080',
            'whisper_cli_api_url' => $config['whisper_cli_api_url'] ?? 'http://localhost:3001',
            'whisper_timeout' => $timeoutSeconds,
        ]);

        $health = $client->checkHealth($timeoutSeconds);

        $status = $health['status'] ?? 'down';
        $ready = !empty($health['ready']);
        $allowEnqueue = $ready && in_array($status, ['ok', 'degraded'], true);
        $degraded = ($status === 'degraded');

        return [
            'success' => !empty($health['success']),
            'ready' => $ready,
            'status' => $status,
            'message' => $health['message'] ?? '',
            'http_code' => $health['http_code'] ?? null,
            'url' => $health['url'] ?? null,
            'raw' => $health['raw'] ?? null,
            'allow_enqueue' => $allowEnqueue,
            'degraded' => $degraded,
            'whisper_method' => $config['whisper_method'] ?? null,
        ];
    }
}

if (!function_exists('transcriptionAssertReadyToEnqueue')) {
    /**
     * Lanza Exception si no se debe encolar (status down / !ready).
     * Si degraded, no lanza (permite encolar con aviso en el caller).
     *
     * @return array health result
     */
    function transcriptionAssertReadyToEnqueue(?PDO $db = null): array
    {
        $health = transcriptionCheckHealth($db, null, 4);
        if (empty($health['allow_enqueue'])) {
            $msg = $health['message'] ?: 'El servidor de transcripción no está listo.';
            throw new Exception($msg);
        }
        return $health;
    }
}
