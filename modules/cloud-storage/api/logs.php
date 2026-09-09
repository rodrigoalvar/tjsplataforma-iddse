<?php
/**
 * API: listar y leer logs del módulo cloud-storage (lista blanca).
 * GET ?action=list  — catálogo con existencia y tamaño
 * GET ?file=r2-worker.log&lines=500 — últimas líneas (máx. 5000)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$logsDir = realpath(__DIR__ . '/../logs');
if ($logsDir === false || !is_dir($logsDir)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Directorio de logs no disponible',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Archivos permitidos (solo basename) → metadatos para la UI */
$ALLOWED_LOGS = [
    'r2-worker.log' => [
        'label' => 'Worker R2 (cron)',
        'description' => 'Salida de workers/r2-upload-worker.php redirigida por cron (setup-cron.sh / CRON_SETUP.md).',
    ],
    'r2-auto-enqueue.log' => [
        'label' => 'Auto-encolado R2 (webhook)',
        'description' => 'Una línea JSON por evento: Lua/Orthanc → auto-enqueue.php (auth, omitidos, encolados, errores).',
    ],
];

function readLastLines(string $path, int $maxLines, int $maxBytes = 524288): string
{
    $size = @filesize($path);
    if ($size === false || $size === 0) {
        return '';
    }
    $readFrom = max(0, $size - $maxBytes);
    $h = @fopen($path, 'rb');
    if (!$h) {
        return '';
    }
    if ($readFrom > 0) {
        fseek($h, $readFrom);
    }
    $data = stream_get_contents($h);
    fclose($h);
    if ($data === false) {
        return '';
    }
    $lines = explode("\n", $data);
    if ($readFrom > 0 && isset($lines[0])) {
        array_shift($lines);
    }
    if (count($lines) > $maxLines) {
        $lines = array_slice($lines, -$maxLines);
    }
    return implode("\n", $lines);
}

try {
    $action = $_GET['action'] ?? '';
    $file = isset($_GET['file']) ? basename((string) $_GET['file']) : '';

    if ($action === 'list' || ($action === '' && $file === '')) {
        $catalog = [];
        foreach ($ALLOWED_LOGS as $name => $meta) {
            $full = $logsDir . DIRECTORY_SEPARATOR . $name;
            $catalog[] = [
                'id' => $name,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'exists' => is_file($full),
                'size_bytes' => is_file($full) ? (int) filesize($full) : 0,
                'modified_at' => is_file($full) ? date('c', (int) filemtime($full)) : null,
            ];
        }
        echo json_encode([
            'success' => true,
            'data' => [
                'logs' => $catalog,
                'hint' => 'Los mensajes PHP error_log() del procesador de cola suelen ir al log configurado en php.ini (p. ej. PHP-FPM/Apache), no a esta carpeta.',
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($file === '' || !isset($ALLOWED_LOGS[$file])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Archivo de log no permitido o no indicado',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $fullPath = $logsDir . DIRECTORY_SEPARATOR . $file;
    if (!is_file($fullPath) || !is_readable($fullPath)) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'El archivo no existe o no es legible',
            'data' => ['file' => $file],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $lines = isset($_GET['lines']) ? (int) $_GET['lines'] : 500;
    $lines = max(50, min(5000, $lines));

    $content = readLastLines($fullPath, $lines);

    echo json_encode([
        'success' => true,
        'data' => [
            'file' => $file,
            'lines_requested' => $lines,
            'size_bytes' => (int) filesize($fullPath),
            'modified_at' => date('c', (int) filemtime($fullPath)),
            'content' => $content,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error leyendo logs',
    ], JSON_UNESCAPED_UNICODE);
}
