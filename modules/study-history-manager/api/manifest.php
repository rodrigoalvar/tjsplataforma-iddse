<?php
/**
 * Manifest JSON firmado para UDV (query manifestUrl). GET público; validación HMAC.
 */

ob_start();

require_once __DIR__ . '/../includes/StudyHistoryCors.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: ' . StudyHistoryCors::allowHeaders());
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: ' . StudyHistoryCors::allowHeaders());

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../pacs-nodes-manager/PacsNodeConfig.php';
require_once __DIR__ . '/../includes/StudyHistoryManifestService.php';
require_once __DIR__ . '/../includes/StudyHistoryManifestSigning.php';
require_once __DIR__ . '/../includes/StudyHistoryPublicUrl.php';

function manifestJsonError($msg, $code) {
    ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    StudyHistoryCors::sendAllowOriginAndHeaders();
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        manifestJsonError('Método no permitido', 405);
    }

    $studyUid = isset($_GET['s']) ? trim((string) $_GET['s']) : '';
    $nodeId = isset($_GET['n']) ? (int) $_GET['n'] : 0;
    $exp = isset($_GET['e']) ? (int) $_GET['e'] : 0;
    $sig = isset($_GET['sig']) ? (string) $_GET['sig'] : '';

    if ($studyUid === '' || $nodeId <= 0 || $exp <= 0 || $sig === '') {
        manifestJsonError('Parámetros inválidos', 400);
    }

    if ($exp < time()) {
        manifestJsonError('Enlace expirado', 403);
    }

    $db = getDBConnection();
    if (!$db) {
        manifestJsonError('Error de base de datos', 500);
    }

    $secret = StudyHistoryManifestSigning::getOrCreateSecret($db);
    if (!StudyHistoryManifestSigning::verify($studyUid, $nodeId, $exp, $sig, $secret)) {
        manifestJsonError('Firma inválida', 403);
    }

    $cols = $db->query('SHOW COLUMNS FROM pacs_nodes')->fetchAll(PDO::FETCH_COLUMN);
    if (!is_array($cols) || !in_array('remote_open_mode', $cols, true)) {
        manifestJsonError('Ejecute la migración migration_remote_open_mode.sql', 500);
    }

    $ns = $db->prepare('SELECT * FROM pacs_nodes WHERE id = ? AND is_active = 1');
    $ns->execute([$nodeId]);
    $node = $ns->fetch(PDO::FETCH_ASSOC);
    if (!$node) {
        manifestJsonError('Nodo no encontrado', 404);
    }

    $mode = isset($node['remote_open_mode']) ? (string) $node['remote_open_mode'] : 'dicomweb';
    if ($mode !== 'wado_manifest') {
        manifestJsonError('Este nodo no está configurado para manifest UDV', 403);
    }

    if (!StudyHistoryManifestService::nodeSupportsDimseManifest($node)) {
        manifestJsonError('El nodo no soporta inventario DIMSE (tipo o datos incompletos)', 400);
    }

    if (($node['node_type'] === 'dimse' || $node['node_type'] === 'hybrid')
        && empty($node['orthanc_node_id'])
        && !empty($node['aet']) && !empty($node['host']) && !empty($node['port'])) {
        try {
            $nodeConfig = new PacsNodeConfig($db);
            $syncResult = $nodeConfig->syncNodeWithOrthanc($node);
            if (!empty($syncResult['success']) && !empty($syncResult['orthanc_id'])) {
                $db->prepare('UPDATE pacs_nodes SET orthanc_node_id = ?, last_sync = NOW() WHERE id = ?')
                    ->execute([$syncResult['orthanc_id'], $nodeId]);
                $node['orthanc_node_id'] = $syncResult['orthanc_id'];
            }
        } catch (Exception $e) {
            error_log('[STUDY_HISTORY] manifest sync: ' . $e->getMessage());
        }
    }

    $publicBase = StudyHistoryPublicUrl::getPortalPublicBase($db);
    $manifest = StudyHistoryManifestService::buildManifest($db, $node, $studyUid, [
        'use_wado_proxy' => true,
        'public_base' => $publicBase,
        'hmac_secret' => $secret,
        'node_id' => $nodeId,
        'wado_instance_exp' => time() + 86400
    ]);

    ob_end_clean();
    echo json_encode($manifest, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    ob_end_clean();
    error_log('[STUDY_HISTORY] manifest: ' . $e->getMessage());
    manifestJsonError($e->getMessage(), 500);
}
