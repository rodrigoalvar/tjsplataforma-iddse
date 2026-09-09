<?php
/**
 * Portal de Estudios v2 (Fase 2)
 * Resuelve URL de apertura final para un estudio según estrategia (local/r2/remoto).
 */

@error_reporting(0);
@ini_set('display_errors', 0);
@ini_set('log_errors', 1);
@ini_set('html_errors', 0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// __DIR__ = .../api/portal-estudios/v2 → ../../ = api/, ../../../ = raíz del proyecto
require_once __DIR__ . '/../../StudyRoutingService.php';
require_once __DIR__ . '/../../config/orthanc_config.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../modules/study-history-manager/includes/RemoteViewerUrlBuilder.php';
require_once __DIR__ . '/../../../modules/study-history-manager/includes/StudyHistoryManifestService.php';
require_once __DIR__ . '/../../../modules/study-history-manager/includes/StudyHistoryManifestSigning.php';
require_once __DIR__ . '/../../../modules/study-history-manager/includes/StudyHistoryPublicUrl.php';

function portalV2OpenJsonError($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function portalV2OpenGetDb() {
    if (function_exists('getDBConnection')) {
        return getDBConnection();
    }
    if (class_exists('Database')) {
        $db = new Database();
        return $db->getConnection();
    }
    return null;
}

function portalV2OpenCfg(PDO $db, $key, $default) {
    try {
        $stmt = $db->prepare("SELECT valor FROM configuracion WHERE clave = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && array_key_exists('valor', $row)) {
            return (string)$row['valor'];
        }
    } catch (Exception $e) {
        // noop
    }
    return (string)$default;
}

function portalV2ViewerForDevice(PDO $db, $device) {
    $device = strtolower(trim((string)$device));
    $key = $device === 'mobile' ? 'portal_estudios_viewer_mobile' : 'portal_estudios_viewer_desktop';
    $viewer = portalV2OpenCfg($db, $key, 'UDV');
    if (!in_array($viewer, ['UDV', 'StoneViewer', 'OHIF', 'Oviyam', 'VolView'], true)) {
        $viewer = 'UDV';
    }
    // En esta fase solo estos visores tienen builder operativo inmediato.
    if (!in_array($viewer, ['UDV', 'StoneViewer', 'Oviyam'], true)) {
        $viewer = 'UDV';
    }
    return $viewer;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        portalV2OpenJsonError('Método no permitido', 405);
    }
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        portalV2OpenJsonError('JSON inválido', 400);
    }

    $studyUid = trim((string)($data['study_instance_uid'] ?? ''));
    $orthancId = trim((string)($data['orthanc_id'] ?? ''));
    $strategyType = trim((string)($data['strategy_type'] ?? ''));
    $sourceNodeId = (int)($data['source_node_id'] ?? 0);
    $device = trim((string)($data['device'] ?? 'desktop'));

    if ($studyUid === '' && $orthancId === '') {
        portalV2OpenJsonError('Faltan datos del estudio', 400);
    }
    if (!in_array($strategyType, ['local', 'r2', 'remote'], true)) {
        portalV2OpenJsonError('Estrategia inválida', 400);
    }

    $db = portalV2OpenGetDb();
    if (!$db) {
        portalV2OpenJsonError('No se pudo conectar a la base de datos', 500);
    }

    $qaFile = __DIR__ . '/../../../modules/qa-publicacion/QaPublicationService.php';
    if (is_file($qaFile)) {
        require_once $qaFile;
        if (class_exists('QaPublicationService')
            && QaPublicationService::isInstalled($db)
            && QaPublicationService::isEnabled($db)
        ) {
            if ($studyUid !== '') {
                $qaRow = QaPublicationService::getStudyStatusByUid($db, $studyUid);
                if ($qaRow && ($qaRow['estado'] ?? '') === QaPublicationService::ESTADO_BLOQUEADO) {
                    portalV2OpenJsonError('Este estudio no está disponible en el portal del paciente.', 403);
                }
            }
        }
    }

    $viewerType = portalV2ViewerForDevice($db, $device);

    if ($strategyType === 'local') {
        if ($orthancId === '') {
            portalV2OpenJsonError('Orthanc ID requerido para estrategia local', 400);
        }
        $openUrl = OrthancConfig::getViewerUrl($orthancId, $viewerType, $studyUid ?: null, $device === 'mobile');
        echo json_encode([
            'success' => true,
            'open_url' => $openUrl,
            'strategy' => 'local',
            'viewer_type' => $viewerType
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($strategyType === 'r2') {
        if ($orthancId === '') {
            portalV2OpenJsonError('Orthanc ID requerido para estrategia R2', 400);
        }
        // Para R2 forzamos UDV para asegurar soporte de manifest.
        $localViewer = OrthancConfig::getViewerUrl($orthancId, 'UDV', $studyUid ?: null, $device === 'mobile');
        $localDl = StudyRoutingService::buildLocalOrthancArchiveUrl($orthancId, '', '', '', '');
        $r2Batch = StudyRoutingService::batchLoadR2Context($db, [$orthancId]);
        $r2row = $r2Batch[$orthancId] ?? [
            'orthanc_study_id' => $orthancId,
            'study_instance_uid' => $studyUid,
            'r2_status' => 'none',
            'r2_manifest_path' => null,
            'r2_zip_key' => null,
        ];
        $resolved = StudyRoutingService::resolve(
            $db,
            $orthancId,
            $studyUid,
            $localViewer,
            $localDl,
            'r2',
            $r2row
        );
        echo json_encode([
            'success' => true,
            'open_url' => (string)$resolved['viewer_url'],
            'download_url' => (string)$resolved['download_url'],
            'strategy' => 'r2',
            'viewer_type' => 'UDV',
            'study_routing' => $resolved['study_routing'] ?? null
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // remote
    if ($sourceNodeId <= 0) {
        portalV2OpenJsonError('source_node_id requerido para estrategia remota', 400);
    }
    if ($studyUid === '') {
        portalV2OpenJsonError('study_instance_uid requerido para estrategia remota', 400);
    }

    $stmt = $db->prepare('SELECT * FROM pacs_nodes WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$sourceNodeId]);
    $node = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$node) {
        portalV2OpenJsonError('Nodo no encontrado o inactivo', 404);
    }

    $remoteMode = isset($node['remote_open_mode']) ? trim((string)$node['remote_open_mode']) : 'dicomweb';
    if (!in_array($remoteMode, ['dicomweb', 'wado_manifest'], true)) {
        $remoteMode = 'dicomweb';
    }

    if ($viewerType === 'UDV' && $remoteMode === 'wado_manifest') {
        if (!StudyHistoryManifestService::nodeSupportsDimseManifest($node)) {
            portalV2OpenJsonError('Nodo no compatible con manifest DIMSE', 400);
        }
        $wado = trim((string)($node['wado_uri_base'] ?? ''));
        if ($wado === '') {
            portalV2OpenJsonError('Falta wado_uri_base para manifest remoto', 400);
        }

        $udvBase = trim((string)((OrthancConfig::getConfig()['viewer']['viewers']['UDV']['url'] ?? '')));
        if ($udvBase === '') {
            portalV2OpenJsonError('Falta URL de UDV en configuración', 500);
        }
        $secret = StudyHistoryManifestSigning::getOrCreateSecret($db);
        $exp = time() + 900;
        $sig = StudyHistoryManifestSigning::sign($studyUid, $sourceNodeId, $exp, $secret);
        $publicBase = StudyHistoryPublicUrl::getPortalPublicBase($db);
        $manifestHref = $publicBase . '/modules/study-history-manager/api/manifest.php?' . http_build_query([
            's' => $studyUid,
            'n' => $sourceNodeId,
            'e' => $exp,
            'sig' => $sig
        ], '', '&', PHP_QUERY_RFC3986);
        $openUrl = StudyHistoryPublicUrl::appendQueryParam($udvBase, 'manifestUrl', $manifestHref);

        echo json_encode([
            'success' => true,
            'open_url' => $openUrl,
            'strategy' => 'remote_udv_manifest',
            'viewer_type' => 'UDV'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Si Stone/Oviyam/UDV sin manifest, usar builder remoto genérico.
    $built = RemoteViewerUrlBuilder::build($node, $viewerType, $studyUid);
    if (empty($built['url'])) {
        // fallback seguro a UDV para no cortar experiencia en modo auto
        $fallback = RemoteViewerUrlBuilder::build($node, 'UDV', $studyUid);
        if (empty($fallback['url'])) {
            portalV2OpenJsonError($built['hint'] ?: 'No se pudo generar URL remota', 400);
        }
        echo json_encode([
            'success' => true,
            'open_url' => (string)$fallback['url'],
            'strategy' => 'remote_fallback_udv',
            'viewer_type' => 'UDV',
            'hint' => $built['hint'] ?? ''
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        'success' => true,
        'open_url' => (string)$built['url'],
        'strategy' => 'remote',
        'viewer_type' => $viewerType,
        'hint' => $built['hint'] ?? ''
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    error_log('[PORTAL_V2] open error: ' . $e->getMessage());
    portalV2OpenJsonError('Error interno del servidor', 500);
}

