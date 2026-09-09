<?php
/**
 * Devuelve estudios candidatos para vincular un informe recibido.
 *
 * Soporta:
 * - sugerencias por recibido_id (usa datos del TXT guardados)
 * - búsqueda manual adicional por q
 * - ranking semiautomático (score + razones)
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit();
}

require_once '../../../config/database.php';
require_once __DIR__ . '/informe_recibido_score_helper.php';
require_once '../../../api/OrthancClient.php';

function fetchCandidateRows(PDO $db, string $whereSql, array $params, int $limit = 300): array
{
    $sql = "
        SELECT
            e.id,
            e.accession_number,
            e.patient_id_pacs AS patient_id,
            e.patient_name_pacs AS patient_name,
            e.study_description,
            e.modality,
            e.study_date,
            e.study_instance_uid,
            e.orthanc_study_id
        FROM estudios e
        WHERE ({$whereSql})
          AND UPPER(TRIM(COALESCE(e.modality, ''))) <> 'DOC'
        ORDER BY e.study_date DESC, e.id DESC
        LIMIT {$limit}
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function sqlNormalizedPatientIdExpr(string $field): string
{
    // Normaliza removiendo separadores frecuentes para comparar IDs de paciente de forma robusta.
    return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(UPPER(TRIM(COALESCE({$field}, ''))), ' ', ''), '-', ''), '.', ''), '/', ''), '\\\\', ''), '_', '')";
}

/**
 * Devuelve una condición SQL que acepta la modalidad dada Y su equivalente CR↔DX.
 * CR y DX son intercambiables: los TXT externos usan CR para radiografías pero los estudios
 * en PACS suelen estar guardados como DX (o viceversa).
 * También normaliza la modalidad del informe recibido si vino como DX para buscar CR y viceversa.
 *
 * @param string $field  Columna SQL a comparar (p.ej. "e.modality")
 * @return array{sql: string, params: string[]}
 */
function sqlModalityCondition(string $field, string $modality): array
{
    $m = strtoupper(trim($modality));
    $equivalents = ['CR' => 'DX', 'DX' => 'CR'];
    if (isset($equivalents[$m])) {
        return [
            'sql'    => "UPPER(TRIM({$field})) IN (?, ?)",
            'params' => [$m, $equivalents[$m]],
        ];
    }
    return [
        'sql'    => "UPPER(TRIM({$field})) = ?",
        'params' => [$m],
    ];
}

function mapOrthancStudiesToRows(PDO $db, array $orthancStudies, string $modality, string $procedureDate, string $searchQ): array
{
    $orthancRows = [];
    foreach ($orthancStudies as $study) {
        $orthancStudyId = (string)($study['orthanc_id'] ?? $study['study_id'] ?? $study['orthanc_study_id'] ?? '');
        $studyInstanceUid = (string)($study['study_instance_uid'] ?? '');
        $studyDateIso = ir_score_normalize_dicom_date_to_iso($study['study_date'] ?? null);
        $studyModality = strtoupper(trim((string)($study['modality'] ?? '')));

        // DOC = informes/PDF en PACS, no estudios de imagen — no ofrecer como candidato
        if ($studyModality === 'DOC') {
            continue;
        }

        if (!ir_score_modality_matches($modality, $studyModality)) {
            continue;
        }

        if ($procedureDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $procedureDate) && $studyDateIso) {
            $diff = ir_score_day_diff_abs($procedureDate, $studyDateIso);
            if ($diff !== null && $diff > 30) {
                continue;
            }
        }

        if ($searchQ !== '') {
            $tmpRow = [
                'patient_name' => $study['patient_name'] ?? null,
                'patient_id' => $study['patient_id'] ?? null,
                'study_description' => $study['study_description'] ?? null,
                'study_instance_uid' => $studyInstanceUid,
                'accession_number' => $study['accession_number'] ?? null,
                'orthanc_study_id' => $orthancStudyId,
                'id' => null,
            ];
            if (!candidateMatchesSearchQ($tmpRow, $searchQ)) {
                continue;
            }
        }

        $localStudyId = null;
        try {
            if ($orthancStudyId !== '' || $studyInstanceUid !== '') {
                $mapStmt = $db->prepare("
                    SELECT id
                    FROM estudios
                    WHERE (orthanc_study_id = ? AND ? <> '')
                       OR (study_instance_uid = ? AND ? <> '')
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $mapStmt->execute([$orthancStudyId, $orthancStudyId, $studyInstanceUid, $studyInstanceUid]);
                $mapped = $mapStmt->fetch(PDO::FETCH_ASSOC);
                if ($mapped && isset($mapped['id'])) {
                    $localStudyId = (int)$mapped['id'];
                }
            }
        } catch (Exception $e) {
            continue;
        }

        $orthancRows[] = [
            'id' => $localStudyId ?: null,
            'local_estudio_id' => $localStudyId ?: null,
            'accession_number' => $study['accession_number'] ?? null,
            'patient_id' => $study['patient_id'] ?? null,
            'patient_name' => $study['patient_name'] ?? null,
            'study_description' => $study['study_description'] ?? null,
            'modality' => $studyModality,
            'study_date' => $studyDateIso,
            'study_instance_uid' => $studyInstanceUid !== '' ? $studyInstanceUid : null,
            'orthanc_study_id' => $orthancStudyId !== '' ? $orthancStudyId : null,
            'requires_local_creation' => $localStudyId ? false : true,
            'source' => 'orthanc_fallback',
        ];
    }
    return $orthancRows;
}

function candidateMatchesSearchQ(array $row, string $searchQ): bool
{
    $q = trim($searchQ);
    if ($q === '') {
        return true;
    }
    $qLower = mb_strtolower($q, 'UTF-8');
    $qNormPatientId = ir_score_normalize_patient_id($q);

    $fields = [
        (string)($row['patient_name'] ?? ''),
        (string)($row['patient_id'] ?? ''),
        (string)($row['study_description'] ?? ''),
        (string)($row['study_instance_uid'] ?? ''),
        (string)($row['accession_number'] ?? ''),
        (string)($row['orthanc_study_id'] ?? ''),
        (string)($row['id'] ?? ''),
    ];
    foreach ($fields as $f) {
        if ($f !== '' && mb_strpos(mb_strtolower($f, 'UTF-8'), $qLower) !== false) {
            return true;
        }
    }

    if ($qNormPatientId !== '') {
        $candidatePidNorm = ir_score_normalize_patient_id((string)($row['patient_id'] ?? ''));
        if ($candidatePidNorm !== '' && strpos($candidatePidNorm, $qNormPatientId) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Prioriza StudyDate desde PACS (Orthanc) para cada candidato: estudios.study_date puede estar desalineado
 * (p. ej. fecha de alta). Orden: GET por orthanc_study_id; si no hay fecha, /tools/find por StudyInstanceUID.
 */
function enrichCandidateStudyDatesFromPacs(array &$rows, int $maxByOrthancId = 120, int $maxUidResolves = 50): void
{
    if ($rows === []) {
        return;
    }
    try {
        $orthanc = new OrthancClient();
    } catch (Exception $e) {
        foreach ($rows as $i => $_r) {
            $rows[$i]['study_date_source'] = 'local';
        }

        return;
    }

    $orthancIds = [];
    foreach ($rows as $r) {
        $oid = trim((string)($r['orthanc_study_id'] ?? ''));
        if ($oid !== '') {
            $orthancIds[$oid] = true;
        }
    }
    $uniqueIds = array_keys($orthancIds);
    if (count($uniqueIds) > $maxByOrthancId) {
        $uniqueIds = array_slice($uniqueIds, 0, $maxByOrthancId);
        error_log('[CANDIDATOS_ESTUDIO] enrichCandidateStudyDatesFromPacs: truncado a ' . $maxByOrthancId . ' IDs Orthanc');
    }

    $dateByOrthancId = [];
    $orthancInternalByStoredId = [];
    foreach ($uniqueIds as $oid) {
        // Si el ID almacenado parece un DICOM UID (cualquier prefijo numérico como 1.2.x, 1.3.x, etc.)
        // hay que resolver primero el UUID interno de Orthanc antes de consultar por REST.
        $isDicomUid = preg_match('/^\d+(\.\d+){3,}$/', $oid);
        $resolvedOid = $oid;
        if ($isDicomUid) {
            $ri = $orthanc->findOrthancStudyIdByStudyInstanceUid($oid);
            if ($ri !== null && $ri !== '' && $ri !== $oid) {
                $orthancInternalByStoredId[$oid] = $ri;
                $resolvedOid = $ri;
            }
        }
        $iso = $orthanc->getStudyDateIsoFromOrthanc($resolvedOid);
        if ($iso !== null && $iso !== '') {
            $dateByOrthancId[$oid] = $iso;
        }
    }

    foreach ($rows as $i => $r) {
        $oid = trim((string)($r['orthanc_study_id'] ?? ''));
        if ($oid !== '' && isset($orthancInternalByStoredId[$oid])) {
            $rows[$i]['orthanc_internal_id'] = $orthancInternalByStoredId[$oid];
        }
        if ($oid !== '' && isset($dateByOrthancId[$oid])) {
            $rows[$i]['study_date'] = $dateByOrthancId[$oid];
            $rows[$i]['study_date_source'] = 'pacs';
        } elseif ($oid !== '') {
            $rows[$i]['study_date_source'] = 'local';
        }
    }

    $uidsToResolve = [];
    foreach ($rows as $i => $r) {
        if (($r['study_date_source'] ?? null) === 'pacs') {
            continue;
        }
        $uid = trim((string)($r['study_instance_uid'] ?? ''));
        if ($uid !== '') {
            $uidsToResolve[$uid] = true;
        }
    }
    $uidList = array_keys($uidsToResolve);
    if (count($uidList) > $maxUidResolves) {
        $uidList = array_slice($uidList, 0, $maxUidResolves);
    }

    $dateByUid = [];
    $internalIdByUid = [];
    foreach ($uidList as $uid) {
        $resolvedId = $orthanc->findOrthancStudyIdByStudyInstanceUid($uid);
        if ($resolvedId === null || $resolvedId === '') {
            continue;
        }
        $iso = $orthanc->getStudyDateIsoFromOrthanc($resolvedId);
        if ($iso !== null && $iso !== '') {
            $dateByUid[$uid] = $iso;
            $internalIdByUid[$uid] = $resolvedId;
        }
    }

    foreach ($rows as $i => $r) {
        if (($r['study_date_source'] ?? null) === 'pacs') {
            continue;
        }
        $uid = trim((string)($r['study_instance_uid'] ?? ''));
        if ($uid !== '' && isset($dateByUid[$uid])) {
            $rows[$i]['study_date'] = $dateByUid[$uid];
            $rows[$i]['study_date_source'] = 'pacs';
            if (isset($internalIdByUid[$uid])) {
                $rows[$i]['orthanc_internal_id'] = $internalIdByUid[$uid];
            }
        } else {
            $rows[$i]['study_date_source'] = $rows[$i]['study_date_source'] ?? 'local';
        }
    }
}

/**
 * Completa study_instance_uid y accession_number desde Orthanc cuando faltan en estudios pero hay ID REST.
 */
function enrichCandidateStudyMetaFromOrthanc(array &$rows, int $maxFetches = 120): void
{
    if ($rows === []) {
        return;
    }
    foreach ($rows as $i => $r) {
        if (trim((string) ($r['study_instance_uid'] ?? '')) !== '') {
            continue;
        }
        $oid = trim((string) ($r['orthanc_study_id'] ?? ''));
        if ($oid !== '' && preg_match('/^1\\.2\\.\\d/', $oid)) {
            $rows[$i]['study_instance_uid'] = $oid;
        }
    }

    try {
        $orthanc = new OrthancClient();
    } catch (Exception $e) {
        return;
    }

    $internalIds = [];
    foreach ($rows as $r) {
        $internal = trim((string) ($r['orthanc_internal_id'] ?? ''));
        if ($internal === '') {
            $oid = trim((string) ($r['orthanc_study_id'] ?? ''));
            if ($oid !== '' && !preg_match('/^1\\.2\\.\\d/', $oid)) {
                $internal = $oid;
            }
        }
        if ($internal !== '') {
            $internalIds[$internal] = true;
        }
    }
    $list = array_keys($internalIds);
    if (count($list) > $maxFetches) {
        $list = array_slice($list, 0, $maxFetches);
    }

    $metaByInternal = [];
    foreach ($list as $iid) {
        $metaByInternal[$iid] = $orthanc->getStudyPacsDisplayMeta($iid);
    }

    foreach ($rows as $i => $r) {
        $internal = trim((string) ($r['orthanc_internal_id'] ?? ''));
        if ($internal === '') {
            $oid = trim((string) ($r['orthanc_study_id'] ?? ''));
            if ($oid !== '' && !preg_match('/^1\\.2\\.\\d/', $oid)) {
                $internal = $oid;
            }
        }
        if ($internal === '' || !isset($metaByInternal[$internal])) {
            continue;
        }
        $m = $metaByInternal[$internal];
        if (trim((string) ($r['study_instance_uid'] ?? '')) === '' && ($m['study_instance_uid'] ?? '') !== '') {
            $rows[$i]['study_instance_uid'] = $m['study_instance_uid'];
        }
        if (trim((string) ($r['accession_number'] ?? '')) === '' && ($m['accession_number'] ?? '') !== '') {
            $rows[$i]['accession_number']       = $m['accession_number'];
            $rows[$i]['_accno_pacs_enriched']   = true;
        }
    }
}

try {
    $db = getDBConnection();

    $recibidoId = (int)($_GET['recibido_id'] ?? 0);
    $accessionNumber = trim((string)($_GET['accession_number'] ?? ''));
    $patientId = trim((string)($_GET['patient_id'] ?? ''));
    $patientName = trim((string)($_GET['patient_name'] ?? ''));
    $procedureDate = trim((string)($_GET['procedure_date'] ?? ''));
    $modality = strtoupper(trim((string)($_GET['modality'] ?? '')));
    $searchQ = trim((string)($_GET['q'] ?? ''));
    $forcePacsSearch = !empty($_GET['pacs_search']) && $_GET['pacs_search'] !== '0';
    $limit = (int)($_GET['limit'] ?? 20);
    if ($limit <= 0 || $limit > 100) {
        $limit = 20;
    }

    $studyDateDicom = ''; // fecha de estudio DICOM (0008.0020) si está disponible
    if ($recibidoId > 0) {
        $irColsStmt = $db->query('SHOW COLUMNS FROM informes_recibidos');
        $irCols = $irColsStmt ? $irColsStmt->fetchAll(PDO::FETCH_COLUMN) : [];
        $hasStudyDateDicom = in_array('study_date_dicom', $irCols, true);
        $selectExtra = $hasStudyDateDicom ? ', study_date_dicom' : '';
        $recibidoStmt = $db->prepare("
            SELECT accession_number, patient_id, patient_name, procedure_date, modality{$selectExtra}
            FROM informes_recibidos
            WHERE id = ?
            LIMIT 1
        ");
        $recibidoStmt->execute([$recibidoId]);
        $recibido = $recibidoStmt->fetch(PDO::FETCH_ASSOC);
        if (!$recibido) {
            throw new Exception('Informe recibido no encontrado');
        }

        if ($accessionNumber === '') {
            $accessionNumber = trim((string)($recibido['accession_number'] ?? ''));
        }
        if ($patientId === '') {
            $patientId = trim((string)($recibido['patient_id'] ?? ''));
        }
        if ($patientName === '') {
            $patientName = trim((string)($recibido['patient_name'] ?? ''));
        }
        if ($procedureDate === '') {
            $procedureDate = trim((string)($recibido['procedure_date'] ?? ''));
        }
        if ($modality === '') {
            $modality = strtoupper(trim((string)($recibido['modality'] ?? '')));
        }
        $studyDateDicom = trim((string)($recibido['study_date_dicom'] ?? ''));
    }

    if (
        $accessionNumber === '' &&
        $patientId === '' &&
        $patientName === '' &&
        $procedureDate === '' &&
        $searchQ === ''
    ) {
        throw new Exception('Debe indicar recibido_id o algún criterio de búsqueda');
    }

    $rows = [];
    $selectionMode = 'broad_or';
    $normalizedPatientId = ir_score_normalize_patient_id($patientId);
    $pidExpr = sqlNormalizedPatientIdExpr('e.patient_id_pacs');

    // Modo estricto por patient_id (+ fecha + modalidad), con fallback progresivo.
    // Las ventanas de fecha cubren gap orden→adquisición (hasta 15d para modalidades que pueden
    // tener espera post-solicitud, p.ej. MR/CT con preparación).
    if ($normalizedPatientId !== '') {
        $strictStages = [];
        // Etapas por procedure_date (fecha orden/programa)
        if ($procedureDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $procedureDate) && $modality !== '') {
            $mc = sqlModalityCondition('e.modality', $modality);
            $strictStages[] = [
                'mode' => 'strict_pid_modality_date_8d',
                'where' => "{$pidExpr} = ? AND {$mc['sql']} AND e.study_date BETWEEN DATE_SUB(?, INTERVAL 8 DAY) AND DATE_ADD(?, INTERVAL 8 DAY)",
                'params' => array_merge([$normalizedPatientId], $mc['params'], [$procedureDate, $procedureDate]),
            ];
        }
        if ($procedureDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $procedureDate)) {
            $strictStages[] = [
                'mode' => 'strict_pid_date_15d',
                'where' => "{$pidExpr} = ? AND e.study_date BETWEEN DATE_SUB(?, INTERVAL 15 DAY) AND DATE_ADD(?, INTERVAL 15 DAY)",
                'params' => [$normalizedPatientId, $procedureDate, $procedureDate],
            ];
        }
        // Etapas por study_date_dicom (fecha real del estudio DICOM 0008.0020) — más precisa
        if ($studyDateDicom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $studyDateDicom) && $modality !== '') {
            $mc = sqlModalityCondition('e.modality', $modality);
            $strictStages[] = [
                'mode' => 'strict_pid_modality_study_date_3d',
                'where' => "{$pidExpr} = ? AND {$mc['sql']} AND e.study_date BETWEEN DATE_SUB(?, INTERVAL 3 DAY) AND DATE_ADD(?, INTERVAL 3 DAY)",
                'params' => array_merge([$normalizedPatientId], $mc['params'], [$studyDateDicom, $studyDateDicom]),
            ];
        }
        if ($studyDateDicom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $studyDateDicom)) {
            $strictStages[] = [
                'mode' => 'strict_pid_study_date_7d',
                'where' => "{$pidExpr} = ? AND e.study_date BETWEEN DATE_SUB(?, INTERVAL 7 DAY) AND DATE_ADD(?, INTERVAL 7 DAY)",
                'params' => [$normalizedPatientId, $studyDateDicom, $studyDateDicom],
            ];
        }
        if ($modality !== '') {
            $mc = sqlModalityCondition('e.modality', $modality);
            $strictStages[] = [
                'mode' => 'strict_pid_modality',
                'where' => "{$pidExpr} = ? AND {$mc['sql']}",
                'params' => array_merge([$normalizedPatientId], $mc['params']),
            ];
        }
        $strictStages[] = [
            'mode' => 'strict_pid_only',
            'where' => "{$pidExpr} = ?",
            'params' => [$normalizedPatientId],
        ];

        foreach ($strictStages as $stage) {
            $rows = fetchCandidateRows($db, $stage['where'], $stage['params'], 300);
            if (!empty($rows)) {
                $selectionMode = $stage['mode'];
                break;
            }
        }
    }

    // Fallback amplio (OR) solo si modo estricto no devolvió resultados.
    // Si viene patient_id y no hay q manual, NO abrimos fallback para evitar sugerencias de otro paciente.
    if (empty($rows) && $normalizedPatientId !== '' && $searchQ === '') {
        $selectionMode = 'strict_pid_no_matches';
    }

    if (empty($rows)) {
        $orWhere = [];
        $params = [];

        if ($accessionNumber !== '') {
            $orWhere[] = "e.accession_number = ?";
            $params[] = $accessionNumber;
        }
        if ($normalizedPatientId !== '') {
            $orWhere[] = "{$pidExpr} = ?";
            $params[] = $normalizedPatientId;
        }
        if ($procedureDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $procedureDate)) {
            $orWhere[] = "e.study_date BETWEEN DATE_SUB(?, INTERVAL 7 DAY) AND DATE_ADD(?, INTERVAL 7 DAY)";
            $params[] = $procedureDate;
            $params[] = $procedureDate;
        }
        if ($patientName !== '') {
            $parts = preg_split('/\s+/', ir_score_normalize_text($patientName));
            if (!empty($parts[0])) {
                $orWhere[] = "e.patient_name_pacs LIKE ?";
                $params[] = '%' . $parts[0] . '%';
            }
        }
        if ($searchQ !== '') {
            $orWhere[] = "(e.patient_name_pacs LIKE ? OR e.patient_id_pacs LIKE ? OR e.accession_number LIKE ? OR e.study_instance_uid LIKE ? OR e.study_description LIKE ?)";
            $like = '%' . $searchQ . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if (!empty($orWhere) && !($selectionMode === 'strict_pid_no_matches')) {
            $rows = fetchCandidateRows($db, implode(' OR ', $orWhere), $params, 300);
        }
    }

    // Búsqueda directa en PACS forzada por UI.
    if ($forcePacsSearch) {
        try {
            $orthanc = new OrthancClient();
            $dateFrom = null;
            $dateTo = null;
            // Buscar ±30 días alrededor de la fecha de procedimiento; si hay study_date_dicom,
            // usar la ventana que cubra ambas fechas (siempre al menos +15d).
            if ($procedureDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $procedureDate)) {
                $dateFrom = date('Y-m-d', strtotime($procedureDate . ' -30 days'));
                $dateTo = date('Y-m-d', strtotime($procedureDate . ' +15 days'));
                if ($studyDateDicom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $studyDateDicom)) {
                    $sdTo = date('Y-m-d', strtotime($studyDateDicom . ' +7 days'));
                    if ($sdTo > $dateTo) {
                        $dateTo = $sdTo;
                    }
                }
            }
            $queryPatientId = $searchQ !== '' ? $searchQ : ($normalizedPatientId !== '' ? $normalizedPatientId : $patientId);
            $manualStudies = $orthanc->getAllStudiesEfficient($dateFrom, $dateTo, $queryPatientId !== '' ? $queryPatientId : null, $modality !== '' ? $modality : null, false);
            $manualRows = mapOrthancStudiesToRows($db, $manualStudies, $modality, $procedureDate, $searchQ);
            $rows = $manualRows;
            $selectionMode = 'orthanc_manual_search_forced';
        } catch (Exception $e) {
            error_log('[CANDIDATOS_ESTUDIO] Búsqueda directa PACS falló: ' . $e->getMessage());
            $rows = [];
            $selectionMode = 'orthanc_manual_search_forced_error';
        }
    }

    // Consulta Orthanc en vivo por patient_id para incluir estudios que aún no están en la BD local.
    // Se ejecuta siempre (no solo cuando la BD local está vacía) para que estudios recientes en PACS
    // que todavía no fueron sincronizados a la tabla estudios aparezcan como candidatos.
    if (!$forcePacsSearch && $normalizedPatientId !== '') {
        try {
            $orthanc = new OrthancClient();
            $orthancStudies = $orthanc->findStudiesByPatientId($normalizedPatientId);
            $orthancRows = mapOrthancStudiesToRows($db, $orthancStudies, $modality, $procedureDate, $searchQ);

            if (!empty($orthancRows)) {
                if (empty($rows)) {
                    $rows = $orthancRows;
                    $selectionMode = 'orthanc_fallback_pid';
                } else {
                    // Fusionar: agregar solo estudios de Orthanc que no estén ya en los resultados locales.
                    $localOrthancIds = [];
                    $localUids = [];
                    foreach ($rows as $r) {
                        $oid = trim((string)($r['orthanc_study_id'] ?? ''));
                        $uid = trim((string)($r['study_instance_uid'] ?? ''));
                        if ($oid !== '') $localOrthancIds[$oid] = true;
                        if ($uid !== '') $localUids[$uid] = true;
                    }
                    foreach ($orthancRows as $or) {
                        $oid = trim((string)($or['orthanc_study_id'] ?? ''));
                        $uid = trim((string)($or['study_instance_uid'] ?? ''));
                        $alreadyPresent = ($oid !== '' && isset($localOrthancIds[$oid]))
                                       || ($uid !== '' && isset($localUids[$uid]));
                        if (!$alreadyPresent) {
                            $rows[] = $or;
                            $selectionMode = 'local_plus_orthanc';
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log('[CANDIDATOS_ESTUDIO] Consulta Orthanc por patient_id falló: ' . $e->getMessage());
        }
    }

    // Stage 3: búsqueda por fecha exacta + token de apellido en Orthanc.
    // Captura estudios donde el patient_id fue ingresado mal en el equipo (sin worklist).
    // Se ejecuta cuando: no se hizo pacs_search forzado, hay fecha de procedimiento y hay nombre.
    // Una sola consulta liviana a /tools/find acotada por StudyDate exacta + PatientName*.
    if (!$forcePacsSearch && $procedureDate !== '' && $patientName !== '') {
        $firstNameToken = strtoupper(preg_replace('/[^A-Za-z\x80-\xFF]/u', '', explode(' ', trim($patientName))[0]));
        if (strlen($firstNameToken) >= 3) {
            try {
                $orthanc3  = isset($orthanc) && $orthanc instanceof OrthancClient ? $orthanc : new OrthancClient();
                $dateDicom = str_replace('-', '', $procedureDate);
                $pacsDate  = $orthanc3->findStudiesByDateAndNameToken($dateDicom, $firstNameToken);
                // Deduplicar contra los rows ya encontrados
                $seenOid3 = [];
                $seenUid3 = [];
                foreach ($rows as $r) {
                    $o = trim((string)($r['orthanc_study_id'] ?? ''));
                    $u = trim((string)($r['study_instance_uid'] ?? ''));
                    if ($o !== '') { $seenOid3[$o] = true; }
                    if ($u !== '') { $seenUid3[$u] = true; }
                }
                $addedDate3 = 0;
                foreach ($pacsDate as $ps) {
                    if ($ps['is_pacs_pdf_informe'] ?? false) {
                        continue;
                    }
                    $studyMod = strtoupper(trim((string)($ps['modality'] ?? '')));
                    if ($studyMod === 'DOC') {
                        continue;
                    }
                    $oid = trim((string)($ps['orthanc_id'] ?? $ps['study_id'] ?? ''));
                    $uid = trim((string)($ps['study_instance_uid'] ?? ''));
                    if (($oid !== '' && isset($seenOid3[$oid])) || ($uid !== '' && isset($seenUid3[$uid]))) {
                        continue;
                    }
                    $studyDateIso = ir_score_normalize_dicom_date_to_iso($ps['study_date'] ?? null);
                    $rows[] = [
                        'id'                  => null,
                        'accession_number'    => $ps['accession_number'] ?: null,
                        'patient_id_pacs'     => $ps['patient_id'] ?: null,
                        'patient_name_pacs'   => $ps['patient_name'] ?: null,
                        'modality'            => $studyMod ?: null,
                        'study_date'          => $studyDateIso,
                        'orthanc_study_id'    => $oid ?: null,
                        'orthanc_internal_id' => $oid ?: null,
                        'study_instance_uid'  => $uid ?: null,
                        'study_description'   => $ps['study_description'] ?? null,
                        'source'              => 'pacs_date_name',
                    ];
                    if ($oid !== '') { $seenOid3[$oid] = true; }
                    if ($uid !== '') { $seenUid3[$uid] = true; }
                    $addedDate3++;
                }
                if ($addedDate3 > 0 && $selectionMode === null) {
                    $selectionMode = 'pacs_date_name';
                } elseif ($addedDate3 > 0) {
                    $selectionMode .= '+pacs_date_name';
                }
            } catch (Exception $e) {
                error_log('[CANDIDATOS_ESTUDIO] Stage 3 (fecha+nombre): ' . $e->getMessage());
            }
        }
    }

    // Fallback de búsqueda manual PACS (como modal adjuntar PDF) cuando no hay candidatos.
    if (!$forcePacsSearch && empty($rows) && $searchQ !== '') {
        try {
            $orthanc = isset($orthanc) && $orthanc instanceof OrthancClient ? $orthanc : new OrthancClient();
            $dateFrom = null;
            $dateTo = null;
            if ($procedureDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $procedureDate)) {
                $dateFrom = date('Y-m-d', strtotime($procedureDate . ' -30 days'));
                $dateTo = date('Y-m-d', strtotime($procedureDate . ' +15 days'));
                if ($studyDateDicom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $studyDateDicom)) {
                    $sdTo = date('Y-m-d', strtotime($studyDateDicom . ' +7 days'));
                    if ($sdTo > $dateTo) {
                        $dateTo = $sdTo;
                    }
                }
            }
            $manualStudies = $orthanc->getAllStudiesEfficient($dateFrom, $dateTo, $searchQ, $modality !== '' ? $modality : null, false);
            $manualRows = mapOrthancStudiesToRows($db, $manualStudies, $modality, $procedureDate, $searchQ);
            if (!empty($manualRows)) {
                $rows = $manualRows;
                $selectionMode = 'orthanc_manual_search';
            }
        } catch (Exception $e) {
            error_log('[CANDIDATOS_ESTUDIO] Fallback manual PACS falló: ' . $e->getMessage());
        }
    }

    // Si el usuario escribió q en el modal, usarlo como filtro obligatorio final.
    // Esto evita mostrar candidatos que no coinciden con lo escrito.
    if ($searchQ !== '' && !empty($rows)) {
        $rows = array_values(array_filter($rows, function ($r) use ($searchQ) {
            return candidateMatchesSearchQ($r, $searchQ);
        }));
    }

    enrichCandidateStudyDatesFromPacs($rows);
    enrichCandidateStudyMetaFromOrthanc($rows);

    $inputForScore = [
        'accession_number' => $accessionNumber,
        'patient_id' => $patientId,
        'patient_name' => $patientName,
        'procedure_date' => $procedureDate,
        'modality' => $modality,
        'study_date_dicom' => $studyDateDicom,
    ];
    $scoreOpts = $searchQ !== '' ? ['search_q' => $searchQ] : [];

    $scored = [];
    foreach ($rows as $row) {
        $calc = ir_score_match_informe_estudio($inputForScore, $row, $scoreOpts);
        $row['match_score'] = $calc['score'];
        $row['match_reasons'] = $calc['reasons'];
        $row['match_reasons_text'] = ir_score_build_reasons_text($calc['reasons']);
        $row['name_similarity'] = $calc['name_similarity'];
        $row['date_diff_days'] = $calc['date_diff_days'];
        $row['date_signed_diff_days'] = $calc['date_signed_diff_days'];
        $row['accno_pacs_enriched'] = !empty($row['_accno_pacs_enriched']);
        // Flags para UI: advertir al operador cuando el ID paciente difiere o es fuzzy
        $row['patient_id_discrepante'] = in_array('patient_id_discrepante', $calc['reasons'], true);
        $row['patient_id_fuzzy']       = in_array('patient_id_prefijo', $calc['reasons'], true)
                                       || in_array('patient_id_similar', $calc['reasons'], true);
        // ID del candidato vs ID del informe recibido (para mostrar en advertencia)
        $row['candidate_patient_id']   = trim((string)($row['patient_id_pacs'] ?? $row['patient_id'] ?? ''));
        unset($row['_accno_pacs_enriched']);
        $scored[] = $row;
    }

    usort($scored, function ($a, $b) {
        $sa = (int)($a['match_score'] ?? 0);
        $sb = (int)($b['match_score'] ?? 0);
        if ($sa !== $sb) {
            return $sb <=> $sa;
        }

        $da = $a['date_diff_days'];
        $db = $b['date_diff_days'];
        $da = $da === null ? 9999 : (int)$da;
        $db = $db === null ? 9999 : (int)$db;
        if ($da !== $db) {
            return $da <=> $db;
        }

        return strcmp((string)($b['study_date'] ?? ''), (string)($a['study_date'] ?? ''));
    });

    if (count($scored) > $limit) {
        $scored = array_slice($scored, 0, $limit);
    }

    // Flag global: ¿algún candidato con score alto tiene discrepancia de patient_id?
    $hayDiscrepanteAltoScore = false;
    foreach ($scored as $s) {
        if (($s['patient_id_discrepante'] || $s['patient_id_fuzzy'])
            && ($s['match_score'] ?? 0) >= 50) {
            $hayDiscrepanteAltoScore = true;
            break;
        }
    }

    echo json_encode([
        'success' => true,
        'data' => $scored,
        'count' => count($scored),
        'selection_mode' => $selectionMode,
        'hay_discrepancia_patient_id' => $hayDiscrepanteAltoScore,
        'criteria' => [
            'recibido_id' => $recibidoId > 0 ? $recibidoId : null,
            'accession_number' => $accessionNumber !== '' ? $accessionNumber : null,
            'patient_id' => $patientId !== '' ? $patientId : null,
            'patient_name' => $patientName !== '' ? $patientName : null,
            'procedure_date' => $procedureDate !== '' ? $procedureDate : null,
            'modality' => $modality !== '' ? $modality : null,
            'q' => $searchQ !== '' ? $searchQ : null,
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

