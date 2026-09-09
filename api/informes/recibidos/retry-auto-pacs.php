<?php
/**
 * Reintenta envío automático a PACS para filas informes_recibidos vinculadas
 * con auto_pacs_estado en pendiente o error (columnas opcionales).
 *
 * CLI: php retry-auto-pacs.php [limite]
 * HTTP: GET/POST con sesión (misma auth que reprocesar-pendientes.php); parámetro opcional limit (1–500).
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/informe_recibido_auto_pacs.php';

function retry_auto_pacs_resolveUserId(PDO $db): ?int
{
    $token = null;
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (strpos($authHeader, 'Bearer ') === 0) {
        $token = substr($authHeader, 7);
    }
    if (!$token && !empty($_COOKIE['session_token'])) {
        $token = $_COOKIE['session_token'];
    }
    if (!$token) {
        return null;
    }
    try {
        require_once __DIR__ . '/../../../classes/User.php';
        $user = new User($db);
        $data = $user->validateSession($token);

        return $data ? (int)$data['id'] : null;
    } catch (Exception $e) {
        return null;
    }
}

function retry_auto_pacs_parseLimit(): int
{
    if (PHP_SAPI === 'cli') {
        global $argv;
        $n = isset($argv[1]) ? (int)$argv[1] : 50;

        return max(1, min(500, $n > 0 ? $n : 50));
    }
    $raw = $_GET['limit'] ?? $_POST['limit'] ?? 50;
    $n = (int)$raw;

    return max(1, min(500, $n > 0 ? $n : 50));
}

try {
    if (!$isCli) {
        $db = getDBConnection();
        $uid = retry_auto_pacs_resolveUserId($db);
        if (!$uid) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Sesión inválida o expirada'], JSON_UNESCAPED_UNICODE);
            exit();
        }
    } else {
        $db = getDBConnection();
    }

    $limit = retry_auto_pacs_parseLimit();
    $cfgActivo = ir_configAutoEnviarPacsActivo($db);
    $hasCols = ir_informesRecibidosHasAutoPacsColumns($db);

    if (!$hasCols) {
        $out = [
            'success' => true,
            'message' => 'Columnas auto_pacs_* no instaladas; nada que reprocesar.',
            'columnas_auto_pacs' => false,
            'config_activo' => $cfgActivo,
            'candidatos' => 0,
            'intentados' => 0,
        ];
        if ($isCli) {
            echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        } else {
            echo json_encode($out, JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    if (!$cfgActivo) {
        $out = [
            'success' => true,
            'message' => 'ir_auto_enviar_pacs_activo desactivado; no se envía a PACS.',
            'columnas_auto_pacs' => true,
            'config_activo' => false,
            'candidatos' => 0,
            'intentados' => 0,
        ];
        if ($isCli) {
            echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        } else {
            echo json_encode($out, JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    $rows = ir_selectRowsForAutoPacsRetry($db, $limit, IR_AUTO_PACS_MAX_TRIES, IR_AUTO_PACS_RETRY_BACKOFF_SEC);
    $candidatos = count($rows);
    $intentados = 0;
    foreach ($rows as $r) {
        $iid = (int)($r['informe_id'] ?? 0);
        $rid = (int)($r['id'] ?? 0);
        if ($iid <= 0 || $rid <= 0) {
            continue;
        }
        ir_enviarPacsSiCorresponde($db, $iid, $rid);
        $intentados++;
    }

    $out = [
        'success' => true,
        'message' => 'Lote de reintento auto PACS finalizado',
        'columnas_auto_pacs' => true,
        'config_activo' => true,
        'limit' => $limit,
        'max_intentos' => IR_AUTO_PACS_MAX_TRIES,
        'backoff_segundos' => IR_AUTO_PACS_RETRY_BACKOFF_SEC,
        'candidatos' => $candidatos,
        'intentados' => $intentados,
    ];

    if ($isCli) {
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    } else {
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    if (!$isCli) {
        http_response_code(500);
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    if ($isCli) {
        echo "\n";
        exit(1);
    }
}
