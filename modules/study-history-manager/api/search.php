<?php
/**
 * Búsqueda de estudios por PatientID (idpaciente) en todos los nodos activos.
 * Agrupa por StudyInstanceUID; el primer nodo (local primero, luego id ASC) define metadatos y origen por defecto.
 * Si el mismo UID aparece en más nodos, se listan en "sources" para elegir origen al abrir el visor (sin nueva C-FIND).
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
require_once __DIR__ . '/../../pacs-nodes-manager/PacsNodeClient.php';
require_once __DIR__ . '/../../pacs-nodes-manager/PacsNodeConfig.php';

try {
    requireStudyHistoryAuth('study_history_manager');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        studyHistoryJsonError('Método no permitido', 405);
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        studyHistoryJsonError('JSON inválido', 400);
    }

    $patientId = isset($data['patient_id']) ? trim((string) $data['patient_id']) : '';
    if ($patientId === '') {
        studyHistoryJsonError('patient_id es requerido', 400);
    }

    $db = getDBConnection();
    if (!$db) {
        studyHistoryJsonError('No se pudo conectar a la base de datos', 500);
    }

    // Nodos activos: local primero
    $nodesStmt = $db->query("
        SELECT * FROM pacs_nodes
        WHERE is_active = 1
        ORDER BY (CASE WHEN node_type = 'local' THEN 0 ELSE 1 END), id ASC
    ");
    $nodes = $nodesStmt->fetchAll(PDO::FETCH_ASSOC);

    $query = [
        'Level' => 'Study',
        'Query' => [
            'PatientID' => $patientId
        ]
    ];

    $client = new PacsNodeClient($db);
    $merged = [];
    $errors = [];

    foreach ($nodes as $node) {
        $nodeId = (int) $node['id'];
        try {
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
                    error_log('[STUDY_HISTORY] Auto-sync nodo ' . $nodeId . ': ' . $e->getMessage());
                }
            }

            $results = $client->executeCFind($node, $query);
        } catch (Exception $e) {
            $errors[] = [
                'node_id' => $nodeId,
                'node_name' => $node['name'] ?? '',
                'error' => $e->getMessage()
            ];
            continue;
        }

        foreach ($results as $row) {
            if (!is_array($row)) {
                continue;
            }
            $uid = trim((string) ($row['StudyInstanceUID'] ?? ''));
            if ($uid === '') {
                continue;
            }

            $isLocal = (($node['node_type'] ?? '') === 'local');
            $rom = isset($node['remote_open_mode']) ? (string) $node['remote_open_mode'] : 'dicomweb';
            if (!in_array($rom, ['dicomweb', 'wado_manifest'], true)) {
                $rom = 'dicomweb';
            }
            $seriesN = (int) ($row['NumberOfStudyRelatedSeries'] ?? 0);
            $instN = (int) ($row['NumberOfStudyRelatedInstances'] ?? 0);
            $sourceSlice = [
                'source_node_id' => $nodeId,
                'source_node_name' => $node['name'] ?? '',
                'source_node_type' => $node['node_type'] ?? '',
                'remote_open_mode' => $rom,
                'is_on_local_pacs' => $isLocal,
                'series_count' => $seriesN,
                'instances_count' => $instN,
            ];

            if (!isset($merged[$uid])) {
                $merged[$uid] = [
                    'study_instance_uid' => $uid,
                    'patient_id' => $row['PatientID'] ?? '',
                    'patient_name' => $row['PatientName'] ?? '',
                    'study_date' => $row['StudyDate'] ?? '',
                    'study_time' => $row['StudyTime'] ?? '',
                    'study_description' => $row['StudyDescription'] ?? '',
                    'accession_number' => $row['AccessionNumber'] ?? '',
                    'modalities_in_study' => $row['ModalitiesInStudy'] ?? '',
                    'number_of_series' => $seriesN,
                    'number_of_instances' => $instN,
                    'sources' => [$sourceSlice],
                    'source_node_id' => $nodeId,
                    'source_node_name' => $sourceSlice['source_node_name'],
                    'source_node_type' => $sourceSlice['source_node_type'],
                    'remote_open_mode' => $rom,
                    'is_on_local_pacs' => $isLocal,
                ];
                continue;
            }

            $already = false;
            foreach ($merged[$uid]['sources'] as $ex) {
                if ((int) ($ex['source_node_id'] ?? 0) === $nodeId) {
                    $already = true;
                    break;
                }
            }
            if (!$already) {
                $merged[$uid]['sources'][] = $sourceSlice;
            }
        }
    }

    $list = array_values($merged);
    usort($list, function ($a, $b) {
        return strcmp($b['study_date'] ?? '', $a['study_date'] ?? '');
    });

    ob_end_clean();
    studyHistoryJsonSuccess([
        'studies' => $list,
        'errors' => $errors,
        'count' => count($list)
    ]);
} catch (Exception $e) {
    ob_end_clean();
    error_log('[STUDY_HISTORY] search: ' . $e->getMessage());
    studyHistoryJsonError($e->getMessage(), 500);
}
