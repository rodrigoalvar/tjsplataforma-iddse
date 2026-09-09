<?php
/**
 * URL de visor para Cross Sync / PACS Nodes (misma lógica que study-history viewer-link).
 * Autenticación: pacs_nodes_manager
 */

ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../api/config/orthanc_config.php';
require_once __DIR__ . '/../../study-history-manager/includes/OrthancUidLookup.php';
require_once __DIR__ . '/../../study-history-manager/includes/RemoteViewerUrlBuilder.php';
require_once __DIR__ . '/../PacsNodeConfig.php';
require_once __DIR__ . '/../../study-history-manager/includes/StudyHistoryPublicUrl.php';
require_once __DIR__ . '/../../study-history-manager/includes/StudyHistoryManifestSigning.php';
require_once __DIR__ . '/../../study-history-manager/includes/StudyHistoryManifestService.php';

function pnmViewerOpenJsonError($message, $code = 400) {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json');
    }
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function pnmViewerOpenJsonSuccess(array $data) {
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode(array_merge(['success' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $user = requirePacsNodesAuth('pacs_nodes_manager');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        pnmViewerOpenJsonError('Método no permitido', 405);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        pnmViewerOpenJsonError('JSON inválido', 400);
    }

    $uid = trim((string) ($data['study_instance_uid'] ?? ''));
    if ($uid === '') {
        pnmViewerOpenJsonError('study_instance_uid es requerido', 400);
    }

    $sourceNodeId = isset($data['source_node_id']) ? (int) $data['source_node_id'] : 0;
    $clientClaimsLocal = !empty($data['is_on_local_pacs']);

    $db = getDBConnection();
    if (!$db) {
        pnmViewerOpenJsonError('No se pudo conectar a la base de datos', 500);
    }

    $selectedNodeIsLocal = null;
    if ($sourceNodeId > 0) {
        $chk = $db->prepare('SELECT node_type FROM pacs_nodes WHERE id = ? AND is_active = 1 LIMIT 1');
        $chk->execute([$sourceNodeId]);
        $nr = $chk->fetch(PDO::FETCH_ASSOC);
        if ($nr) {
            $selectedNodeIsLocal = (($nr['node_type'] ?? '') === 'local');
        }
    }

    $viewerStmt = $db->prepare('SELECT dicom_viewer FROM usuarios WHERE id = ? AND activo = 1 LIMIT 1');
    $viewerStmt->execute([(int) $user['id']]);
    $vr = $viewerStmt->fetch(PDO::FETCH_ASSOC);
    $viewerType = 'UDV';
    if ($vr && !empty(trim((string) $vr['dicom_viewer']))) {
        $viewerType = trim((string) $vr['dicom_viewer']);
    }
    if (!in_array($viewerType, ['UDV', 'StoneViewer', 'Oviyam'], true)) {
        $viewerType = 'UDV';
    }

    $orthancIds = OrthancUidLookup::findStudyIdsByInstanceUid($uid);
    $inLocalOrthanc = count($orthancIds) > 0;

    $openLocalOrthanc = $inLocalOrthanc
        && ($sourceNodeId <= 0 || $selectedNodeIsLocal === true);

    if ($openLocalOrthanc) {
        $studyId = $orthancIds[0];
        $url = OrthancConfig::getViewerUrl($studyId, $viewerType, $uid);
        ob_end_clean();
        pnmViewerOpenJsonSuccess([
            'open_url' => $url,
            'strategy' => 'local_orthanc',
            'orthanc_study_id' => $studyId,
            'viewer_type' => $viewerType
        ]);
    }

    if ($clientClaimsLocal && !$inLocalOrthanc && ($sourceNodeId <= 0 || $selectedNodeIsLocal === true)) {
        ob_end_clean();
        pnmViewerOpenJsonSuccess([
            'open_url' => null,
            'strategy' => 'local_missing',
            'viewer_type' => $viewerType,
            'hint' => 'El estudio no está en el PACS local (Orthanc) para este UID.'
        ]);
    }

    if ($sourceNodeId <= 0) {
        ob_end_clean();
        pnmViewerOpenJsonSuccess([
            'open_url' => null,
            'strategy' => 'remote_unknown_node',
            'hint' => 'Indique source_node_id para generar enlace remoto.'
        ]);
    }

    $ns = $db->prepare('SELECT * FROM pacs_nodes WHERE id = ? AND is_active = 1');
    $ns->execute([$sourceNodeId]);
    $node = $ns->fetch(PDO::FETCH_ASSOC);
    if (!$node) {
        pnmViewerOpenJsonError('Nodo no encontrado o inactivo', 404);
    }

    $remoteMode = isset($node['remote_open_mode']) ? trim((string) $node['remote_open_mode']) : 'dicomweb';
    if (!in_array($remoteMode, ['dicomweb', 'wado_manifest'], true)) {
        $remoteMode = 'dicomweb';
    }

    if ($viewerType === 'UDV' && $remoteMode === 'wado_manifest') {
        if (!StudyHistoryManifestService::nodeSupportsDimseManifest($node)) {
            ob_end_clean();
            pnmViewerOpenJsonSuccess([
                'open_url' => null,
                'strategy' => 'remote_udv_manifest_unsupported_node',
                'viewer_type' => $viewerType,
                'hint' => 'El manifest UDV requiere un nodo DIMSE u híbrido con AET/host (no DICOMweb puro).'
            ]);
        }
        $wado = isset($node['wado_uri_base']) ? trim((string) $node['wado_uri_base']) : '';
        if ($wado === '') {
            ob_end_clean();
            pnmViewerOpenJsonSuccess([
                'open_url' => null,
                'strategy' => 'remote_udv_manifest_no_wado',
                'viewer_type' => $viewerType,
                'hint' => 'Configure wado_uri_base en el nodo para generar URLs WADO por instancia en el manifest.'
            ]);
        }
        if (($node['node_type'] === 'dimse' || $node['node_type'] === 'hybrid')
            && empty($node['orthanc_node_id'])
            && !empty($node['aet']) && !empty($node['host']) && !empty($node['port'])) {
            try {
                $nodeConfig = new PacsNodeConfig($db);
                $syncResult = $nodeConfig->syncNodeWithOrthanc($node);
                if (!empty($syncResult['success']) && !empty($syncResult['orthanc_id'])) {
                    $db->prepare('UPDATE pacs_nodes SET orthanc_node_id = ?, last_sync = NOW() WHERE id = ?')
                        ->execute([$syncResult['orthanc_id'], $sourceNodeId]);
                    $node['orthanc_node_id'] = $syncResult['orthanc_id'];
                }
            } catch (Exception $e) {
                error_log('[PACS_NODES] viewer-open sync: ' . $e->getMessage());
            }
        }
        $cfg = OrthancConfig::getConfig();
        $udvCfg = $cfg['viewer']['viewers']['UDV'] ?? [];
        $udvBase = isset($udvCfg['url']) ? trim((string) $udvCfg['url']) : '';
        if ($udvBase === '') {
            ob_end_clean();
            pnmViewerOpenJsonSuccess([
                'open_url' => null,
                'strategy' => 'remote_udv_no_base',
                'viewer_type' => $viewerType,
                'hint' => 'Falta URL de UDV en configuración PACS (pacs_viewer_udv_url).'
            ]);
        }
        $secret = StudyHistoryManifestSigning::getOrCreateSecret($db);
        $exp = time() + 900;
        $sig = StudyHistoryManifestSigning::sign($uid, $sourceNodeId, $exp, $secret);
        $publicBase = StudyHistoryPublicUrl::getPortalPublicBase($db);
        $manifestHref = $publicBase . '/modules/study-history-manager/api/manifest.php?' . http_build_query([
            's' => $uid,
            'n' => $sourceNodeId,
            'e' => $exp,
            'sig' => $sig
        ], '', '&', PHP_QUERY_RFC3986);
        $open = StudyHistoryPublicUrl::appendQueryParam($udvBase, 'manifestUrl', $manifestHref);
        ob_end_clean();
        pnmViewerOpenJsonSuccess([
            'open_url' => $open,
            'strategy' => 'remote_udv_manifest',
            'viewer_type' => $viewerType,
            'hint' => '',
            'source_node_id' => $sourceNodeId
        ]);
    }

    $built = RemoteViewerUrlBuilder::build($node, $viewerType, $uid);
    ob_end_clean();
    pnmViewerOpenJsonSuccess([
        'open_url' => $built['url'],
        'strategy' => $built['strategy'],
        'viewer_type' => $viewerType,
        'hint' => $built['hint'],
        'source_node_id' => $sourceNodeId
    ]);
} catch (Exception $e) {
    ob_end_clean();
    error_log('[PACS_NODES] viewer-open: ' . $e->getMessage());
    pnmViewerOpenJsonError($e->getMessage(), 500);
}
