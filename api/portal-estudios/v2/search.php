<?php
/**
 * Portal de Estudios v2 (Fase 1, backend paralelo)
 * Busca estudios por paciente y devuelve listado unificado con estrategia de apertura
 * (R2 / Local / Remoto), sin impactar el flujo legacy.
 */

@error_reporting(0);
@ini_set('display_errors', 0);
@ini_set('log_errors', 1);
@ini_set('html_errors', 0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

while (@ob_get_level()) {
    @ob_end_clean();
}
@ob_start();

// __DIR__ = .../api/portal-estudios/v2 → ../../ = api/
require_once __DIR__ . '/../../OrthancClient.php';
require_once __DIR__ . '/../../StudyRoutingService.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../modules/pacs-nodes-manager/PacsNodeClient.php';
require_once __DIR__ . '/../../OrthancPacsSender.php';
require_once __DIR__ . '/InformeViewStrategy.php';

function portalV2JsonError($error, $code = 500) {
    while (@ob_get_level()) {
        @ob_end_clean();
    }
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error' => $error
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function portalV2GetDb() {
    if (function_exists('getDBConnection')) {
        return getDBConnection();
    }
    if (class_exists('Database')) {
        $db = new Database();
        return $db->getConnection();
    }
    return null;
}

function portalV2GetConfig(PDO $db, $key, $default) {
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

/** Normaliza flags guardados como 1/0 o como etiquetas (Sí/No) a '1' o '0'. */
function portalV2BoolTo01($raw, $default = '0') {
    $v = trim((string)$raw);
    if ($v === '') {
        return $default;
    }
    $t = function_exists('mb_strtolower')
        ? mb_strtolower($v, 'UTF-8')
        : strtolower($v);
    if (in_array($t, ['1', 'true', 'yes', 'on', 'si', 'sí', 'y'], true)) {
        return '1';
    }
    if (in_array($t, ['0', 'false', 'no', 'off', 'n'], true)) {
        return '0';
    }
    return $default;
}

function portalV2ResolvePatientId(PDO $db, $patientId, $searchType) {
    $patientId = trim((string)$patientId);
    if ($patientId === '') {
        portalV2JsonError('ID de paciente requerido', 400);
    }

    if ($searchType !== 'id_interno') {
        // Documento / idpaciente
        try {
            $stmt = $db->prepare("SELECT idpaciente, activo FROM pacientes WHERE idpaciente = ? LIMIT 1");
            $stmt->execute([$patientId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && (int)($row['activo'] ?? 1) === 0) {
                portalV2JsonError('El paciente está inactivo y no puede buscar estudios.', 403);
            }
        } catch (Exception $e) {
            // no bloquear por validación auxiliar
        }
        return [$patientId, null];
    }

    $stmt = $db->prepare("SELECT id_interno, idpaciente, nombre, activo FROM pacientes WHERE id_interno = ? LIMIT 1");
    $stmt->execute([$patientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        portalV2JsonError('No se encontró un paciente con ID Interno: ' . $patientId, 404);
    }
    if ((int)($row['activo'] ?? 1) === 0) {
        portalV2JsonError('El paciente está inactivo y no puede buscar estudios.', 403);
    }
    $idpacs = trim((string)($row['idpaciente'] ?? ''));
    if ($idpacs === '') {
        portalV2JsonError('El paciente no tiene ID PACS asociado.', 404);
    }
    return [$idpacs, $row];
}

function portalV2GetRemoteStudies(PDO $db, $patientId, $strictNodeId, $listingMode) {
    $out = [];
    if (!in_array($listingMode, ['remote', 'mixed'], true) && $strictNodeId <= 0) {
        return $out;
    }

    $sql = "SELECT * FROM pacs_nodes WHERE is_active = 1";
    $params = [];
    if ($strictNodeId > 0) {
        $sql .= " AND id = ?";
        $params[] = $strictNodeId;
    }
    $sql .= " ORDER BY (CASE WHEN node_type = 'local' THEN 0 ELSE 1 END), id ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$nodes) {
        return $out;
    }

    $client = new PacsNodeClient($db);
    $query = [
        'Level' => 'Study',
        'Query' => ['PatientID' => $patientId]
    ];

    foreach ($nodes as $node) {
        try {
            $results = $client->executeCFind($node, $query);
        } catch (Exception $e) {
            continue;
        }
        foreach ($results as $row) {
            if (!is_array($row)) {
                continue;
            }
            $uid = trim((string)($row['StudyInstanceUID'] ?? ''));
            if ($uid === '') {
                continue;
            }
            if (!isset($out[$uid])) {
                $out[$uid] = [
                    'study_instance_uid' => $uid,
                    'patient_id' => (string)($row['PatientID'] ?? ''),
                    'patient_name' => (string)($row['PatientName'] ?? ''),
                    'study_date' => (string)($row['StudyDate'] ?? ''),
                    'study_time' => (string)($row['StudyTime'] ?? ''),
                    'study_description' => (string)($row['StudyDescription'] ?? ''),
                    'modality' => (string)($row['ModalitiesInStudy'] ?? ''),
                    'orthanc_id' => null,
                    'sources' => []
                ];
            }
            $out[$uid]['sources'][] = [
                'type' => (($node['node_type'] ?? '') === 'local') ? 'local' : 'remote',
                'node_id' => (int)$node['id'],
                'node_name' => (string)($node['name'] ?? ''),
                'node_type' => (string)($node['node_type'] ?? ''),
                'is_local' => (($node['node_type'] ?? '') === 'local')
            ];
        }
    }

    return $out;
}

function portalV2ColumnExists(PDO $db, string $table, string $column): bool {
    try {
        $stmt = $db->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
        );
        $stmt->execute([$table, $column]);
        return (bool) $stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Informes finalizados visibles en portal v2: PDF en disco y/o refs PACS.
 *
 * @return array<int, array<string, mixed>>
 */
function portalV2FetchInformesForPortal(PDO $db, $patientId) {
    $out = [];
    try {
        if (!portalV2ColumnExists($db, 'informes', 'id')) {
            return $out;
        }
        $hasPdfPath = portalV2ColumnExists($db, 'informes', 'pdf_path');
        $hasPacsInstanceId = false;
        $hasPacsSeriesId = false;
        $hasPacsStudyId = false;
        $hasFechaEnviadoPacs = false;
        try {
            $pacsColumns = $db->query("SHOW COLUMNS FROM informes WHERE Field IN ('pacs_instance_id', 'pacs_series_id', 'pacs_study_id', 'fecha_enviado_pacs')");
            if ($pacsColumns) {
                foreach ($pacsColumns->fetchAll(PDO::FETCH_ASSOC) as $col) {
                    if (($col['Field'] ?? '') === 'pacs_instance_id') {
                        $hasPacsInstanceId = true;
                    }
                    if (($col['Field'] ?? '') === 'pacs_series_id') {
                        $hasPacsSeriesId = true;
                    }
                    if (($col['Field'] ?? '') === 'pacs_study_id') {
                        $hasPacsStudyId = true;
                    }
                    if (($col['Field'] ?? '') === 'fecha_enviado_pacs') {
                        $hasFechaEnviadoPacs = true;
                    }
                }
            }
        } catch (Exception $e) {
            // noop
        }

        $informesQuery = "SELECT 
                i.id, i.titulo, i.estado, i.modality,
                i.fecha_creacion, i.fecha_modificacion,
                i.study_id, i.patient_id, i.patient_name,
                DATE(i.fecha_creacion) as informe_fecha";
        if ($hasPdfPath) {
            $informesQuery .= ", i.pdf_path";
        }
        if ($hasPacsInstanceId) {
            $informesQuery .= ", i.pacs_instance_id";
        }
        if ($hasPacsSeriesId) {
            $informesQuery .= ", i.pacs_series_id";
        }
        if ($hasPacsStudyId) {
            $informesQuery .= ", i.pacs_study_id";
        }
        if ($hasFechaEnviadoPacs) {
            $informesQuery .= ", i.fecha_enviado_pacs";
        }
        if (portalV2ColumnExists($db, 'informes', 'accession_number')) {
            $informesQuery .= ", i.accession_number";
        }
        $informesQuery .= " FROM informes i
            WHERE i.patient_id = :patient_id
              AND i.estado = 'finalizado'
              AND (";

        $visibility = [];
        if ($hasPdfPath) {
            $visibility[] = "(i.pdf_path IS NOT NULL AND i.pdf_path != '')";
        }
        if ($hasPacsInstanceId) {
            $visibility[] = "(i.pacs_instance_id IS NOT NULL AND i.pacs_instance_id != '')";
        }
        if ($hasPacsSeriesId) {
            $visibility[] = "(i.pacs_series_id IS NOT NULL AND i.pacs_series_id != '')";
        }
        if ($hasPacsStudyId) {
            $visibility[] = "(i.pacs_study_id IS NOT NULL AND i.pacs_study_id != '')";
        }
        if ($hasFechaEnviadoPacs) {
            $visibility[] = "i.fecha_enviado_pacs IS NOT NULL";
        }
        if (empty($visibility)) {
            $visibility[] = "(i.pdf_path IS NOT NULL AND i.pdf_path != '')";
        }
        $informesQuery .= implode(' OR ', $visibility) . ')';
        $informesQuery .= " ORDER BY i.fecha_creacion DESC";

        $informesStmt = $db->prepare($informesQuery);
        $informesStmt->execute([':patient_id' => $patientId]);
        $informesData = $informesStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($informesData as $informe) {
            $informeDate = !empty($informe['informe_fecha'])
                ? date('Ymd', strtotime($informe['informe_fecha']))
                : date('Ymd');
            $row = [
                'orthanc_id' => null,
                'study_id' => $informe['study_id'] ?? null,
                'informe_id' => $informe['id'],
                'study_date' => $informeDate,
                'study_time' => !empty($informe['fecha_creacion'])
                    ? date('His', strtotime($informe['fecha_creacion']))
                    : '120000',
                'modality' => $informe['modality'] ?? 'DOC',
                'study_description' => $informe['titulo'] ?? 'Informe Médico',
                'patient_name' => $informe['patient_name'] ?? '',
                'patient_id' => $informe['patient_id'] ?? $patientId,
                'pdf_path' => $informe['pdf_path'] ?? '',
                'pacs_series_id' => $informe['pacs_series_id'] ?? null,
                'pacs_instance_id' => $informe['pacs_instance_id'] ?? null,
                'pacs_study_id' => $informe['pacs_study_id'] ?? null,
                'accession_number' => $informe['accession_number'] ?? null,
                'estado' => $informe['estado'] ?? 'finalizado',
                'viewer_url' => null,
                'is_informe' => true,
            ];
            $out[] = $row;
        }
    } catch (Exception $e) {
        @error_log('[PORTAL_V2] informes: ' . $e->getMessage());
    }
    return $out;
}

/**
 * Adjunta has_informe / informes[] a estudios con orthanc_id y devuelve filas sueltas (sin estudio en lista).
 *
 * @return array{total_informes: int, orphans: array<int, array<string, mixed>>}
 */
function portalV2ApplyInformesToStudies(PDO $db, $patientId, array &$studies) {
    $informesWithPdf = portalV2FetchInformesForPortal($db, $patientId);
    if (empty($informesWithPdf)) {
        foreach ($studies as &$st) {
            $st['has_informe'] = false;
        }
        unset($st);
        return ['total_informes' => 0, 'orphans' => []];
    }

    $informesMap = [];
    foreach ($informesWithPdf as $informe) {
        $studyId = $informe['study_id'] ?? null;
        if ($studyId) {
            if (!isset($informesMap[$studyId])) {
                $informesMap[$studyId] = [];
            }
            $informesMap[$studyId][] = $informe;
        }
    }
    foreach ($informesMap as &$informes) {
        usort($informes, function ($a, $b) {
            $dateA = $a['study_date'] ?? '';
            $dateB = $b['study_date'] ?? '';
            if ($dateA === $dateB) {
                $timeA = $a['study_time'] ?? '';
                $timeB = $b['study_time'] ?? '';
                return strcmp($timeB, $timeA);
            }
            return strcmp($dateB, $dateA);
        });
    }
    unset($informes);

    foreach ($studies as &$study) {
        $orthancId = $study['orthanc_id'] ?? null;
        if ($orthancId && isset($informesMap[$orthancId]) && !empty($informesMap[$orthancId])) {
            $informesDelEstudio = $informesMap[$orthancId];
            $study['has_informe'] = true;
            if (count($informesDelEstudio) === 1) {
                $informe = $informesDelEstudio[0];
                $study['informe_pdf_path'] = $informe['pdf_path'] ?? '';
                $study['informe_titulo'] = $informe['study_description'] ?? 'Informe Médico';
                $study['informe_id'] = $informe['informe_id'];
                $study['pacs_series_id'] = $informe['pacs_series_id'] ?? null;
                $study['pacs_instance_id'] = $informe['pacs_instance_id'] ?? null;
                $study['accession_number'] = $informe['accession_number'] ?? null;
            } else {
                $informePrincipal = $informesDelEstudio[0];
                $study['informe_pdf_path'] = $informePrincipal['pdf_path'] ?? '';
                $study['informe_titulo'] = $informePrincipal['study_description'] ?? 'Informe Médico';
                $study['informe_id'] = $informePrincipal['informe_id'];
                $study['pacs_series_id'] = $informePrincipal['pacs_series_id'] ?? null;
                $study['pacs_instance_id'] = $informePrincipal['pacs_instance_id'] ?? null;
                $study['accession_number'] = $informePrincipal['accession_number'] ?? null;
            }
            $study['informes'] = array_map(function ($inf) {
                return [
                    'pdf_path' => $inf['pdf_path'] ?? '',
                    'titulo' => $inf['study_description'] ?? 'Informe Médico',
                    'informe_id' => $inf['informe_id'],
                    'fecha' => $inf['study_date'] ?? '',
                    'hora' => $inf['study_time'] ?? '',
                    'pacs_series_id' => $inf['pacs_series_id'] ?? null,
                    'pacs_instance_id' => $inf['pacs_instance_id'] ?? null,
                    'accession_number' => $inf['accession_number'] ?? null,
                ];
            }, $informesDelEstudio);
        } else {
            $study['has_informe'] = false;
        }
    }
    unset($study);

    $orphans = array_values(array_filter($informesWithPdf, function ($informe) use ($studies) {
        $studyId = $informe['study_id'] ?? null;
        if (!$studyId) {
            return true;
        }
        foreach ($studies as $study) {
            if (($study['orthanc_id'] ?? null) === $studyId) {
                return false;
            }
        }
        return true;
    }));

    return [
        'total_informes' => count($informesWithPdf),
        'orphans' => $orphans,
    ];
}

try {
    $db = portalV2GetDb();
    if (!$db) {
        portalV2JsonError('No se pudo conectar a la base de datos', 500);
    }

    $input = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = [];
        }
    }
    $patientIdRaw = isset($_GET['patient_id']) ? $_GET['patient_id'] : ($input['patient_id'] ?? '');
    $searchTypeReq = isset($_GET['search_type']) ? $_GET['search_type'] : ($input['search_type'] ?? '');

    $cfg = [
        'v2_enabled' => portalV2BoolTo01(portalV2GetConfig($db, 'portal_estudios_v2_enabled', '0'), '0'),
        'listing_mode' => portalV2GetConfig($db, 'portal_estudios_listing_mode', 'local'),
        'opening_mode' => portalV2GetConfig($db, 'portal_estudios_opening_mode', 'auto'),
        'strict_node_id' => (int)portalV2GetConfig($db, 'portal_estudios_strict_node_id', ''),
        'use_r2' => portalV2BoolTo01(portalV2GetConfig($db, 'portal_estudios_use_r2', '1'), '1'),
        'r2_priority' => portalV2GetConfig($db, 'portal_estudios_r2_priority', 'first'),
        'search_id_type' => portalV2GetConfig($db, 'portal_estudios_search_id_type', 'idpaciente'),
        'viewer_desktop' => portalV2GetConfig($db, 'portal_estudios_viewer_desktop', 'UDV'),
        'viewer_mobile' => portalV2GetConfig($db, 'portal_estudios_viewer_mobile', 'UDV'),
    ];

    $searchType = trim((string)$searchTypeReq);
    if (!in_array($searchType, ['documento', 'id_interno', 'idpaciente'], true)) {
        $searchType = ($cfg['search_id_type'] === 'id_interno') ? 'id_interno' : 'documento';
    }

    list($patientId, $patientData) = portalV2ResolvePatientId($db, $patientIdRaw, $searchType);

    $orthanc = new OrthancClient();
    $serverStatus = $orthanc->getServerStatus();
    if (($serverStatus['status'] ?? '') !== 'connected') {
        portalV2JsonError('No se puede conectar al servidor Orthanc', 500);
    }
    $localStudies = $orthanc->findStudiesByPatientId($patientId);

    $merged = [];
    $localOrthancIds = [];
    foreach ($localStudies as $study) {
        $uid = trim((string)($study['study_instance_uid'] ?? ''));
        if ($uid === '') {
            continue;
        }
        $orthancId = (string)($study['orthanc_id'] ?? '');
        if ($orthancId !== '') {
            $localOrthancIds[] = $orthancId;
        }
        $merged[$uid] = [
            'study_instance_uid' => $uid,
            'orthanc_id' => $orthancId,
            'patient_id' => (string)($study['patient_id'] ?? ''),
            'patient_name' => (string)($study['patient_name'] ?? ''),
            'study_date' => (string)($study['study_date'] ?? ''),
            'study_time' => (string)($study['study_time'] ?? ''),
            'study_description' => (string)($study['study_description'] ?? ''),
            'modality' => (string)($study['modality'] ?? ''),
            'is_pacs_pdf_informe' => !empty($study['is_pacs_pdf_informe']),
            'viewer_url' => (string)($study['viewer_url'] ?? ''),
            'viewer_url_mobile' => (string)($study['viewer_url_mobile'] ?? ''),
            'viewer_url_desktop' => (string)($study['viewer_url_desktop'] ?? ''),
            'sources' => [[
                'type' => 'local',
                'node_id' => null,
                'node_name' => 'Orthanc local',
                'node_type' => 'local',
                'is_local' => true
            ]]
        ];
    }

    $remoteStudies = portalV2GetRemoteStudies($db, $patientId, (int)$cfg['strict_node_id'], (string)$cfg['listing_mode']);
    foreach ($remoteStudies as $uid => $remoteStudy) {
        if (!isset($merged[$uid])) {
            $merged[$uid] = $remoteStudy;
        } else {
            $merged[$uid]['sources'] = array_merge($merged[$uid]['sources'], $remoteStudy['sources']);
        }
    }

    // Si listing_mode=local, descartar estudios sin origen local.
    if ($cfg['listing_mode'] === 'local') {
        foreach ($merged as $uid => $study) {
            $hasLocal = false;
            foreach (($study['sources'] ?? []) as $src) {
                if (!empty($src['is_local'])) {
                    $hasLocal = true;
                    break;
                }
            }
            if (!$hasLocal) {
                unset($merged[$uid]);
            }
        }
    }

    // Contexto R2 solo para estudios locales con orthanc_id.
    $r2Batch = [];
    if (!empty($localOrthancIds)) {
        $r2Batch = StudyRoutingService::batchLoadR2Context($db, array_values(array_unique($localOrthancIds)));
    }

    $studies = array_values($merged);
    foreach ($studies as &$study) {
        $orthancId = (string)($study['orthanc_id'] ?? '');
        $r2Available = false;
        $r2Delivery = null;
        if ($orthancId !== '' && isset($r2Batch[$orthancId])) {
            $r2Delivery = StudyRoutingService::detectDelivery($r2Batch[$orthancId]);
            $r2Available = $r2Delivery !== null;
        }

        $hasLocal = false;
        $firstRemote = null;
        foreach (($study['sources'] ?? []) as $src) {
            if (!empty($src['is_local'])) {
                $hasLocal = true;
            } elseif ($firstRemote === null) {
                $firstRemote = $src;
            }
        }

        $target = ['type' => null, 'node_id' => null, 'reason' => 'no_source_available'];
        if ($cfg['opening_mode'] === 'strict') {
            if ($cfg['strict_node_id'] > 0) {
                $found = null;
                foreach (($study['sources'] ?? []) as $src) {
                    if ((int)($src['node_id'] ?? 0) === (int)$cfg['strict_node_id']) {
                        $found = $src;
                        break;
                    }
                }
                if ($found) {
                    $target = ['type' => 'remote', 'node_id' => (int)$found['node_id'], 'reason' => 'strict_node_match'];
                } else {
                    $target = ['type' => null, 'node_id' => null, 'reason' => 'strict_node_not_found'];
                }
            } elseif ($cfg['use_r2'] === '1' && $r2Available) {
                $target = ['type' => 'r2', 'node_id' => null, 'reason' => 'strict_r2'];
            } elseif ($hasLocal) {
                $target = ['type' => 'local', 'node_id' => null, 'reason' => 'strict_local'];
            }
        } else {
            if ($cfg['use_r2'] === '1' && $cfg['r2_priority'] === 'first' && $r2Available) {
                $target = ['type' => 'r2', 'node_id' => null, 'reason' => 'auto_r2_first'];
            } elseif ($hasLocal) {
                $target = ['type' => 'local', 'node_id' => null, 'reason' => 'auto_local'];
            } elseif ($firstRemote) {
                $target = ['type' => 'remote', 'node_id' => (int)$firstRemote['node_id'], 'reason' => 'auto_remote'];
            } elseif ($cfg['use_r2'] === '1' && $cfg['r2_priority'] === 'last' && $r2Available) {
                $target = ['type' => 'r2', 'node_id' => null, 'reason' => 'auto_r2_last'];
            }
        }

        $study['r2'] = [
            'available' => $r2Available,
            'delivery' => $r2Delivery,
        ];
        $study['open_strategy'] = $target;
    }
    unset($study);

    $informeMeta = portalV2ApplyInformesToStudies($db, $patientId, $studies);
    $totalStudiesImaging = count($studies);
    $studies = array_merge($studies, $informeMeta['orphans']);

    $qaMeta = null;
    $qaFile = __DIR__ . '/../../../modules/qa-publicacion/QaPublicationService.php';
    if (is_file($qaFile)) {
        require_once $qaFile;
        if (class_exists('QaPublicationService')) {
            foreach ($studies as &$qaStudy) {
                $orthancForMixed = (string) ($qaStudy['orthanc_id'] ?? '');
                if ($orthancForMixed !== '') {
                    $mixed = QaPublicationService::detectMixedSeries($orthancForMixed);
                    $qaStudy['qa_mixed_series'] = !empty($mixed['mixed']);
                }
            }
            unset($qaStudy);
            $qaMeta = QaPublicationService::applyToPortal($db, $patientId, $studies);
        }
    }

    try {
        $pacsSender = new OrthancPacsSender();
        InformeViewStrategy::enrichStudies($studies, $pacsSender);
    } catch (Throwable $e) {
        @error_log('[PORTAL_V2] InformeViewStrategy: ' . $e->getMessage());
    }

    usort($studies, function ($a, $b) {
        return strcmp((string)($b['study_date'] ?? ''), (string)($a['study_date'] ?? ''));
    });

    while (@ob_get_level()) {
        @ob_end_clean();
    }
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'version' => 'portal_estudios_v2_phase2',
        'patient_id' => $patientId,
        'search_type' => $searchType,
        'patient_data' => $patientData,
        'config' => $cfg,
        'count' => count($studies),
        'total_studies' => $totalStudiesImaging,
        'total_informes' => $informeMeta['total_informes'],
        'qa' => $qaMeta,
        'studies' => $studies
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    error_log('[PORTAL_V2] search error: ' . $e->getMessage());
    portalV2JsonError('Error interno del servidor', 500);
}

