<?php
/**
 * Web encolado automático R2 (llamado desde Orthanc Lua OnStableStudy).
 *
 * POST JSON (recomendado):
 *   orthanc_study_id (requerido)
 *   study_instance_uid, modality (ModalitiesInStudy o Modality), patient_id, remote_aet (opcionales)
 * Si "modality" viene informada, se filtra sin consultar Orthanc (fast path); si no, fallback a Orthanc API.
 *
 * Header: Authorization: Bearer <r2_auto_enqueue_secret>
 *     o: X-R2-Auto-Enqueue-Token: <secret>
 *
 * Log dedicado: logs/r2-auto-enqueue.log (JSON por línea; visible en pestaña Logs del módulo).
 *
 * Compatibilidad: cuerpo solo con UUID en texto plano (Lua antiguo).
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-R2-Auto-Enqueue-Token');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../config/cloud_storage_config.php';
CloudStorageConfig::clearCache();
$config = CloudStorageConfig::load();

/**
 * Una línea JSON en logs/r2-auto-enqueue.log (sin datos sensibles).
 */
function r2_auto_enqueue_file_log(string $event, array $context = []): void
{
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return;
    }
    $path = $dir . '/r2-auto-enqueue.log';
    $row = array_merge([
        'ts' => date('c'),
        'event' => $event,
    ], $context);
    @file_put_contents($path, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * @return array{raw: string, payload: array, study_id: ?string}
 */
function r2_auto_enqueue_parse_request(): array
{
    $rawBody = file_get_contents('php://input');
    if ($rawBody === false) {
        $rawBody = '';
    }
    $payload = [];
    if ($rawBody !== '') {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    $studyId = null;
    if (!empty($payload['orthanc_study_id']) && is_string($payload['orthanc_study_id'])) {
        $studyId = trim($payload['orthanc_study_id']);
    } elseif (!empty($payload['study_id']) && is_string($payload['study_id'])) {
        $studyId = trim($payload['study_id']);
    } elseif (!empty($payload['orthanc_id']) && is_string($payload['orthanc_id'])) {
        $studyId = trim($payload['orthanc_id']);
    }

    if (($studyId === null || $studyId === '') && is_string($rawBody)) {
        $t = trim($rawBody);
        if (preg_match('/^[a-f0-9\-]{10,64}$/i', $t)) {
            $studyId = $t;
        }
    }

    if (($studyId === null || $studyId === '') && isset($_POST['orthanc_study_id']) && is_string($_POST['orthanc_study_id'])) {
        $studyId = trim($_POST['orthanc_study_id']);
    }

    return ['raw' => $rawBody, 'payload' => $payload, 'study_id' => $studyId];
}

/**
 * Modalidades desde string DICOM/Orthanc: separadores \ , ; y espacios.
 *
 * @return string[] valores únicos en mayúsculas
 */
function r2_auto_enqueue_modalities_from_string(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    $normalized = str_replace('\\', ',', $raw);
    $parts = preg_split('/[,;\s]+/', strtoupper($normalized), -1, PREG_SPLIT_NO_EMPTY);
    $set = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '' && $p !== 'N/A') {
            $set[$p] = true;
        }
    }

    return array_keys($set);
}

/**
 * Lista permitida desde configuración (misma normalización).
 *
 * @return string[]
 */
function r2_auto_enqueue_allowed_modalities(string $configRaw): array
{
    return r2_auto_enqueue_modalities_from_string($configRaw);
}

/**
 * @param object $client OrthancClient
 * @return string[] modalidades únicas en mayúsculas
 */
function r2_auto_enqueue_study_modalities_from_orthanc($client, string $studyId): array
{
    $details = $client->getStudyDetails($studyId);
    if (!$details) {
        return [];
    }
    $mod = (string) ($details['modality'] ?? '');

    return r2_auto_enqueue_modalities_from_string($mod);
}

/**
 * @return int[] ids únicos > 0
 */
function r2_auto_enqueue_parse_node_ids(string $raw): array
{
    $parts = preg_split('/[,\s;]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach ($parts as $p) {
        $n = (int) $p;
        if ($n > 0) {
            $out[$n] = true;
        }
    }
    return array_map('intval', array_keys($out));
}

/**
 * Conteo en Orthanc local por /studies/{id}/instances.
 */
function r2_auto_enqueue_count_instances_orthanc(string $studyId): ?int
{
    $orthancConfig = __DIR__ . '/../../../api/config/orthanc_config.php';
    if (!file_exists($orthancConfig)) {
        return null;
    }
    require_once $orthancConfig;
    if (!class_exists('OrthancConfig')) {
        return null;
    }
    $baseUrl = OrthancConfig::getServerUrl();
    $credentials = OrthancConfig::getCredentials();

    $ch = curl_init(rtrim($baseUrl, '/') . '/studies/' . rawurlencode($studyId) . '/instances');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, $credentials['username'] . ':' . $credentials['password']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200 || !is_string($response)) {
        return null;
    }
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return null;
    }
    return count($decoded);
}

/**
 * @return array{count:?int, details:array, source:string}
 */
function r2_auto_enqueue_instances_count_from_source(array $config, array $payload, string $studyId): array
{
    $minInstances = max(0, (int) ($config['r2_auto_enqueue_min_instances'] ?? 0));
    $source = (string) ($config['r2_auto_enqueue_instances_source'] ?? 'orthanc');
    $source = in_array($source, ['orthanc', 'pacs_nodes'], true) ? $source : 'orthanc';
    $details = [];

    $orthancCount = r2_auto_enqueue_count_instances_orthanc($studyId);
    $details['orthanc_count'] = $orthancCount;

    if ($minInstances <= 0) {
        return ['count' => $orthancCount, 'details' => $details, 'source' => $source];
    }

    if ($source !== 'pacs_nodes') {
        return ['count' => $orthancCount, 'details' => $details, 'source' => 'orthanc'];
    }

    $studyUid = '';
    if (!empty($payload['study_instance_uid']) && is_string($payload['study_instance_uid'])) {
        $studyUid = trim($payload['study_instance_uid']);
    }
    if ($studyUid === '') {
        $orthancConfig = __DIR__ . '/../../../api/config/orthanc_config.php';
        if (file_exists($orthancConfig)) {
            require_once $orthancConfig;
            if (class_exists('OrthancConfig')) {
                $baseUrl = OrthancConfig::getServerUrl();
                $credentials = OrthancConfig::getCredentials();
                $ch = curl_init(rtrim($baseUrl, '/') . '/studies/' . rawurlencode($studyId));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                curl_setopt($ch, CURLOPT_USERPWD, $credentials['username'] . ':' . $credentials['password']);
                curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                $response = curl_exec($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($httpCode === 200 && is_string($response)) {
                    $decoded = json_decode($response, true);
                    $studyUid = trim((string) ($decoded['MainDicomTags']['StudyInstanceUID'] ?? ''));
                }
            }
        }
    }
    if ($studyUid === '') {
        $details['pacs_error'] = 'study_instance_uid_missing';
        return ['count' => $orthancCount, 'details' => $details, 'source' => 'orthanc'];
    }

    $nodeIds = r2_auto_enqueue_parse_node_ids((string) ($config['r2_auto_enqueue_pacs_node_ids'] ?? ''));
    if ($nodeIds === []) {
        $details['pacs_error'] = 'no_nodes_selected';
        return ['count' => $orthancCount, 'details' => $details, 'source' => 'orthanc'];
    }

    require_once __DIR__ . '/../../../config/database.php';
    require_once __DIR__ . '/../../pacs-nodes-manager/PacsNodeClient.php';

    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        $details['pacs_error'] = 'db_connection_failed';
        return ['count' => $orthancCount, 'details' => $details, 'source' => 'orthanc'];
    }

    $inPlaceholders = implode(',', array_fill(0, count($nodeIds), '?'));
    $stmt = $db->prepare("
        SELECT id, name, node_type, aet, host, port, dicomweb_url, orthanc_node_id, is_active, allow_find
        FROM pacs_nodes
        WHERE id IN ($inPlaceholders) AND is_active = 1
    ");
    $stmt->execute($nodeIds);
    $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$nodes) {
        $details['pacs_error'] = 'selected_nodes_not_found_or_inactive';
        return ['count' => $orthancCount, 'details' => $details, 'source' => 'orthanc'];
    }

    $client = new PacsNodeClient($db);
    $maxCount = null;
    $nodeCounts = [];
    $nodeErrors = [];
    foreach ($nodes as $node) {
        if (isset($node['allow_find']) && (int)$node['allow_find'] === 0) {
            $nodeErrors[] = ['node_id' => (int)$node['id'], 'error' => 'allow_find_disabled'];
            continue;
        }
        try {
            $query = [
                'Level' => 'Study',
                'Query' => ['StudyInstanceUID' => $studyUid],
                'limit' => 1,
            ];
            $results = $client->executeCFind($node, $query);
            $count = null;
            if (!empty($results) && is_array($results)) {
                $first = $results[0];
                if (is_array($first) && isset($first['NumberOfStudyRelatedInstances'])) {
                    $count = (int) $first['NumberOfStudyRelatedInstances'];
                }
            }
            if ($count === null) {
                $nodeErrors[] = ['node_id' => (int)$node['id'], 'error' => 'instances_not_returned'];
                continue;
            }
            $nodeCounts[] = ['node_id' => (int)$node['id'], 'count' => $count];
            $maxCount = ($maxCount === null) ? $count : max($maxCount, $count);
        } catch (Throwable $e) {
            $nodeErrors[] = ['node_id' => (int)$node['id'], 'error' => $e->getMessage()];
        }
    }

    $details['pacs_nodes'] = $nodeCounts;
    $details['pacs_errors'] = $nodeErrors;
    $details['pacs_selected_node_ids'] = $nodeIds;
    $details['study_instance_uid'] = $studyUid;
    $details['aggregation'] = 'max';

    if ($maxCount === null) {
        $details['pacs_error'] = 'all_nodes_failed_or_no_count';
        return ['count' => $orthancCount, 'details' => $details, 'source' => 'orthanc'];
    }

    return ['count' => $maxCount, 'details' => $details, 'source' => 'pacs_nodes'];
}

function r2_auto_enqueue_read_token(): ?string
{
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (is_string($auth) && preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
        return $m[1];
    }
    $x = $_SERVER['HTTP_X_R2_AUTO_ENQUEUE_TOKEN'] ?? '';
    if (is_string($x) && $x !== '') {
        return trim($x);
    }

    return null;
}

try {
    $secret = trim((string) ($config['r2_auto_enqueue_secret'] ?? ''));
    $token = r2_auto_enqueue_read_token();

    if ($secret === '') {
        r2_auto_enqueue_file_log('secret_not_configured', [
            'http_status' => 503,
        ]);
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'error' => 'Encolado automático: configure r2_auto_enqueue_secret en Cloud Storage',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($token === null || $token === '' || !hash_equals($secret, $token)) {
        r2_auto_enqueue_file_log('auth_failed', [
            'http_status' => 403,
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No autorizado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $req = r2_auto_enqueue_parse_request();
    $payload = $req['payload'];
    $studyId = $req['study_id'];

    $patientIdLog = '';
    if (!empty($payload['patient_id']) && is_string($payload['patient_id'])) {
        $patientIdLog = trim($payload['patient_id']);
    }
    $remoteAet = '';
    if (!empty($payload['remote_aet']) && is_string($payload['remote_aet'])) {
        $remoteAet = trim($payload['remote_aet']);
    }
    $studyUidLog = '';
    if (!empty($payload['study_instance_uid']) && is_string($payload['study_instance_uid'])) {
        $studyUidLog = trim($payload['study_instance_uid']);
    }
    $modalityRawLog = '';
    if (!empty($payload['modality']) && is_string($payload['modality'])) {
        $modalityRawLog = $payload['modality'];
    }

    if (empty($config['r2_auto_enqueue_enabled'])) {
        r2_auto_enqueue_file_log('skipped_auto_enqueue_disabled', [
            'orthanc_study_id' => $studyId,
            'study_instance_uid' => $studyUidLog,
            'remote_aet' => $remoteAet,
            'modality_lua' => $modalityRawLog,
        ]);
        echo json_encode([
            'success' => true,
            'skipped' => true,
            'reason' => 'auto_enqueue_disabled',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($config['r2_enabled'])) {
        r2_auto_enqueue_file_log('skipped_r2_disabled', [
            'orthanc_study_id' => $studyId,
            'http_status' => 503,
        ]);
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'error' => 'R2 no está habilitado en la configuración',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($studyId === null || $studyId === '') {
        r2_auto_enqueue_file_log('missing_orthanc_study_id', [
            'remote_aet' => $remoteAet,
            'has_json_body' => $req['raw'] !== '' && $payload !== [],
        ]);
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Falta orthanc_study_id'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $allowed = r2_auto_enqueue_allowed_modalities((string) ($config['r2_auto_enqueue_modalities'] ?? ''));
    if ($allowed === []) {
        r2_auto_enqueue_file_log('skipped_no_modalities_configured', [
            'orthanc_study_id' => $studyId,
            'study_instance_uid' => $studyUidLog,
            'remote_aet' => $remoteAet,
        ]);
        echo json_encode([
            'success' => true,
            'skipped' => true,
            'reason' => 'no_modalities_configured',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $modalityPayloadRaw = '';
    if (!empty($payload['modality']) && is_string($payload['modality'])) {
        $modalityPayloadRaw = $payload['modality'];
    } elseif (!empty($payload['ModalitiesInStudy']) && is_string($payload['ModalitiesInStudy'])) {
        $modalityPayloadRaw = $payload['ModalitiesInStudy'];
    } elseif (!empty($payload['modalities_in_study']) && is_string($payload['modalities_in_study'])) {
        $modalityPayloadRaw = $payload['modalities_in_study'];
    }

    $studyMods = r2_auto_enqueue_modalities_from_string($modalityPayloadRaw);
    $modalitySource = 'orthanc';

    if ($studyMods !== []) {
        $modalitySource = 'payload';
    } else {
        require_once __DIR__ . '/../../../api/OrthancClient.php';
        $orthanc = new OrthancClient();
        $studyMods = r2_auto_enqueue_study_modalities_from_orthanc($orthanc, $studyId);
    }

    if ($studyMods === []) {
        r2_auto_enqueue_file_log('study_not_found_or_no_modality', [
            'orthanc_study_id' => $studyId,
            'study_instance_uid' => $studyUidLog,
            'remote_aet' => $remoteAet,
            'modality_source_attempted' => $modalitySource,
            'http_status' => 404,
        ]);
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Estudio no encontrado en Orthanc o sin modalidad',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    error_log(sprintf(
        '[CLOUD_STORAGE][AUTO_ENQUEUE] orthanc_id=%s modality_source=%s remote_aet=%s study_uid=%s modalities=%s',
        $studyId,
        $modalitySource,
        $remoteAet !== '' ? $remoteAet : '-',
        $studyUidLog !== '' ? $studyUidLog : '-',
        implode(',', $studyMods)
    ));

    $match = false;
    foreach ($studyMods as $m) {
        if (in_array($m, $allowed, true)) {
            $match = true;
            break;
        }
    }

    if (!$match) {
        r2_auto_enqueue_file_log('skipped_modality_not_matched', [
            'orthanc_study_id' => $studyId,
            'study_instance_uid' => $studyUidLog,
            'patient_id' => $patientIdLog,
            'remote_aet' => $remoteAet,
            'modality_source' => $modalitySource,
            'study_modalities' => $studyMods,
            'allowed_modalities' => $allowed,
        ]);
        echo json_encode([
            'success' => true,
            'skipped' => true,
            'reason' => 'modality_not_matched',
            'study_modalities' => $studyMods,
            'allowed_modalities' => $allowed,
            'modality_source' => $modalitySource,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $minInstances = max(0, (int) ($config['r2_auto_enqueue_min_instances'] ?? 0));
    $instancesInfo = r2_auto_enqueue_instances_count_from_source($config, $payload, $studyId);
    $effectiveCount = $instancesInfo['count'];
    $countSource = $instancesInfo['source'];
    $countDetails = $instancesInfo['details'];
    // Fail-open: si no se pudo determinar conteo, no bloquear encolado.
    $meetsThreshold = ($minInstances <= 0) || ($effectiveCount === null) || ($effectiveCount >= $minInstances);

    // Si no cumple umbral, permitir de todas formas cuando se detecta complemento (re-sync):
    // comparación entre "conteo real" y total ya almacenado en R2.
    $allowResyncByDelta = false;
    $r2TotalInstances = null;
    if (!$meetsThreshold && $effectiveCount !== null) {
        try {
            require_once __DIR__ . '/../../../config/database.php';
            $database = new Database();
            $db = $database->getConnection();
            if ($db) {
                $s = $db->prepare('
                    SELECT r2_status, total_instances
                    FROM r2_studies
                    WHERE orthanc_study_id = ?
                    LIMIT 1
                ');
                $s->execute([$studyId]);
                $row = $s->fetch(PDO::FETCH_ASSOC);
                if ($row && ($row['r2_status'] ?? '') === 'online') {
                    $r2TotalInstances = (int) ($row['total_instances'] ?? 0);
                    $allowResyncByDelta = $effectiveCount > $r2TotalInstances;
                }
            }
        } catch (Throwable $e) {
            $countDetails['r2_lookup_error'] = $e->getMessage();
        }
    }

    if (!$meetsThreshold && !$allowResyncByDelta) {
        r2_auto_enqueue_file_log('skipped_instances_threshold_not_met', [
            'orthanc_study_id' => $studyId,
            'study_instance_uid' => $studyUidLog,
            'patient_id' => $patientIdLog,
            'remote_aet' => $remoteAet,
            'instances_source' => $countSource,
            'instances_effective' => $effectiveCount,
            'instances_threshold' => $minInstances,
            'instances_details' => $countDetails,
        ]);
        echo json_encode([
            'success' => true,
            'skipped' => true,
            'reason' => 'instances_threshold_not_met',
            'instances_source' => $countSource,
            'instances_effective' => $effectiveCount,
            'instances_threshold' => $minInstances,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    require_once __DIR__ . '/../CloudStorageManager.php';
    $manager = new CloudStorageManager();
    $result = $manager->enqueueStudy($studyId);

    $isResync = !empty($result['resync']);
    r2_auto_enqueue_file_log($isResync ? 'enqueued_resync' : 'enqueued', [
        'orthanc_study_id' => $studyId,
        'study_instance_uid' => $studyUidLog,
        'patient_id' => $patientIdLog,
        'remote_aet' => $remoteAet,
        'modality_source' => $modalitySource,
        'study_modalities' => $studyMods,
        'instances_source' => $countSource,
        'instances_effective' => $effectiveCount,
        'instances_threshold' => $minInstances,
        'instances_details' => $countDetails,
        'resync_delta_override' => $allowResyncByDelta,
        'r2_total_instances' => $r2TotalInstances,
        'queue_id' => $result['queue_id'] ?? null,
        'enqueue_message' => $result['message'] ?? '',
        'resync' => $isResync,
    ]);

    echo json_encode([
        'success' => true,
        'skipped' => false,
        'modality_source' => $modalitySource,
        'instances_source' => $countSource,
        'instances_effective' => $effectiveCount,
        'instances_threshold' => $minInstances,
        'enqueue' => $result,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[CLOUD_STORAGE][AUTO_ENQUEUE] ' . $e->getMessage());
    r2_auto_enqueue_file_log('exception', [
        'message' => $e->getMessage(),
        'http_status' => 500,
    ]);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error interno',
    ], JSON_UNESCAPED_UNICODE);
}
