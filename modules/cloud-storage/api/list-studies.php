<?php
/**
 * API para listar estudios desde PACS para Cloud Storage
 * Similar a pacs-manager/list.php pero adaptado para Cloud Storage
 * Incluye viewer_url (visor del usuario + Study routing si aplica)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

/**
 * @return array{id: ?int, nivel: string, permisos: array}
 */
function cloudStorageListGetSessionUser(): array
{
    $sessionToken = $_COOKIE['session_token'] ?? null;
    if (empty($sessionToken)) {
        return [
            'id' => null,
            'nivel' => 'root',
            'permisos' => ['all'],
        ];
    }
    try {
        $user = new User();
        $userData = $user->validateSession($sessionToken);
        if (!$userData) {
            return [
                'id' => null,
                'nivel' => 'root',
                'permisos' => ['all'],
            ];
        }
        $permissions = [];
        if (!empty($userData['permisos'])) {
            $permissions = json_decode($userData['permisos'], true) ?: [];
        }
        if (empty($permissions) && (($userData['nivel'] ?? '') === 'root')) {
            $permissions = ['all'];
        }
        return [
            'id' => (int)($userData['id'] ?? 0),
            'nivel' => (string)($userData['nivel'] ?? ''),
            'permisos' => $permissions,
        ];
    } catch (Throwable $e) {
        error_log('[CLOUD_STORAGE][LIST_STUDIES] Sesión: ' . $e->getMessage());
        return [
            'id' => null,
            'nivel' => 'root',
            'permisos' => ['all'],
        ];
    }
}

try {
    require_once __DIR__ . '/../../../config/database.php';
    require_once __DIR__ . '/../../../api/OrthancClient.php';
    require_once __DIR__ . '/../../../classes/User.php';
    require_once __DIR__ . '/../../../api/config/orthanc_config.php';
    require_once __DIR__ . '/../../../api/StudyRoutingService.php';

    // Obtener parámetros de filtro
    $dateFrom = $_GET['date_from'] ?? null;
    $dateTo = $_GET['date_to'] ?? null;
    $patientId = $_GET['patient_id'] ?? null;
    $modality = $_GET['modality'] ?? null;

    $pdoRoute = getDBConnection();

    $sessionUser = cloudStorageListGetSessionUser();
    $currentUserId = $sessionUser['id'] ?? null;
    if ($currentUserId !== null && $currentUserId <= 0) {
        $currentUserId = null;
    }
    $sessionPerms = $sessionUser['permisos'] ?? [];
    if (!is_array($sessionPerms)) {
        $sessionPerms = [];
    }
    $sessionLevel = (string)($sessionUser['nivel'] ?? '');

    $userViewerType = 'UDV';
    if ($currentUserId !== null && $pdoRoute) {
        try {
            $viewerStmt = $pdoRoute->prepare('SELECT dicom_viewer FROM usuarios WHERE id = ? AND activo = 1');
            $viewerStmt->execute([$currentUserId]);
            $viewerRow = $viewerStmt->fetch(PDO::FETCH_ASSOC);
            if ($viewerRow && !empty(trim((string)($viewerRow['dicom_viewer'] ?? '')))) {
                $userViewerType = trim((string)$viewerRow['dicom_viewer']);
            }
        } catch (Exception $e) {
            error_log('[CLOUD_STORAGE][LIST_STUDIES] Visor usuario: ' . $e->getMessage());
        }
    }

    // Obtener estudios desde Orthanc
    $orthancClient = new OrthancClient();
    $studies = $orthancClient->getAllStudiesEfficient($dateFrom, $dateTo, $patientId, $modality, false);

    if (!is_array($studies)) {
        $studies = [];
    }

    // Obtener estado R2 y de cola para cada estudio (solo si hay estudios)
    $r2Statuses = [];
    $queueStatuses = [];
    if (!empty($studies)) {
        try {
            $database = new Database();
            $db = $database->getConnection();

            if ($db) {
                $stmt = $db->query("
                    SELECT orthanc_study_id, r2_status, r2_manifest_path 
                    FROM r2_studies
                ");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $r2Statuses[$row['orthanc_study_id']] = $row;
                }

                $stmt = $db->query("
                    SELECT orthanc_study_id, status 
                    FROM r2_queue
                    ORDER BY created_at DESC
                ");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (!isset($queueStatuses[$row['orthanc_study_id']])) {
                        $queueStatuses[$row['orthanc_study_id']] = $row['status'];
                    }
                }
            }
        } catch (Exception $e) {
            error_log('[CLOUD_STORAGE][LIST_STUDIES] Error obteniendo estados R2: ' . $e->getMessage());
        }
    }

    foreach ($studies as &$study) {
        $studyId = $study['orthanc_id'] ?? $study['study_id'] ?? '';
        if (isset($r2Statuses[$studyId])) {
            $study['r2_status'] = $r2Statuses[$studyId]['r2_status'];
            $study['r2_manifest_path'] = $r2Statuses[$studyId]['r2_manifest_path'];
        } else {
            $study['r2_status'] = 'none';
            $study['r2_manifest_path'] = null;
        }

        if (isset($queueStatuses[$studyId])) {
            $study['queue_status'] = $queueStatuses[$studyId];
        } else {
            $study['queue_status'] = null;
        }
    }
    unset($study);

    // Study routing + URL del visor (misma lógica que pacs-manager/list.php)
    $r2Batch = [];
    $effectiveRouting = 'local';
    if ($pdoRoute && $studies !== []) {
        $effectiveRouting = StudyRoutingService::getEffectiveMode(
            $pdoRoute,
            $currentUserId,
            $sessionPerms,
            $sessionLevel
        );
        $routeIds = [];
        foreach ($studies as $st) {
            $oid = $st['orthanc_id'] ?? $st['study_id'] ?? '';
            if ($oid !== '') {
                $routeIds[] = $oid;
            }
        }
        if ($routeIds !== []) {
            $r2Batch = StudyRoutingService::batchLoadR2Context($pdoRoute, $routeIds);
        }
    }

    foreach ($studies as &$study) {
        $studyId = $study['orthanc_id'] ?? $study['study_id'] ?? '';
        $studyInstanceUID = $study['study_instance_uid'] ?? '';

        $viewerUrl = OrthancConfig::getViewerUrl($studyId, $userViewerType, $studyInstanceUID);
        $localDl = StudyRoutingService::buildLocalOrthancArchiveUrl(
            $studyId,
            (string)($study['patient_id'] ?? ''),
            (string)($study['patient_name'] ?? ''),
            (string)($study['study_date'] ?? ''),
            (string)($study['study_description'] ?? '')
        );
        $r2row = $r2Batch[$studyId] ?? [
            'orthanc_study_id' => $studyId,
            'study_instance_uid' => $studyInstanceUID,
            'r2_status' => $study['r2_status'] ?? 'none',
            'r2_manifest_path' => $study['r2_manifest_path'] ?? null,
            'r2_zip_key' => null,
        ];

        if ($pdoRoute) {
            $resolved = StudyRoutingService::resolve(
                $pdoRoute,
                $studyId,
                $studyInstanceUID,
                $viewerUrl,
                $localDl,
                $effectiveRouting,
                $r2row
            );
            $study['viewer_url'] = $resolved['viewer_url'];
            $study['study_routing'] = $resolved['study_routing'];
        } else {
            $study['viewer_url'] = $viewerUrl;
            $study['study_routing'] = [
                'mode_requested' => 'local',
                'source_viewer' => 'local',
                'source_download' => 'local',
                'delivery' => null,
                'fallback' => null,
            ];
        }
    }
    unset($study);

    echo json_encode([
        'success' => true,
        'data' => $studies,
        'total' => count($studies),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
    error_log('[CLOUD_STORAGE][LIST_STUDIES] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
