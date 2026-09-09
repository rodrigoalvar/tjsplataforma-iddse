<?php
/**
 * API: leer logs del módulo HL7 worklist (lista blanca).
 * GET ?action=list
 * GET ?file=listener.log&lines=300
 * GET ?action=status — listener heartbeat + puerto
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../../classes/User.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../php/bootstrap.php';

try {
    $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? $_GET['session_token'] ?? null;
    if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
        $sessionToken = substr($sessionToken, 7);
    }
    if (!$sessionToken && !empty($_COOKIE['session_token'])) {
        $sessionToken = $_COOKIE['session_token'];
    }
    if (!$sessionToken) {
        throw new Exception('Token de sesión requerido');
    }
    $user = new User();
    $userData = $user->validateSession($sessionToken);
    if (!$userData) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sesión inválida']);
        exit;
    }
    $permisos = json_decode($userData['permisos'] ?? '[]', true) ?: [];
    $can = in_array('all', $permisos, true)
        || in_array('configuracion', $permisos, true)
        || in_array('worklist', $permisos, true);
    if (!$can) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Sin permiso']);
        exit;
    }

    $logsDir = realpath(__DIR__ . '/../logs');
    if ($logsDir === false || !is_dir($logsDir)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Directorio de logs no disponible']);
        exit;
    }

    $ALLOWED = [
        'listener.log' => [
            'label' => 'Listener MLLP',
            'description' => 'Conexiones, recv OK, ACK y errores del servicio hl7-mllp-listener (puerto configurado).',
        ],
    ];

    $action = $_GET['action'] ?? '';
    $file = isset($_GET['file']) ? basename((string)$_GET['file']) : '';

    if ($action === 'status') {
        $db = getDBConnection();
        $cfg = Hl7WorklistModule::readConfigFromDb($db);
        echo json_encode([
            'success' => true,
            'data' => [
                'hl7_enabled' => (int)($cfg['hl7_enabled'] ?? 0),
                'hl7_port' => (int)($cfg['hl7_port'] ?? 2575),
                'hl7_bind_host' => (string)($cfg['hl7_bind_host'] ?? '0.0.0.0'),
                'worklist_ingest_mode' => (string)($cfg['worklist_ingest_mode'] ?? ''),
                'listener_alive' => Hl7WorklistModule::isListenerAlive(),
                'heartbeat_path' => Hl7WorklistModule::heartbeatPath(),
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Carpetas HL7 permitidas (inbox / processed / failed)
    $hl7Dirs = null;
    $resolveHl7Dirs = static function () use (&$hl7Dirs): array {
        if ($hl7Dirs !== null) {
            return $hl7Dirs;
        }
        $db = getDBConnection();
        $cfg = Hl7WorklistModule::readConfigFromDb($db);
        $inbox = rtrim((string)($cfg['hl7_input_path'] ?? Hl7WorklistModule::defaultInboxPath()), '/');
        $processed = rtrim((string)($cfg['hl7_processed_path'] ?? ($inbox . '/processed')), '/');
        $failed = rtrim((string)($cfg['hl7_failed_path'] ?? ($inbox . '/failed')), '/');
        $hl7Dirs = [
            'inbox' => $inbox,
            'processed' => $processed,
            'failed' => $failed,
        ];
        return $hl7Dirs;
    };

    $isSafeHl7Name = static function (string $name): bool {
        return (bool)preg_match('/^[A-Za-z0-9._-]+\.hl7$/i', $name);
    };

    if ($action === 'messages') {
        $dirs = $resolveHl7Dirs();
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 40;
        $limit = max(5, min(100, $limit));
        $folderFilter = isset($_GET['folder']) ? strtolower(trim((string)$_GET['folder'])) : '';
        $items = [];
        foreach ($dirs as $folder => $dirPath) {
            if ($folderFilter !== '' && $folderFilter !== $folder) {
                continue;
            }
            if (!is_dir($dirPath)) {
                continue;
            }
            foreach (glob($dirPath . '/*.[Hh][Ll]7') ?: [] as $full) {
                if (!is_file($full)) {
                    continue;
                }
                $base = basename($full);
                if (!$isSafeHl7Name($base)) {
                    continue;
                }
                $items[] = [
                    'folder' => $folder,
                    'name' => $base,
                    'size_bytes' => (int)filesize($full),
                    'modified_at' => date('c', (int)filemtime($full)),
                    'mtime' => (int)filemtime($full),
                ];
            }
        }
        usort($items, static function ($a, $b) {
            return ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0);
        });
        $items = array_slice($items, 0, $limit);
        foreach ($items as &$it) {
            unset($it['mtime']);
        }
        unset($it);
        echo json_encode([
            'success' => true,
            'data' => [
                'messages' => $items,
                'dirs' => $dirs,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'message') {
        $dirs = $resolveHl7Dirs();
        $folder = strtolower(trim((string)($_GET['folder'] ?? 'inbox')));
        $name = basename((string)($_GET['name'] ?? ''));
        if (!isset($dirs[$folder])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Carpeta no válida (inbox|processed|failed)']);
            exit;
        }
        if ($name === '' || !$isSafeHl7Name($name)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Nombre de archivo .hl7 no válido']);
            exit;
        }
        $dirReal = realpath($dirs[$folder]);
        if ($dirReal === false || !is_dir($dirReal)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Carpeta HL7 no disponible']);
            exit;
        }
        $candidate = $dirReal . DIRECTORY_SEPARATOR . $name;
        $fileReal = realpath($candidate);
        if ($fileReal === false || !is_file($fileReal) || strpos($fileReal, $dirReal . DIRECTORY_SEPARATOR) !== 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Mensaje HL7 no encontrado']);
            exit;
        }
        $size = (int)filesize($fileReal);
        $maxBytes = 262144; // 256 KiB
        $raw = file_get_contents($fileReal, false, null, 0, $maxBytes + 1);
        if ($raw === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'No se pudo leer el archivo']);
            exit;
        }
        $truncated = strlen($raw) > $maxBytes;
        if ($truncated) {
            $raw = substr($raw, 0, $maxBytes);
        }
        // Mostrar segmentos en líneas (HL7 usa CR)
        $display = str_replace(["\r\n", "\r"], "\n", $raw);
        echo json_encode([
            'success' => true,
            'data' => [
                'folder' => $folder,
                'name' => $name,
                'size_bytes' => $size,
                'modified_at' => date('c', (int)filemtime($fileReal)),
                'truncated' => $truncated,
                'content' => $display,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'list' || ($action === '' && $file === '')) {
        $catalog = [];
        foreach ($ALLOWED as $name => $meta) {
            $full = $logsDir . DIRECTORY_SEPARATOR . $name;
            $catalog[] = [
                'id' => $name,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'exists' => is_file($full),
                'size_bytes' => is_file($full) ? (int)filesize($full) : 0,
                'modified_at' => is_file($full) ? date('c', (int)filemtime($full)) : null,
            ];
        }
        echo json_encode(['success' => true, 'data' => ['logs' => $catalog]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($file === '' || !isset($ALLOWED[$file])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Archivo de log no permitido']);
        exit;
    }

    $fullPath = $logsDir . DIRECTORY_SEPARATOR . $file;
    if (!is_file($fullPath) || !is_readable($fullPath)) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'El archivo no existe o no es legible (¿el listener escribió alguna vez?)',
            'data' => ['file' => $file],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $lines = isset($_GET['lines']) ? (int)$_GET['lines'] : 300;
    $lines = max(50, min(5000, $lines));

    $size = (int)filesize($fullPath);
    $maxBytes = 524288;
    $readFrom = max(0, $size - $maxBytes);
    $h = fopen($fullPath, 'rb');
    if ($readFrom > 0) {
        fseek($h, $readFrom);
    }
    $data = stream_get_contents($h);
    fclose($h);
    $arr = explode("\n", $data === false ? '' : $data);
    if ($readFrom > 0 && isset($arr[0])) {
        array_shift($arr);
    }
    if (count($arr) > $lines) {
        $arr = array_slice($arr, -$lines);
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'file' => $file,
            'lines_requested' => $lines,
            'size_bytes' => $size,
            'modified_at' => date('c', (int)filemtime($fullPath)),
            'content' => implode("\n", $arr),
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
