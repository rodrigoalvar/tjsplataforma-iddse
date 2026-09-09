<?php
/**
 * Re-procesa informes recibidos con estado='recibido' (sin vincular)
 * aplicando la misma lógica de auto-vinculación por score que recibir-pdf.php.
 *
 * Acepta GET o POST con parámetros opcionales:
 *   ids[]       : array de IDs específicos a reprocesar (si se omite, procesa todos los pendientes)
 *   dry_run     : 1 → simula sin persistir cambios
 *   limit       : máximo de registros a procesar por llamada (default 50, máx 500)
 *
 * Requiere sesión de usuario autenticado (usa el mismo sistema de cookies que el resto del portal).
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../../../config/database.php';
require_once __DIR__ . '/informe_recibido_score_helper.php';
require_once '../../../classes/User.php';
require_once __DIR__ . '/../../OrthancClient.php';
require_once __DIR__ . '/informe_recibido_version_helper.php';
require_once __DIR__ . '/informe_recibido_upsert_informe.php';
require_once __DIR__ . '/informe_recibido_auto_pacs.php';

// ── Autenticación ────────────────────────────────────────────────────────────
function reproc_resolveUserId(PDO $db): ?int
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
        $user = new User($db);
        $data = $user->validateSession($token);
        return $data ? (int)$data['id'] : null;
    } catch (Exception $e) {
        return null;
    }
}

// ── Helpers (misma lógica que recibir-pdf.php) ───────────────────────────────

function reproc_pidSqlNormExpr(): string
{
    return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(UPPER(TRIM(COALESCE(patient_id_pacs, ''))), ' ', ''), '-', ''), '.', ''), '/', ''), '\\\\', ''), '_', '')";
}

/**
 * Enriquece accession_number (y study_instance_uid) de candidatos desde Orthanc.
 * Igual que enrichCandidateStudyMetaFromOrthanc en candidatos-estudio.php.
 * Sin este paso el ACCNO viene vacío de findStudiesByPatientId y el score es bajo.
 */
function reproc_enrichAccnoFromOrthanc(array &$rows): void
{
    if (empty($rows)) {
        return;
    }
    try {
        $orthanc = new OrthancClient();
    } catch (Exception $e) {
        return;
    }
    $internalIds = [];
    foreach ($rows as $r) {
        $internal = trim((string)($r['orthanc_internal_id'] ?? ''));
        if ($internal === '') {
            $oid = trim((string)($r['orthanc_study_id'] ?? ''));
            if ($oid !== '' && !preg_match('/^1\.2\.\d/', $oid)) {
                $internal = $oid;
            }
        }
        if ($internal !== '') {
            $internalIds[$internal] = true;
        }
    }
    if (empty($internalIds)) {
        return;
    }
    $metaByInternal = [];
    foreach (array_keys($internalIds) as $iid) {
        $metaByInternal[$iid] = $orthanc->getStudyPacsDisplayMeta($iid);
    }
    foreach ($rows as $i => $r) {
        $internal = trim((string)($r['orthanc_internal_id'] ?? ''));
        if ($internal === '') {
            $oid = trim((string)($r['orthanc_study_id'] ?? ''));
            if ($oid !== '' && !preg_match('/^1\.2\.\d/', $oid)) {
                $internal = $oid;
            }
        }
        if ($internal === '' || !isset($metaByInternal[$internal])) {
            continue;
        }
        $m = $metaByInternal[$internal];
        if (trim((string)($r['study_instance_uid'] ?? '')) === '' && ($m['study_instance_uid'] ?? '') !== '') {
            $rows[$i]['study_instance_uid'] = $m['study_instance_uid'];
        }
        if (trim((string)($r['accession_number'] ?? '')) === '' && ($m['accession_number'] ?? '') !== '') {
            $rows[$i]['accession_number']     = $m['accession_number'];
            $rows[$i]['_accno_pacs_enriched'] = true;
        }
    }
}

/** CR y DX son equivalentes (TXT externos usan CR, estudios en PACS suelen ser DX). */
function reproc_sqlModalityCondition(string $field, string $modality): array
{
    $m = strtoupper(trim($modality));
    $equivalents = ['CR' => 'DX', 'DX' => 'CR'];
    if (isset($equivalents[$m])) {
        return ['sql' => "UPPER(TRIM({$field})) IN (?, ?)", 'params' => [$m, $equivalents[$m]]];
    }
    return ['sql' => "UPPER(TRIM({$field})) = ?", 'params' => [$m]];
}

function reproc_findBestCandidate(PDO $db, array $payload): ?array
{
    $accNo     = trim((string)($payload['accession_number'] ?? ''));
    $patientId = ir_score_normalize_patient_id($payload['patient_id'] ?? '');
    $date      = trim((string)($payload['procedure_date'] ?? ''));
    $modality  = strtoupper(trim((string)($payload['modality'] ?? '')));
    $validDate = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);

    $baseSelect = "SELECT id, accession_number, patient_id_pacs, patient_name_pacs,
                          modality, study_date, orthanc_study_id, study_instance_uid";
    $rows = [];
    $mode = null;

    // Stage 0: ACCNO exacto local (excluir DOC: informes PDF en PACS, no estudios de imagen)
    if ($accNo !== '') {
        $stmt = $db->prepare("{$baseSelect} FROM estudios WHERE accession_number = ? AND UPPER(TRIM(COALESCE(modality, ''))) <> 'DOC' LIMIT 20");
        $stmt->execute([$accNo]);
        $r = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($r)) {
            $rows = $r;
            $mode = 'accno_exacto_local';
        }
    }

    // Stages escalonados por patient_id
    if (empty($rows) && $patientId !== '') {
        $pidExpr = reproc_pidSqlNormExpr();
        $stages  = [];
        if ($validDate && $modality !== '') {
            $mc = reproc_sqlModalityCondition('modality', $modality);
            $stages[] = ['mode' => 'pid_mod_date_8d', 'where' => "{$pidExpr} = ? AND {$mc['sql']} AND study_date BETWEEN DATE_SUB(?, INTERVAL 8 DAY) AND DATE_ADD(?, INTERVAL 8 DAY)", 'params' => array_merge([$patientId], $mc['params'], [$date, $date])];
        }
        if ($validDate) {
            $stages[] = ['mode' => 'pid_date_15d', 'where' => "{$pidExpr} = ? AND study_date BETWEEN DATE_SUB(?, INTERVAL 15 DAY) AND DATE_ADD(?, INTERVAL 15 DAY)", 'params' => [$patientId, $date, $date]];
        }
        if ($modality !== '') {
            $mc = reproc_sqlModalityCondition('modality', $modality);
            $stages[] = ['mode' => 'pid_mod', 'where' => "{$pidExpr} = ? AND {$mc['sql']}", 'params' => array_merge([$patientId], $mc['params'])];
        }
        $stages[] = ['mode' => 'pid_solo', 'where' => "{$pidExpr} = ?", 'params' => [$patientId]];

        foreach ($stages as $stage) {
            $sql  = "{$baseSelect} FROM estudios WHERE ({$stage['where']}) AND UPPER(TRIM(COALESCE(modality, ''))) <> 'DOC' ORDER BY study_date DESC, id DESC LIMIT 300";
            $stmt = $db->prepare($sql);
            $stmt->execute($stage['params']);
            $r = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($r)) {
                $rows = $r;
                $mode = $stage['mode'];
                break;
            }
        }
    }

    // Igual que candidatos-estudio.php: si la BD local ya devolvió filas por patient_id,
    // seguir consultando Orthanc y fusionar estudios que aún no están en estudios locales.
    // Así el mejor score puede ser el estudio reciente solo en PACS.
    if (
        !empty($rows) && $patientId !== ''
        && $mode !== 'accno_exacto_local'
        && strpos((string)$mode, 'pacs_fallback') === false
    ) {
        try {
            $orthanc     = new OrthancClient();
            $pacsStudies = $orthanc->findStudiesByPatientId($patientId);
            $seenOid     = [];
            $seenUid     = [];
            foreach ($rows as $r) {
                $o = trim((string)($r['orthanc_study_id'] ?? ''));
                $u = trim((string)($r['study_instance_uid'] ?? ''));
                if ($o !== '') {
                    $seenOid[$o] = true;
                }
                if ($u !== '') {
                    $seenUid[$u] = true;
                }
            }
            $added = 0;
            foreach ($pacsStudies as $ps) {
                $studyMod = strtoupper(trim((string)($ps['modality'] ?? '')));
                if ($studyMod === 'DOC') {
                    continue;
                }
                // Usar ir_score_modality_matches para CR↔DX equivalencia
                if ($modality !== '' && $studyMod !== '') {
                    $tokens = preg_split('/[\s,\\\\\/;|]+/', $studyMod);
                    $tokens = array_map('trim', array_filter($tokens));
                    $anyMatch = false;
                    foreach ($tokens as $t) {
                        if (ir_score_modality_matches($modality, $t)) { $anyMatch = true; break; }
                    }
                    if (!$anyMatch) {
                        continue;
                    }
                }
                $studyDateIso = ir_score_normalize_dicom_date_to_iso($ps['study_date'] ?? null);
                if ($validDate && $studyDateIso) {
                    $diff = ir_score_day_diff_abs($date, $studyDateIso);
                    if ($diff !== null && $diff > 30) {
                        continue;
                    }
                }
                $oid = trim((string)($ps['orthanc_id'] ?? $ps['study_id'] ?? ''));
                $uid = trim((string)($ps['study_instance_uid'] ?? ''));
                $dup = ($oid !== '' && isset($seenOid[$oid])) || ($uid !== '' && isset($seenUid[$uid]));
                if ($dup) {
                    continue;
                }
                $rows[] = [
                    'id'                 => null,
                    'accession_number'   => $ps['accession_number'] ?? null,
                    'patient_id_pacs'    => $ps['patient_id'] ?? null,
                    'patient_name_pacs'  => $ps['patient_name'] ?? null,
                    'modality'           => $studyMod ?: null,
                    'study_date'         => $studyDateIso,
                    'orthanc_study_id'   => $oid !== '' ? $oid : null,
                    'study_instance_uid' => $uid !== '' ? $uid : null,
                    'source'             => 'pacs_only',
                ];
                if ($oid !== '') {
                    $seenOid[$oid] = true;
                }
                if ($uid !== '') {
                    $seenUid[$uid] = true;
                }
                $added++;
            }
            if ($added > 0 && $mode !== null) {
                $mode .= '_plus_orthanc';
            }
        } catch (Exception $e) {
            error_log('[REPROC_IR] merge Orthanc en candidatos locales: ' . $e->getMessage());
        }
    }

    // Fallback PACS por patient_id
    if (empty($rows) && $patientId !== '') {
        try {
            $orthanc     = new OrthancClient();
            $pacsStudies = $orthanc->findStudiesByPatientId($patientId);
            foreach ($pacsStudies as $ps) {
                $studyMod = strtoupper(trim((string)($ps['modality'] ?? '')));
                if ($studyMod === 'DOC') {
                    continue;
                }
                // Usar ir_score_modality_matches para CR↔DX equivalencia
                if ($modality !== '' && $studyMod !== '') {
                    $tokens = preg_split('/[\s,\\\\\/;|]+/', $studyMod);
                    $tokens = array_map('trim', array_filter($tokens));
                    $anyMatch = false;
                    foreach ($tokens as $t) {
                        if (ir_score_modality_matches($modality, $t)) { $anyMatch = true; break; }
                    }
                    if (!$anyMatch) {
                        continue;
                    }
                }
                $studyDateIso = ir_score_normalize_dicom_date_to_iso($ps['study_date'] ?? null);
                if ($validDate && $studyDateIso) {
                    $diff = ir_score_day_diff_abs($date, $studyDateIso);
                    if ($diff !== null && $diff > 30) {
                        continue;
                    }
                }
                $rows[] = [
                    'id'                => null,
                    'accession_number'  => $ps['accession_number'] ?? null,
                    'patient_id_pacs'   => $ps['patient_id'] ?? null,
                    'patient_name_pacs' => $ps['patient_name'] ?? null,
                    'modality'          => $studyMod ?: null,
                    'study_date'        => $studyDateIso,
                    'orthanc_study_id'  => $ps['orthanc_id'] ?? $ps['study_id'] ?? null,
                    'study_instance_uid'=> $ps['study_instance_uid'] ?? null,
                    'source'            => 'pacs_only',
                ];
            }
            if (!empty($rows)) {
                $mode = 'pacs_fallback_pid';
            }
        } catch (Exception $e) {
            error_log('[REPROC_IR] PACS fallback error: ' . $e->getMessage());
        }
    }

    // Stage 3: búsqueda por fecha exacta + token de apellido en Orthanc.
    // Se activa cuando no hay candidatos de los stages anteriores, o cuando existe candidato
    // pero su score máximo preliminar podría ser bajo (ej. patient_id no coincide por error de carga).
    // Esta consulta es liviana (un solo request a Orthanc acotado por fecha exacta).
    if ($validDate && trim((string)($payload['patient_name'] ?? '')) !== '') {
        $firstToken = strtoupper(preg_replace('/[^A-Za-záéíóúÁÉÍÓÚñÑüÜ]/u', '', explode(' ', trim($payload['patient_name']))[0]));
        if (strlen($firstToken) >= 3) {
            try {
                $orthanc3   = new OrthancClient();
                $dateDicom  = str_replace('-', '', $date); // YYYY-MM-DD → YYYYMMDD
                $pacsDate   = $orthanc3->findStudiesByDateAndNameToken($dateDicom, $firstToken);
                $seenOid3 = [];
                $seenUid3 = [];
                foreach ($rows as $r) {
                    $o = trim((string)($r['orthanc_study_id'] ?? ''));
                    $u = trim((string)($r['study_instance_uid'] ?? ''));
                    if ($o !== '') { $seenOid3[$o] = true; }
                    if ($u !== '') { $seenUid3[$u] = true; }
                }
                $addedDate = 0;
                foreach ($pacsDate as $ps) {
                    $studyMod = strtoupper(trim((string)($ps['modality'] ?? '')));
                    if ($studyMod === 'DOC' || ($ps['is_pacs_pdf_informe'] ?? false)) {
                        continue;
                    }
                    $oid = trim((string)($ps['orthanc_id'] ?? $ps['study_id'] ?? ''));
                    $uid = trim((string)($ps['study_instance_uid'] ?? ''));
                    if (($oid !== '' && isset($seenOid3[$oid])) || ($uid !== '' && isset($seenUid3[$uid]))) {
                        continue;
                    }
                    $studyDateIso = ir_score_normalize_dicom_date_to_iso($ps['study_date'] ?? null);
                    $rows[] = [
                        'id'                 => null,
                        'accession_number'   => $ps['accession_number'] ?? null,
                        'patient_id_pacs'    => $ps['patient_id'] ?? null,
                        'patient_name_pacs'  => $ps['patient_name'] ?? null,
                        'modality'           => $studyMod ?: null,
                        'study_date'         => $studyDateIso,
                        'orthanc_study_id'   => $oid !== '' ? $oid : null,
                        'study_instance_uid' => $uid !== '' ? $uid : null,
                        'source'             => 'pacs_date_name',
                    ];
                    if ($oid !== '') { $seenOid3[$oid] = true; }
                    if ($uid !== '') { $seenUid3[$uid] = true; }
                    $addedDate++;
                }
                if ($addedDate > 0) {
                    $mode = ($mode !== null ? $mode . '+' : '') . 'pacs_date_name';
                }
            } catch (Exception $e) {
                error_log('[REPROC_IR] Stage 3 (fecha+nombre): ' . $e->getMessage());
            }
        }
    }

    if (empty($rows)) {
        return null;
    }

    // Enriquecer ACCNO y StudyInstanceUID desde Orthanc (igual que candidatos-estudio.php).
    // Sin esto el ACCNO viene vacío de findStudiesByPatientId y el score no supera el umbral.
    reproc_enrichAccnoFromOrthanc($rows);

    $best = null;
    foreach ($rows as $row) {
        $calc = ir_score_match_informe_estudio($payload, $row, []);
        if ($best === null || $calc['score'] > $best['match_score']) {
            $row['match_score']    = $calc['score'];
            $row['match_reasons']  = $calc['reasons'];
            $row['selection_mode'] = $mode;
            $best = $row;
        }
    }
    return $best;
}

function reproc_resolveOrCreateEstudio(PDO $db, array $pacsRow): ?int
{
    $orthancId = trim((string)($pacsRow['orthanc_study_id'] ?? ''));
    $uid       = trim((string)($pacsRow['study_instance_uid'] ?? ''));
    if ($orthancId === '' && $uid === '') {
        return null;
    }

    // Incluye el caso legacy: orthanc_study_id almacena el Study UID (1.2...) en vez del UUID REST.
    $conds  = [];
    $params = [];
    if ($orthancId !== '') {
        $conds[]  = '(orthanc_study_id = ? AND ? <> \'\')';
        $params[] = $orthancId;
        $params[] = $orthancId;
    }
    if ($uid !== '') {
        $conds[]  = '(study_instance_uid = ? AND ? <> \'\')';
        $params[] = $uid;
        $params[] = $uid;
        // UID guardado en orthanc_study_id (datos legado)
        $conds[]  = '(orthanc_study_id = ? AND ? <> \'\')';
        $params[] = $uid;
        $params[] = $uid;
    }
    $stmt = $db->prepare('SELECT id FROM estudios WHERE (' . implode(' OR ', $conds) . ') ORDER BY id ASC LIMIT 1');
    $stmt->execute($params);
    $eid = $stmt->fetchColumn();
    if ($eid) {
        return (int)$eid;
    }

    try {
        $modality  = strtoupper(trim((string)($pacsRow['modality'] ?? ''))) ?: 'DOC';
        $studyDate = trim((string)($pacsRow['study_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $studyDate)) {
            $studyDate = null;
        }
        $accNo = trim((string)($pacsRow['accession_number'] ?? ''));
        $db->prepare("
            INSERT INTO estudios (
                orthanc_study_id, patient_id_pacs, patient_name_pacs,
                modality, study_date, study_instance_uid, accession_number,
                status, fecha_creacion
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'COMPLETADO', NOW())
        ")->execute([
            $orthancId !== '' ? $orthancId : null,
            trim((string)($pacsRow['patient_id_pacs'] ?? '')) ?: null,
            trim((string)($pacsRow['patient_name_pacs'] ?? '')) ?: null,
            $modality,
            $studyDate,
            $uid !== '' ? $uid : null,
            $accNo !== '' ? $accNo : null,
        ]);
        return (int)$db->lastInsertId();
    } catch (Exception $e) {
        error_log('[REPROC_IR] resolveOrCreateEstudio error: ' . $e->getMessage());
        return null;
    }
}

function reproc_parseBoolConfig(string $val): bool
{
    return in_array(strtolower(trim($val)), ['1', 'si', 'sí', 'yes', 'true'], true);
}

function reproc_getAutoLinkConfig(PDO $db): array
{
    $get = static function (PDO $db, string $key, string $default) {
        $stmt = $db->prepare('SELECT valor FROM configuracion WHERE clave = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return isset($row['valor']) ? (string)$row['valor'] : $default;
    };

    $enabled         = reproc_parseBoolConfig($get($db, 'ir_auto_vincular_activo', '0'));
    $minScore        = max(0, min(100, (int)$get($db, 'ir_auto_vincular_min_score', '75')));
    $requireAccno    = reproc_parseBoolConfig($get($db, 'ir_auto_vincular_requiere_accno_exacto', '1'));
    $requirePatId    = reproc_parseBoolConfig($get($db, 'ir_auto_vincular_requiere_patient_id', '0'));
    $onPacs          = strtolower(trim($get($db, 'ir_auto_vincular_en_pacs', 'bloquear')));
    if (!in_array($onPacs, ['bloquear', 'permitir'], true)) {
        $onPacs = 'bloquear';
    }

    return [
        'enabled'          => $enabled,
        'min_score'        => $minScore,
        'require_accno'    => $requireAccno,
        'require_patient_id' => $requirePatId,
        'on_pacs'          => $onPacs,
    ];
}

// ── Main ─────────────────────────────────────────────────────────────────────
try {
    $db = getDBConnection();

    $userId = reproc_resolveUserId($db);
    if (!$userId) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No autenticado']);
        exit();
    }

    $input   = [];
    $rawBody = file_get_contents('php://input');
    if ($rawBody !== '') {
        $input = json_decode($rawBody, true) ?? [];
    }
    $input = array_merge($_GET, $_POST, $input);

    $dryRun     = !empty($input['dry_run']) && $input['dry_run'] !== '0';
    $limitInput = isset($input['limit']) ? (int)$input['limit'] : 50;
    $limitInput = max(1, min(500, $limitInput));
    $idsInput   = [];
    if (!empty($input['ids'])) {
        $raw = is_array($input['ids']) ? $input['ids'] : explode(',', (string)$input['ids']);
        foreach ($raw as $v) {
            $id = (int)$v;
            if ($id > 0) {
                $idsInput[] = $id;
            }
        }
    }

    $autoCfg = reproc_getAutoLinkConfig($db);

    $irExtraCols = '';
    try {
        $icc = $db->query('SHOW COLUMNS FROM informes_recibidos');
        $icn = $icc ? $icc->fetchAll(PDO::FETCH_COLUMN) : [];
        if (is_array($icn) && in_array('study_date_dicom', $icn, true)) {
            $irExtraCols = ', study_date_dicom';
        }
    } catch (Exception $e) {
        $irExtraCols = '';
    }

    // Cargar los IR pendientes
    if (!empty($idsInput)) {
        $placeholders = implode(',', array_fill(0, count($idsInput), '?'));
        $irStmt       = $db->prepare("
            SELECT id, accession_number, patient_id, patient_name,
                   modality, procedure_date, pdf_path, procedure_description{$irExtraCols}
            FROM informes_recibidos
            WHERE id IN ({$placeholders}) AND estado = 'recibido'
            LIMIT {$limitInput}
        ");
        $irStmt->execute($idsInput);
    } else {
        $irStmt = $db->prepare("
            SELECT id, accession_number, patient_id, patient_name,
                   modality, procedure_date, pdf_path, procedure_description{$irExtraCols}
            FROM informes_recibidos
            WHERE estado = 'recibido'
            ORDER BY id ASC
            LIMIT {$limitInput}
        ");
        $irStmt->execute();
    }
    $pendientes = $irStmt->fetchAll(PDO::FETCH_ASSOC);

    $systemUserId = (int)$db->query('SELECT id FROM usuarios ORDER BY id ASC LIMIT 1')->fetchColumn();

    $resultados        = [];
    $vinculados        = 0;
    $sin_candidato     = 0;
    $score_bajo        = 0;
    $errores           = 0;
    $excluidos_mod     = 0;

    foreach ($pendientes as $ir) {
        $irId   = (int)$ir['id'];
        $result = [
            'id'              => $irId,
            'accession_number'=> $ir['accession_number'],
            'estado_final'    => 'sin_cambio',
            'score'           => null,
            'selection_mode'  => null,
            'motivo'          => null,
            'estudio_id'      => null,
            'informe_id'      => null,
        ];

        try {
            // Verificar si la modalidad está excluida de búsqueda automática en PACS
            $irModality = strtoupper(trim((string)($ir['modality'] ?? '')));
            if ($irModality !== '' && ir_isModalidadExcluida($irModality, $db)) {
                $result['estado_final'] = 'pendiente_sin_pacs';
                $result['motivo']       = "Modalidad {$irModality} excluida de búsqueda automática en PACS";
                if (!$dryRun) {
                    $db->prepare("UPDATE informes_recibidos SET estado = 'pendiente_sin_pacs' WHERE id = ?")
                       ->execute([$irId]);
                }
                $excluidos_mod++;
                $resultados[] = $result;
                continue;
            }

            $payload = [
                'accession_number' => $ir['accession_number'],
                'patient_id'       => $ir['patient_id'],
                'patient_name'     => $ir['patient_name'],
                'modality'         => $ir['modality'],
                'procedure_date'   => $ir['procedure_date'],
                'study_date_dicom' => isset($ir['study_date_dicom']) ? trim((string)$ir['study_date_dicom']) : '',
            ];

            $best = reproc_findBestCandidate($db, $payload);

            if (!$best) {
                $result['estado_final'] = 'sin_candidato';
                $result['motivo']       = 'No se encontró candidato en estudios ni en PACS';
                $sin_candidato++;
                $resultados[] = $result;
                continue;
            }

            $score   = (int)($best['match_score'] ?? 0);
            $selMode = $best['selection_mode'] ?? '';
            $result['score']          = $score;
            $result['selection_mode'] = $selMode;

            $accOk = !$autoCfg['require_accno'] || (
                trim((string)($ir['accession_number'] ?? '')) !== '' &&
                trim((string)($best['accession_number'] ?? '')) !== '' &&
                strcasecmp((string)$ir['accession_number'], (string)$best['accession_number']) === 0
            );

            $pidFoundByStage = (strpos($selMode, 'pid') !== false);
            $pidInNorm       = ir_score_normalize_patient_id($ir['patient_id'] ?? '');
            $pidBestNorm     = ir_score_normalize_patient_id($best['patient_id_pacs'] ?? '');
            $pidOk           = !$autoCfg['require_patient_id']
                || $pidFoundByStage
                || ($pidInNorm !== '' && $pidBestNorm !== '' && $pidInNorm === $pidBestNorm);

            // Bloqueo explícito por discrepancia de patient_id: el ID del estudio en PACS
            // fue ingresado incorrectamente (typo, dígito extra/faltante). El operador debe
            // corregir el dato en PACS antes de vincular. Nunca auto-vincular en estos casos.
            $matchReasons = $best['match_reasons'] ?? [];
            $pidDiscrepante = array_intersect(
                ['patient_id_discrepante', 'patient_id_similar', 'patient_id_prefijo'],
                $matchReasons
            ) !== [];
            if ($pidDiscrepante) {
                $result['estado_final'] = 'pid_discrepante';
                $result['motivo']       = 'ID paciente en PACS difiere del informe (posible error de carga manual). Requiere revisión y corrección manual en PACS.';
                $score_bajo++;
                $resultados[] = $result;
                continue;
            }

            if ($score < $autoCfg['min_score'] || !$accOk || !$pidOk) {
                $result['estado_final'] = 'score_insuficiente';
                $result['motivo']       = "Score {$score} < umbral {$autoCfg['min_score']}";
                if (!$accOk) {
                    $result['motivo'] .= ' (ACCNO requerido no coincide)';
                }
                if (!$pidOk) {
                    $result['motivo'] .= ' (patient_id requerido no coincide)';
                }
                $score_bajo++;
                $resultados[] = $result;
                continue;
            }

            // Resolver / crear entrada en estudios si viene de PACS (sin alta local)
            $estudioId  = ($best['id'] !== null) ? (int)$best['id'] : null;
            $metodo     = 'auto_score';
            $isPacsOnly = $estudioId === null && in_array(
                $best['source'] ?? '',
                ['pacs_only', 'pacs_fallback_pid', 'pacs_date_name'],
                true
            );
            if ($isPacsOnly) {
                if (!$dryRun) {
                    $estudioId = reproc_resolveOrCreateEstudio($db, $best);
                } else {
                    $estudioId = -1; // placeholder en dry_run
                }
                $metodo = 'auto_score_pacs';
            }

            if (!$estudioId) {
                $result['estado_final'] = 'error_estudio';
                $result['motivo']       = 'No se pudo resolver o crear entrada en estudios';
                $errores++;
                $resultados[] = $result;
                continue;
            }

            $result['estudio_id'] = $estudioId;

            if ($dryRun) {
                $result['estado_final'] = 'vincularia';
                $result['motivo']       = implode(', ', $best['match_reasons'] ?? []);
                $vinculados++;
                $resultados[] = $result;
                continue;
            }

            // Crear / actualizar informe en informes-manager
            $informeId = ir_upsert_informe_desde_recibido($db, [
                'estudio_id'            => $estudioId,
                'accession_number'      => $ir['accession_number'],
                'patient_id'            => $ir['patient_id'],
                'patient_name'          => $ir['patient_name'],
                'modality'              => $ir['modality'],
                'procedure_description' => $ir['procedure_description'],
                'pdf_path'              => $ir['pdf_path'],
                'historial_motivo'      => 'Actualización PDF recibido por API (reproceso)',
            ], $systemUserId);

            if (!$informeId) {
                $result['estado_final'] = 'error_informe';
                $result['motivo']       = 'No se pudo crear o actualizar el informe (upsert)';
                $errores++;
                $resultados[] = $result;
                continue;
            }

            $result['informe_id'] = $informeId;

            // Verificar bloqueo PACS
            $blockedByPacs = false;
            if ($autoCfg['on_pacs'] === 'bloquear') {
                try {
                    $pacsCheck = $db->prepare("
                        SELECT COUNT(*) FROM informes
                        WHERE id = ? AND (
                            (pacs_series_id IS NOT NULL AND pacs_series_id <> '')
                            OR (pacs_instance_id IS NOT NULL AND pacs_instance_id <> '')
                            OR (pacs_study_id IS NOT NULL AND pacs_study_id <> '')
                            OR (fecha_enviado_pacs IS NOT NULL)
                        )
                    ");
                    $pacsCheck->execute([$informeId]);
                    $blockedByPacs = (int)$pacsCheck->fetchColumn() > 0;
                } catch (Exception $e) {
                    // Columnas opcionales; no bloquear si no existen
                }
            }

            if ($blockedByPacs) {
                $mensajePacs = 'Nuevo envío detectado (actualización). Debe quitar primero la versión previa del PACS.';
                $db->prepare("
                    UPDATE informes_recibidos
                    SET estado = 'recibido', estudio_id = NULL, fecha_vinculacion = NULL,
                        informe_id = NULL, metodo_vinculacion = NULL, error_message = ?
                    WHERE id = ?
                ")->execute([$mensajePacs, $irId]);
                $result['estado_final'] = 'bloqueado_pacs';
                $result['motivo']       = $mensajePacs;
                $errores++;
                $resultados[] = $result;
                continue;
            }

            // Vincular en informes_recibidos (en dos pasos: si falla JSON/score no se pierde informe_id)
            $reasonsJson = json_encode(
                $best['match_reasons'] ?? [],
                JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)
            );
            if ($reasonsJson === false) {
                $reasonsJson = '[]';
            }
            try {
                $db->prepare("
                    UPDATE informes_recibidos
                    SET estado = 'vinculado', estudio_id = ?, fecha_vinculacion = NOW(),
                        informe_id = ?, metodo_vinculacion = ?
                    WHERE id = ?
                ")->execute([$estudioId, $informeId, $metodo, $irId]);
            } catch (Exception $e) {
                // Compatibilidad con instalaciones antiguas sin todas las columnas
                $db->prepare("
                    UPDATE informes_recibidos
                    SET estado = 'vinculado', estudio_id = ?, fecha_vinculacion = NOW()
                    WHERE id = ?
                ")->execute([$estudioId, $irId]);
            }
            try {
                $db->prepare("
                    UPDATE informes_recibidos
                    SET matching_score = COALESCE(?, matching_score),
                        matching_reasons = COALESCE(?, matching_reasons)
                    WHERE id = ?
                ")->execute([$score, $reasonsJson, $irId]);
            } catch (Exception $e) {
                // Columnas opcionales
            }

            ir_markAutoPacsPending($db, $irId);
            // Auto-envío a PACS si está configurado
            ir_enviarPacsSiCorresponde($db, (int)$informeId, $irId);

            $result['estado_final'] = 'vinculado';
            $result['motivo']       = implode(', ', $best['match_reasons'] ?? []);
            $vinculados++;

        } catch (Exception $e) {
            error_log('[REPROC_IR] Error procesando IR id=' . $irId . ': ' . $e->getMessage());
            $result['estado_final'] = 'error';
            $result['motivo']       = $e->getMessage();
            $errores++;
        }

        $resultados[] = $result;
    }

    echo json_encode([
        'success'           => true,
        'dry_run'           => $dryRun,
        'procesados'        => count($pendientes),
        'vinculados'        => $vinculados,
        'sin_candidato'     => $sin_candidato,
        'score_bajo'        => $score_bajo,
        'errores'           => $errores,
        'excluidos_mod'     => $excluidos_mod,
        'min_score_cfg'     => $autoCfg['min_score'],
        'resultados'        => $resultados,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
