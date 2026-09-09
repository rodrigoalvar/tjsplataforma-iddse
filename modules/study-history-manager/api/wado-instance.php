<?php
/**
 * Proxy WADO firmado: el navegador pide HTTPS al portal; PHP descarga del PACS (HTTP interno).
 * Evita mixed content y expone instancias sin que el cliente alcance la IP del PACS.
 */

while (ob_get_level()) {
    ob_end_clean();
}

require_once __DIR__ . '/../includes/StudyHistoryCors.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
    header('Access-Control-Allow-Headers: ' . StudyHistoryCors::allowHeaders());
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../includes/StudyHistoryManifestService.php';
require_once __DIR__ . '/../includes/StudyHistoryManifestSigning.php';

function wadoInstanceJsonError($msg, $code) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    StudyHistoryCors::sendAllowOriginAndHeaders();
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
        wadoInstanceJsonError('Método no permitido', 405);
    }

    $nodeId = isset($_GET['n']) ? (int) $_GET['n'] : 0;
    $studyUid = isset($_GET['s']) ? trim((string) $_GET['s']) : '';
    $seriesUid = isset($_GET['ser']) ? trim((string) $_GET['ser']) : '';
    $sopUid = isset($_GET['o']) ? trim((string) $_GET['o']) : '';
    $exp = isset($_GET['e']) ? (int) $_GET['e'] : 0;
    $sig = isset($_GET['sig']) ? (string) $_GET['sig'] : '';

    if ($nodeId <= 0 || $studyUid === '' || $seriesUid === '' || $sopUid === '' || $exp <= 0 || $sig === '') {
        wadoInstanceJsonError('Parámetros inválidos', 400);
    }

    if ($exp < time()) {
        wadoInstanceJsonError('Enlace expirado', 403);
    }

    $db = getDBConnection();
    if (!$db) {
        wadoInstanceJsonError('Error de base de datos', 500);
    }

    $secret = StudyHistoryManifestSigning::getOrCreateSecret($db);
    if (!StudyHistoryManifestSigning::verifyWadoInstance($nodeId, $studyUid, $seriesUid, $sopUid, $exp, $sig, $secret)) {
        wadoInstanceJsonError('Firma inválida', 403);
    }

    $cols = $db->query('SHOW COLUMNS FROM pacs_nodes')->fetchAll(PDO::FETCH_COLUMN);
    if (!is_array($cols) || !in_array('remote_open_mode', $cols, true)) {
        wadoInstanceJsonError('Migración remote_open_mode pendiente', 500);
    }

    $ns = $db->prepare('SELECT * FROM pacs_nodes WHERE id = ? AND is_active = 1');
    $ns->execute([$nodeId]);
    $node = $ns->fetch(PDO::FETCH_ASSOC);
    if (!$node) {
        wadoInstanceJsonError('Nodo no encontrado', 404);
    }

    $mode = isset($node['remote_open_mode']) ? (string) $node['remote_open_mode'] : 'dicomweb';
    if ($mode !== 'wado_manifest') {
        wadoInstanceJsonError('Nodo no autorizado para proxy WADO manifest', 403);
    }

    $wadoBase = isset($node['wado_uri_base']) ? trim((string) $node['wado_uri_base']) : '';
    if ($wadoBase === '') {
        wadoInstanceJsonError('Falta wado_uri_base en el nodo', 500);
    }

    $upstream = StudyHistoryManifestService::buildUpstreamWadoInstanceUrl($wadoBase, $studyUid, $seriesUid, $sopUid);

    if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
        $hStatus = 0;
        $ch = curl_init($upstream);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $headerLine) use (&$hStatus) {
            $len = strlen($headerLine);
            $line = rtrim($headerLine, "\r\n");
            if ($line !== '' && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $hStatus = (int) $m[1];
            }
            return $len;
        });
        curl_exec($ch);
        $httpCode = $hStatus > 0 ? $hStatus : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        StudyHistoryCors::sendAllowOriginAndHeaders();
        if ($httpCode === 200) {
            header('Content-Type: application/dicom');
            header('Cache-Control: private, no-store');
            http_response_code(200);
        } else {
            http_response_code($httpCode >= 400 ? $httpCode : 502);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'WADO upstream', 'http' => $httpCode, 'detail' => $cerr], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    $httpStatus = 0;
    $contentType = 'application/dicom';
    $sentHeaders = false;

    $ch = curl_init($upstream);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_HEADER, false);

    if (!empty($_SERVER['HTTP_RANGE'])) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Range: ' . $_SERVER['HTTP_RANGE']]);
    }

    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $headerLine) use (&$httpStatus, &$contentType) {
        $len = strlen($headerLine);
        $line = rtrim($headerLine, "\r\n");
        if ($line === '') {
            return $len;
        }
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $httpStatus = (int) $m[1];
        }
        if (preg_match('/^Content-Type:\s*(.+)$/i', $line, $m)) {
            $contentType = trim($m[1]);
        }
        if (preg_match('/^Content-Range:\s*(.+)$/i', $line, $m)) {
            header('Content-Range: ' . trim($m[1]));
        }
        return $len;
    });

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$sentHeaders, &$httpStatus, &$contentType) {
        if (!$sentHeaders) {
            if ($httpStatus !== 200 && $httpStatus !== 206) {
                return 0;
            }
            StudyHistoryCors::sendAllowOriginAndHeaders();
            header('Access-Control-Expose-Headers: Content-Length, Content-Range');
            header('Content-Type: ' . ($contentType !== '' ? $contentType : 'application/dicom'));
            header('Cache-Control: private, no-store');
            header('X-Content-Type-Options: nosniff');
            if ($httpStatus === 206) {
                http_response_code(206);
            } else {
                http_response_code(200);
            }
            $sentHeaders = true;
        }
        echo $data;
        if (function_exists('ob_get_level') && ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
        return strlen($data);
    });

    $ok = curl_exec($ch);
    $cerr = curl_error($ch);
    curl_close($ch);

    if (!$sentHeaders) {
        $code = $httpStatus >= 400 ? $httpStatus : 502;
        if ($code < 400 || $code > 599) {
            $code = 502;
        }
        wadoInstanceJsonError('No se pudo obtener la instancia WADO: ' . ($cerr ?: ('HTTP ' . $httpStatus)), $code);
    }

    if ($ok === false) {
        error_log('[STUDY_HISTORY] wado-instance curl: ' . $cerr);
    }
} catch (Exception $e) {
    error_log('[STUDY_HISTORY] wado-instance: ' . $e->getMessage());
    if (!headers_sent()) {
        wadoInstanceJsonError($e->getMessage(), 500);
    }
}
