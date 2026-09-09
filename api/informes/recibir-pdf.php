<?php
/**
 * API Endpoint para recibir informes PDF desde otros sistemas
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Recibe:
 * - Archivo PDF (multipart/form-data, campo: 'pdf')
 * - Archivo TXT con datos DICOM (multipart/form-data, campo: 'txt')
 * 
 * El TXT debe tener formato:
 * [Message]
 * Type=Add
 * [DicomData]
 * (0008.0050)=541527
 * ...
 */

$irRecibirPdfFunctionsOnly = defined('IR_RECIBIR_PDF_FUNCTIONS_ONLY_LOAD') && IR_RECIBIR_PDF_FUNCTIONS_ONLY_LOAD === true;

if (!$irRecibirPdfFunctionsOnly) {
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);

    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Método no permitido']);
        exit();
    }
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/pdf_metadata_title.php';
require_once __DIR__ . '/recibidos/informe_recibido_version_helper.php';
require_once __DIR__ . '/recibidos/informe_recibido_score_helper.php';
require_once __DIR__ . '/recibidos/informe_recibido_auto_pacs.php';
require_once __DIR__ . '/recibidos/informe_recibido_upsert_informe.php';
require_once __DIR__ . '/../OrthancClient.php';
require_once __DIR__ . '/recibidos/dicom_txt_parser.php';

/**
 * Inserta trazabilidad del intento de recepción.
 * Si la tabla no existe o hay error, no interrumpe el flujo principal.
 */
function registrarIntentoRecepcion(?PDO $db, array $payload): ?int {
    if (!$db) {
        return null;
    }
    try {
        $sql = "INSERT INTO informes_recibidos_intentos (
                    request_id, estado, error_message, accession_number, informe_recibido_id,
                    metodo_http, content_type, remote_ip, user_agent,
                    pdf_filename, pdf_mime, pdf_size_bytes,
                    txt_filename, txt_mime, txt_size_bytes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $payload['request_id'] ?? null,
            $payload['estado'] ?? 'iniciado',
            $payload['error_message'] ?? null,
            $payload['accession_number'] ?? null,
            $payload['informe_recibido_id'] ?? null,
            $payload['metodo_http'] ?? null,
            $payload['content_type'] ?? null,
            $payload['remote_ip'] ?? null,
            $payload['user_agent'] ?? null,
            $payload['pdf_filename'] ?? null,
            $payload['pdf_mime'] ?? null,
            $payload['pdf_size_bytes'] ?? null,
            $payload['txt_filename'] ?? null,
            $payload['txt_mime'] ?? null,
            $payload['txt_size_bytes'] ?? null,
        ]);
        return (int)$db->lastInsertId();
    } catch (Exception $e) {
        error_log('[INFORMES_RECIBIDOS][TRACE] No se pudo registrar intento: ' . $e->getMessage());
        return null;
    }
}

/**
 * Actualiza trazabilidad del intento de recepción.
 * Si la tabla no existe o hay error, no interrumpe el flujo principal.
 */
function actualizarIntentoRecepcion(?PDO $db, ?int $intentoId, array $payload): void {
    if (!$db || !$intentoId) {
        return;
    }
    try {
        $sql = "UPDATE informes_recibidos_intentos
                SET estado = COALESCE(?, estado),
                    error_message = COALESCE(?, error_message),
                    accession_number = COALESCE(?, accession_number),
                    informe_recibido_id = COALESCE(?, informe_recibido_id),
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $payload['estado'] ?? null,
            $payload['error_message'] ?? null,
            $payload['accession_number'] ?? null,
            $payload['informe_recibido_id'] ?? null,
            $intentoId,
        ]);
    } catch (Exception $e) {
        error_log('[INFORMES_RECIBIDOS][TRACE] No se pudo actualizar intento: ' . $e->getMessage());
    }
}

/**
 * Obtiene un usuario del sistema para registrar informes externos.
 */
function resolveSystemUserId(PDO $db): int {
    $stmt = $db->query("SELECT id FROM usuarios ORDER BY id ASC LIMIT 1");
    $userId = (int)$stmt->fetchColumn();
    if ($userId <= 0) {
        throw new Exception('No se encontró usuario para registrar informe externo');
    }
    return $userId;
}

function getConfigValueRecibidos(PDO $db, string $key, string $default): string
{
    $stmt = $db->prepare('SELECT valor FROM configuracion WHERE clave = ? LIMIT 1');
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return isset($row['valor']) ? (string)$row['valor'] : $default;
}

function getAutoLinkConfig(PDO $db): array
{
    $parseBool = static function (string $v): bool {
        return in_array(strtolower(trim($v)), ['1', 'si', 'sí', 'yes', 'true'], true);
    };
    $enabled = $parseBool(getConfigValueRecibidos($db, 'ir_auto_vincular_activo', '0'));
    $minScore = (int)getConfigValueRecibidos($db, 'ir_auto_vincular_min_score', '75');
    $minScore = max(0, min(100, $minScore));
    $requireAccno = $parseBool(getConfigValueRecibidos($db, 'ir_auto_vincular_requiere_accno_exacto', '1'));
    $requirePatientId = $parseBool(getConfigValueRecibidos($db, 'ir_auto_vincular_requiere_patient_id', '0'));
    $onPacs = strtolower(trim(getConfigValueRecibidos($db, 'ir_auto_vincular_en_pacs', 'bloquear')));
    if (!in_array($onPacs, ['bloquear', 'permitir'], true)) {
        $onPacs = 'bloquear';
    }

    return [
        'enabled' => $enabled,
        'min_score' => $minScore,
        'require_accno' => $requireAccno,
        'require_patient_id' => $requirePatientId,
        'on_pacs' => $onPacs,
    ];
}

function computeStudyMatchScore(array $input, array $study): array
{
    $calc = ir_score_match_informe_estudio($input, $study, []);

    return ['score' => $calc['score'], 'reasons' => $calc['reasons']];
}

/**
 * Expresión SQL para normalizar patient_id_pacs (elimina separadores comunes).
 */
function autoLinkPidSqlNormExpr(): string
{
    return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(UPPER(TRIM(COALESCE(patient_id_pacs, ''))), ' ', ''), '-', ''), '.', ''), '/', ''), '\\\\', ''), '_', '')";
}

/**
 * Enriquece accession_number y study_instance_uid de candidatos consultando Orthanc por ID REST.
 * Sin este paso el ACCNO viene vacío de findStudiesByPatientId y el score no supera el umbral.
 */
function autoLinkEnrichAccnoFromOrthanc(array &$rows): void
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

/** CR y DX son equivalentes (TXT externos usan CR para radiografías, PACS suele guardar DX). */
function autoLinkModalityCondition(string $field, string $modality): array
{
    $m = strtoupper(trim($modality));
    $equivalents = ['CR' => 'DX', 'DX' => 'CR'];
    if (isset($equivalents[$m])) {
        return ['sql' => "UPPER(TRIM({$field})) IN (?, ?)", 'params' => [$m, $equivalents[$m]]];
    }
    return ['sql' => "UPPER(TRIM({$field})) = ?", 'params' => [$m]];
}

/**
 * Crea o recupera la entrada en estudios para un candidato encontrado solo en PACS (Orthanc).
 * Retorna el id del registro en estudios, o null si no fue posible.
 */
function autoLinkResolveOrCreateEstudio(PDO $db, array $pacsRow): ?int
{
    $orthancStudyId   = trim((string)($pacsRow['orthanc_study_id'] ?? ''));
    $studyInstanceUid = trim((string)($pacsRow['study_instance_uid'] ?? ''));

    if ($orthancStudyId === '' && $studyInstanceUid === '') {
        return null;
    }

    // Buscar si ya existe en estudios locales.
    // Incluye el caso legacy donde orthanc_study_id almacena el Study UID (1.2...) en vez del UUID REST.
    $conditions = [];
    $findParams = [];
    if ($orthancStudyId !== '') {
        $conditions[] = '(orthanc_study_id = ? AND ? <> \'\')';
        $findParams[] = $orthancStudyId;
        $findParams[] = $orthancStudyId;
    }
    if ($studyInstanceUid !== '') {
        $conditions[] = '(study_instance_uid = ? AND ? <> \'\')';
        $findParams[] = $studyInstanceUid;
        $findParams[] = $studyInstanceUid;
        // Caso legacy: UID guardado en orthanc_study_id
        $conditions[] = '(orthanc_study_id = ? AND ? <> \'\')';
        $findParams[] = $studyInstanceUid;
        $findParams[] = $studyInstanceUid;
    }
    $findSql  = 'SELECT id FROM estudios WHERE (' . implode(' OR ', $conditions) . ') ORDER BY id ASC LIMIT 1';
    $findStmt = $db->prepare($findSql);
    $findStmt->execute($findParams);
    $existingId = $findStmt->fetchColumn();
    if ($existingId) {
        return (int)$existingId;
    }

    // Crear nueva entrada
    try {
        $patientId   = trim((string)($pacsRow['patient_id_pacs'] ?? ''));
        $patientName = trim((string)($pacsRow['patient_name_pacs'] ?? ''));
        $modality    = strtoupper(trim((string)($pacsRow['modality'] ?? '')));
        if ($modality === '') {
            $modality = 'DOC';
        }
        $studyDate = trim((string)($pacsRow['study_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $studyDate)) {
            $studyDate = null;
        }
        $accNo = trim((string)($pacsRow['accession_number'] ?? ''));

        $createStmt = $db->prepare("
            INSERT INTO estudios (
                orthanc_study_id, patient_id_pacs, patient_name_pacs,
                modality, study_date, study_instance_uid, accession_number,
                status, fecha_creacion
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'COMPLETADO', NOW())
        ");
        $createStmt->execute([
            $orthancStudyId   !== '' ? $orthancStudyId   : null,
            $patientId        !== '' ? $patientId        : null,
            $patientName      !== '' ? $patientName      : null,
            $modality,
            $studyDate,
            $studyInstanceUid !== '' ? $studyInstanceUid : null,
            $accNo            !== '' ? $accNo            : null,
        ]);
        return (int)$db->lastInsertId();
    } catch (Exception $e) {
        error_log('[INFORMES_RECIBIDOS][AUTO_LINK] autoLinkResolveOrCreateEstudio error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Busca el mejor candidato en estudios usando estrategia escalonada:
 *   Stage 0: ACCNO exacto en estudios locales
 *   Stage 1-4: patient_id con restricciones progresivas de fecha/modalidad
 *   Fallback: consulta directa a PACS (Orthanc) por patient_id
 *
 * Retorna el mejor candidato con 'match_score', 'match_reasons' y 'selection_mode',
 * o null si no se encontró ninguno.
 * Cuando el candidato viene solo de PACS, 'id' es null y 'source' = 'pacs_only'.
 */
function findBestStudyCandidate(PDO $db, array $payload): ?array
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

    // Stage 0: ACCNO exacto en estudios locales (excluir DOC: informes PDF en PACS)
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
        $pidExpr = autoLinkPidSqlNormExpr();
        $stages  = [];

        if ($validDate && $modality !== '') {
            $mc = autoLinkModalityCondition('modality', $modality);
            $stages[] = [
                'mode'   => 'pid_mod_date_3d',
                'where'  => "{$pidExpr} = ? AND {$mc['sql']} AND study_date BETWEEN DATE_SUB(?, INTERVAL 3 DAY) AND DATE_ADD(?, INTERVAL 3 DAY)",
                'params' => array_merge([$patientId], $mc['params'], [$date, $date]),
            ];
        }
        if ($validDate) {
            $stages[] = [
                'mode'   => 'pid_date_7d',
                'where'  => "{$pidExpr} = ? AND study_date BETWEEN DATE_SUB(?, INTERVAL 7 DAY) AND DATE_ADD(?, INTERVAL 7 DAY)",
                'params' => [$patientId, $date, $date],
            ];
        }
        if ($modality !== '') {
            $mc = autoLinkModalityCondition('modality', $modality);
            $stages[] = [
                'mode'   => 'pid_mod',
                'where'  => "{$pidExpr} = ? AND {$mc['sql']}",
                'params' => array_merge([$patientId], $mc['params']),
            ];
        }
        $stages[] = [
            'mode'   => 'pid_solo',
            'where'  => "{$pidExpr} = ?",
            'params' => [$patientId],
        ];

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

    // Fallback PACS (Orthanc) por patient_id cuando no hay resultados en estudios locales
    if (empty($rows) && $patientId !== '') {
        try {
            $orthanc     = new OrthancClient();
            $pacsStudies = $orthanc->findStudiesByPatientId($patientId);
            foreach ($pacsStudies as $ps) {
                $studyMod = strtoupper(trim((string)($ps['modality'] ?? '')));
                if ($studyMod === 'DOC') {
                    continue;
                }
                // Filtrar por modalidad si ambos están definidos (soporta modalidades compuestas)
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
                // Filtrar estudios muy alejados en fecha (ventana ±30 días)
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
            error_log('[INFORMES_RECIBIDOS][AUTO_LINK] PACS fallback error: ' . $e->getMessage());
        }
    }

    // Stage 3: búsqueda por fecha exacta + token de apellido en Orthanc.
    // Encuentra estudios aunque el patient_id esté mal cargado en el equipo (sin worklist).
    if ($validDate && trim((string)($payload['patient_name'] ?? '')) !== '') {
        $firstToken = strtoupper(preg_replace('/[^A-Za-záéíóúÁÉÍÓÚñÑüÜ]/u', '', explode(' ', trim($payload['patient_name']))[0]));
        if (strlen($firstToken) >= 3) {
            try {
                $orthanc3  = new OrthancClient();
                $dateDicom = str_replace('-', '', $date);
                $pacsDate  = $orthanc3->findStudiesByDateAndNameToken($dateDicom, $firstToken);
                $seenOid3  = [];
                $seenUid3  = [];
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
                        'id'                => null,
                        'accession_number'  => $ps['accession_number'] ?? null,
                        'patient_id_pacs'   => $ps['patient_id'] ?? null,
                        'patient_name_pacs' => $ps['patient_name'] ?? null,
                        'modality'          => $studyMod ?: null,
                        'study_date'        => $studyDateIso,
                        'orthanc_study_id'  => $oid !== '' ? $oid : null,
                        'study_instance_uid'=> $uid !== '' ? $uid : null,
                        'source'            => 'pacs_date_name',
                    ];
                    if ($oid !== '') { $seenOid3[$oid] = true; }
                    if ($uid !== '') { $seenUid3[$uid] = true; }
                    $addedDate++;
                }
                if ($addedDate > 0) {
                    $mode = ($mode !== null ? $mode . '+' : '') . 'pacs_date_name';
                }
            } catch (Exception $e) {
                error_log('[INFORMES_RECIBIDOS][AUTO_LINK] Stage 3 (fecha+nombre): ' . $e->getMessage());
            }
        }
    }

    if (empty($rows)) {
        return null;
    }

    // Enriquecer ACCNO y StudyInstanceUID desde Orthanc (igual que candidatos-estudio.php).
    autoLinkEnrichAccnoFromOrthanc($rows);

    $best = null;
    foreach ($rows as $row) {
        $calc = computeStudyMatchScore($payload, $row);
        if ($best === null || $calc['score'] > $best['match_score']) {
            $row['match_score']    = $calc['score'];
            $row['match_reasons']  = $calc['reasons'];
            $row['selection_mode'] = $mode;
            $best = $row;
        }
    }

    return $best;
}

/**
 * Resuelve estudio_id por auto-vinculación (misma lógica que el bloque principal de recibir-pdf).
 * Puede invocarse dos veces en la misma petición: antes del INSERT del IR y justo después,
 * para mitigar carreras donde `estudios` se confirma en otra transacción milisegundos después
 * del primer SELECT (mismo timestamp que fecha_recepcion del IR).
 *
 * @return array{estudio_id:?int,metodo_vinculacion:?string,auto_score:?int,auto_reasons:?array}
 */
function autoLinkResolveEstudioFromCandidateSearch(
    PDO $db,
    array $autoCfg,
    array $inputForScore,
    string $accessionNumber,
    array $dicomData
): array {
    $out = [
        'estudio_id'          => null,
        'metodo_vinculacion'  => null,
        'auto_score'          => null,
        'auto_reasons'        => null,
    ];
    if (empty($autoCfg['enabled'])) {
        return $out;
    }
    // Modalidad excluida de búsqueda automática en PACS (ej. DMO, US)
    $irMod = strtoupper(trim((string)($dicomData['modality'] ?? '')));
    if ($irMod !== '' && ir_isModalidadExcluida($irMod, $db)) {
        $out['motivo_excluido'] = "Modalidad {$irMod} excluida de auto-vinculación en PACS";
        return $out;
    }
    $best = findBestStudyCandidate($db, $inputForScore);
    if (!$best) {
        return $out;
    }
    $score   = (int)($best['match_score'] ?? 0);
    $reasons = $best['match_reasons'] ?? [];
    $selMode = $best['selection_mode'] ?? '';

    $accOk = !$autoCfg['require_accno'] || (
        trim((string)$accessionNumber) !== '' &&
        trim((string)($best['accession_number'] ?? '')) !== '' &&
        strcasecmp((string)$accessionNumber, (string)$best['accession_number']) === 0
    );

    $pidFoundByStage = (strpos($selMode, 'pid') !== false);
    $pidInNorm       = ir_score_normalize_patient_id($dicomData['patient_id'] ?? '');
    $pidBestNorm     = ir_score_normalize_patient_id($best['patient_id_pacs'] ?? '');
    $pidOk           = !$autoCfg['require_patient_id']
        || $pidFoundByStage
        || ($pidInNorm !== '' && $pidBestNorm !== '' && $pidInNorm === $pidBestNorm);

    // Bloqueo explícito por discrepancia de patient_id: el estudio en PACS fue ingresado con
    // un ID de paciente incorrecto. El operador debe corregirlo en PACS antes de vincular.
    $matchReasons = $best['match_reasons'] ?? [];
    $pidDiscrepante = array_intersect(
        ['patient_id_discrepante', 'patient_id_similar', 'patient_id_prefijo'],
        $matchReasons
    ) !== [];
    if ($pidDiscrepante) {
        $out['motivo_excluido'] = 'ID paciente en PACS difiere del informe (posible error de carga manual). Requiere revisión manual.';
        return $out;
    }

    if ($score < $autoCfg['min_score'] || !$accOk || !$pidOk) {
        return $out;
    }

    $resolvedEstudioId = ($best['id'] !== null) ? (int)$best['id'] : null;
    $metodo            = null;
    $isPacsOnly        = $resolvedEstudioId === null && in_array(
        $best['source'] ?? '',
        ['pacs_only', 'pacs_fallback_pid', 'pacs_date_name'],
        true
    );
    if ($isPacsOnly) {
        $resolvedEstudioId = autoLinkResolveOrCreateEstudio($db, $best);
        if ($resolvedEstudioId) {
            $metodo = 'auto_score_pacs';
        }
    } else {
        $metodo = 'auto_score';
    }
    if (!$resolvedEstudioId) {
        return $out;
    }

    $out['estudio_id']         = $resolvedEstudioId;
    $out['metodo_vinculacion'] = $metodo;
    $out['auto_score']         = $score;
    $out['auto_reasons']      = $reasons;

    return $out;
}

function hasInformeInPacs(PDO $db, int $informeId): bool
{
    try {
        $stmt = $db->query("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'informes'
              AND COLUMN_NAME IN ('pacs_series_id','pacs_instance_id','pacs_study_id','fecha_enviado_pacs')
        ");
        $cols = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    } catch (Exception $e) {
        $cols = [];
    }
    if (!$cols) {
        return false;
    }
    $fields = implode(', ', array_map(static function ($c) { return 'i.' . $c; }, $cols));
    $q = $db->prepare('SELECT ' . $fields . ' FROM informes i WHERE i.id = ? LIMIT 1');
    $q->execute([$informeId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    foreach ($cols as $c) {
        if (!empty($row[$c])) {
            return true;
        }
    }

    return false;
}

/**
 * Copia PDF+TXT desde rutas locales (tmp o SMB montado) a uploads/informes_recibidos
 * y aplica la misma lógica de INSERT / auto-vinculación que el POST multipart.
 *
 * @param string $pdfOriginalFilename Nombre original del archivo PDF (ej: "12345678_66652.28.pdf").
 *                                    Se usa para extraer el numero_informe_pdf del sistema externo.
 * @return array{
 *   informe_recibido_id:int,
 *   accession_number:string,
 *   estudio_id:?int,
 *   informe_id:?int,
 *   estado:string,
 *   metodo_vinculacion:?string,
 *   auto_score:?int,
 *   auto_reasons:?mixed,
 *   pendiente_mensaje:?string,
 *   pdf_path:string,
 *   txt_path:string,
 *   pending_pacs_send:?int
 * }
 */
function ir_extract_numero_informe_pdf(string $filename): ?string
{
    $bn = basename($filename);
    // Formato esperado: idpaciente_nroinforme.ext  (ej: 12345678_66652.28.pdf)
    if (!preg_match('/^[^_]+_(.+)\.[^.]+$/i', $bn, $m)) {
        return null;
    }
    $sufijo = trim($m[1]);
    return $sufijo !== '' ? $sufijo : null;
}

function ir_ingest_recibido_pair_from_paths(PDO $db, string $pdfSourceAbs, string $txtSourceAbs, array $dicomData, string $pdfOriginalFilename = ''): array
{
    $accessionNumber = trim((string)($dicomData['accession_number'] ?? ''));
    if ($accessionNumber === '') {
        throw new Exception('Falta accession_number en datos DICOM');
    }

    if (!is_readable($pdfSourceAbs)) {
        throw new Exception('No se puede leer el PDF origen');
    }
    if (!is_readable($txtSourceAbs)) {
        throw new Exception('No se puede leer el TXT origen');
    }

    $pdfSize = @filesize($pdfSourceAbs);
    if ($pdfSize !== false && $pdfSize > 50 * 1024 * 1024) {
        throw new Exception('El archivo PDF es demasiado grande (máximo 50MB)');
    }

    $baseDir = realpath(__DIR__ . '/../../');
    if ($baseDir === false) {
        throw new Exception('No se pudo resolver directorio base');
    }
    $storageDir = $baseDir . '/uploads/informes_recibidos';

    if (!is_dir($storageDir)) {
        if (!mkdir($storageDir, 0775, true)) {
            throw new Exception('No se pudo crear el directorio de almacenamiento');
        }
    }

    $timestamp = date('Ymd_His');
    $safeAccession = preg_replace('/[^a-zA-Z0-9_-]/', '_', $accessionNumber);
    $pdfFilename = 'informe_' . $safeAccession . '_' . $timestamp . '.pdf';
    $txtFilename = 'datos_' . $safeAccession . '_' . $timestamp . '.txt';

    $pdfPath = $storageDir . '/' . $pdfFilename;
    $txtPath = $storageDir . '/' . $txtFilename;

    if (!@copy($pdfSourceAbs, $pdfPath)) {
        throw new Exception('Error al copiar el archivo PDF a almacenamiento');
    }
    @chmod($pdfPath, 0644);
    ir_applyPdfTitleMetadataIfConfigured($db, $pdfPath);
    if (!@copy($txtSourceAbs, $txtPath)) {
        @unlink($pdfPath);
        throw new Exception('Error al copiar el archivo TXT a almacenamiento');
    }

    $pdfRelativePath = 'uploads/informes_recibidos/' . $pdfFilename;
    $txtRelativePath = 'uploads/informes_recibidos/' . $txtFilename;

    $autoCfg = getAutoLinkConfig($db);
    $estudioId = null;
    $metodoVinculacion = null;
    $autoScore = null;
    $autoReasons = null;
    $mensajePendiente = null;
    $pendingPacsSend = null;

    $patientName = $dicomData['patient_name'] ?? null;
    $patientId = $dicomData['patient_id'] ?? null;
    $patientBirthDate = !empty($dicomData['patient_birth_date']) ? $dicomData['patient_birth_date'] : null;
    $patientSex = $dicomData['patient_sex'] ?? null;
    $modality = $dicomData['modality'] ?? null;
    $referringPhysician = $dicomData['referring_physician'] ?? null;
    $equipmentName = $dicomData['equipment_name'] ?? null;
    $procedureDate = $dicomData['scheduled_date'] ?? ($dicomData['study_date'] ?? null);
    $studyDateDicom = !empty($dicomData['study_date']) ? $dicomData['study_date'] : null;
    $procedureTime = $dicomData['scheduled_time'] ?? null;
    $procedureDescription = $dicomData['procedure_description'] ?? null;
    $reasonForStudy = $dicomData['reason_for_study'] ?? null;

    $studyDateDicomIso = null;
    if ($studyDateDicom !== null && trim((string)$studyDateDicom) !== '') {
        $studyDateDicomIso = ir_score_normalize_dicom_date_to_iso(trim((string)$studyDateDicom));
        if ($studyDateDicomIso === null && preg_match('/^\d{4}-\d{2}-\d{2}/', trim((string)$studyDateDicom))) {
            $studyDateDicomIso = substr(trim((string)$studyDateDicom), 0, 10);
        }
    }

    $inputForScore = [
        'accession_number' => $accessionNumber,
        'patient_id' => $patientId,
        'patient_name' => $patientName,
        'modality' => $modality,
        'procedure_date' => $procedureDate,
        'study_date_dicom' => $studyDateDicomIso ?? '',
    ];

    // Verificar si la modalidad está excluida de búsqueda automática en PACS
    $irModNorm = strtoupper(trim((string)($modality ?? '')));
    $modalidadExcluida = $irModNorm !== '' && ir_isModalidadExcluida($irModNorm, $db);

    if ($modalidadExcluida) {
        // No buscar candidatos; el informe quedará como pendiente_sin_pacs
        $mensajePendiente = "Modalidad {$irModNorm} excluida de búsqueda automática en PACS";
    } elseif ($autoCfg['enabled']) {
        $lk = autoLinkResolveEstudioFromCandidateSearch($db, $autoCfg, $inputForScore, $accessionNumber, $dicomData);
        $estudioId = $lk['estudio_id'];
        $metodoVinculacion = $lk['metodo_vinculacion'];
        $autoScore = $lk['auto_score'];
        $autoReasons = $lk['auto_reasons'];
    } else {
        $estudioQuery = "SELECT id FROM estudios WHERE accession_number = ? AND UPPER(TRIM(COALESCE(modality, ''))) <> 'DOC' LIMIT 1";
        $estudioStmt = $db->prepare($estudioQuery);
        $estudioStmt->execute([$accessionNumber]);
        $estudio = $estudioStmt->fetch(PDO::FETCH_ASSOC);
        if ($estudio) {
            $estudioId = (int)$estudio['id'];
            $metodoVinculacion = 'auto_accno';
        }
    }

    $estado = $modalidadExcluida ? 'pendiente_sin_pacs' : 'recibido';

    // Verificar si existe columna study_date_dicom (agregada en migración opcional)
    static $irHasStudyDateDicom = null;
    if ($irHasStudyDateDicom === null) {
        try {
            $sdColStmt = $db->query("SHOW COLUMNS FROM informes_recibidos LIKE 'study_date_dicom'");
            $irHasStudyDateDicom = $sdColStmt && $sdColStmt->rowCount() > 0;
        } catch (Throwable $e) {
            $irHasStudyDateDicom = false;
        }
    }
    // Extraer número de informe del nombre original del PDF (idpaciente_NROINFORME.pdf)
    $numeroInformePdf = null;
    if ($pdfOriginalFilename !== '') {
        $numeroInformePdf = ir_extract_numero_informe_pdf($pdfOriginalFilename);
    }

    $insertCols = 'accession_number, numero_informe_pdf, pdf_path, txt_path, estudio_id,
        patient_name, patient_id, patient_birth_date, patient_sex,
        modality, referring_physician, equipment_name,
        procedure_date, procedure_time, procedure_description, reason_for_study,
        estado, fecha_recepcion';
    $insertPlaceholders = '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()';
    $insertValues = [
        $accessionNumber, $numeroInformePdf, $pdfRelativePath, $txtRelativePath, null,
        $patientName, $patientId, $patientBirthDate, $patientSex,
        $modality, $referringPhysician, $equipmentName,
        $procedureDate, $procedureTime, $procedureDescription, $reasonForStudy,
        $estado,
    ];
    if ($irHasStudyDateDicom && $studyDateDicom !== null) {
        $insertCols .= ', study_date_dicom';
        $insertPlaceholders .= ', ?';
        $insertValues[] = $studyDateDicom;
    }
    $insertQuery = "INSERT INTO informes_recibidos ($insertCols) VALUES ($insertPlaceholders)";
    $insertStmt = $db->prepare($insertQuery);
    $insertStmt->execute($insertValues);

    $informeRecibidoId = (int)$db->lastInsertId();

    if (!$estudioId && !empty($autoCfg['enabled'])) {
        $accTrim = trim((string)$accessionNumber);
        $pidTrim = trim((string)($patientId ?? ''));
        if ($accTrim !== '' || $pidTrim !== '') {
            $lk2 = autoLinkResolveEstudioFromCandidateSearch($db, $autoCfg, $inputForScore, $accessionNumber, $dicomData);
            if (!empty($lk2['estudio_id'])) {
                error_log('[IR_AUTO_LINK] 2º intento post-INSERT (ingest paths) informes_recibidos id=' . $informeRecibidoId . ' estudio_id=' . (int)$lk2['estudio_id']);
                $estudioId = $lk2['estudio_id'];
                $metodoVinculacion = $lk2['metodo_vinculacion'];
                $autoScore = $lk2['auto_score'];
                $autoReasons = $lk2['auto_reasons'];
            }
        }
    }

    $informeId = null;
    if ($estudioId) {
        $informeId = ir_upsert_informe_desde_recibido($db, [
            'estudio_id' => $estudioId,
            'accession_number' => $accessionNumber,
            'patient_id' => $patientId,
            'patient_name' => $patientName,
            'modality' => $modality,
            'procedure_description' => $procedureDescription,
            'pdf_path' => $pdfRelativePath,
        ], resolveSystemUserId($db));

        if ($informeId && $autoCfg['on_pacs'] === 'bloquear' && hasInformeInPacs($db, (int)$informeId)) {
            $estado = 'recibido';
            $mensajePendiente = 'Nuevo envío detectado (actualización). Debe quitar primero la versión previa del PACS para aplicar la actualización.';
            try {
                $revertStmt = $db->prepare('
                    UPDATE informes_recibidos
                    SET estado = \'recibido\',
                        estudio_id = NULL,
                        fecha_vinculacion = NULL,
                        informe_id = NULL,
                        metodo_vinculacion = NULL,
                        error_message = ?
                    WHERE id = ?
                ');
                $revertStmt->execute([$mensajePendiente, $informeRecibidoId]);
            } catch (Exception $e) {
                $revertStmt = $db->prepare('
                    UPDATE informes_recibidos
                    SET estado = \'recibido\',
                        estudio_id = NULL,
                        fecha_vinculacion = NULL,
                        error_message = ?
                    WHERE id = ?
                ');
                $revertStmt->execute([$mensajePendiente, $informeRecibidoId]);
            }
            $estudioId = null;
            $informeId = null;
            $metodoVinculacion = null;
            $autoScore = null;
            $autoReasons = null;
        } elseif ($informeId) {
            $metodoFinal = $metodoVinculacion ?: 'auto_accno';
            $linkQuery = 'UPDATE informes_recibidos SET estado = \'vinculado\', estudio_id = ?, fecha_vinculacion = NOW(), informe_id = ?, metodo_vinculacion = ? WHERE id = ?';
            try {
                $linkStmt = $db->prepare($linkQuery);
                $linkStmt->execute([$estudioId, $informeId, $metodoFinal, $informeRecibidoId]);
            } catch (Exception $e) {
                $fallbackStmt = $db->prepare('UPDATE informes_recibidos SET estado = \'vinculado\', estudio_id = ?, fecha_vinculacion = NOW() WHERE id = ?');
                $fallbackStmt->execute([$estudioId, $informeRecibidoId]);
            }
            $estado = 'vinculado';
            if ($autoScore !== null || $autoReasons !== null) {
                try {
                    $scoreStmt = $db->prepare('
                        UPDATE informes_recibidos
                        SET matching_score = COALESCE(?, matching_score),
                            matching_reasons = COALESCE(?, matching_reasons)
                        WHERE id = ?
                    ');
                    $reasonsText = is_array($autoReasons) ? json_encode($autoReasons, JSON_UNESCAPED_UNICODE) : null;
                    $scoreStmt->execute([$autoScore, $reasonsText, $informeRecibidoId]);
                } catch (Exception $e) {
                    // columnas opcionales
                }
            }
            $pendingPacsSend = $informeId;
            if ($pendingPacsSend) {
                ir_markAutoPacsPending($db, $informeRecibidoId);
            }
        } else {
            $pendingPacsSend = null;
            $estado = 'recibido';
            $estudioId = null;
            try {
                $revertNoInformeStmt = $db->prepare('
                    UPDATE informes_recibidos
                    SET estado = \'recibido\',
                        estudio_id = NULL,
                        fecha_vinculacion = NULL
                    WHERE id = ?
                ');
                $revertNoInformeStmt->execute([$informeRecibidoId]);
            } catch (Exception $e) {
                // mantener trazabilidad
            }
        }
    }

    return [
        'informe_recibido_id' => $informeRecibidoId,
        'accession_number' => $accessionNumber,
        'estudio_id' => $estudioId,
        'informe_id' => $informeId,
        'estado' => $estado,
        'metodo_vinculacion' => $metodoVinculacion,
        'auto_score' => $autoScore,
        'auto_reasons' => $autoReasons,
        'pendiente_mensaje' => $mensajePendiente,
        'pdf_path' => $pdfRelativePath,
        'txt_path' => $txtRelativePath,
        'pending_pacs_send' => $pendingPacsSend,
    ];
}

if (!$irRecibirPdfFunctionsOnly) {
$requestId = uniqid('irx_', true);
$db = null;
$intentoId = null;
$accessionNumber = null;

try {
    $db = getDBConnection();

    // Registrar intento (iniciado) para trazabilidad integral
    $intentoId = registrarIntentoRecepcion($db, [
        'request_id' => $requestId,
        'estado' => 'iniciado',
        'metodo_http' => $_SERVER['REQUEST_METHOD'] ?? null,
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? null),
        'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'pdf_filename' => $_FILES['pdf']['name'] ?? null,
        'pdf_mime' => $_FILES['pdf']['type'] ?? null,
        'pdf_size_bytes' => isset($_FILES['pdf']['size']) ? (int)$_FILES['pdf']['size'] : null,
        'txt_filename' => $_FILES['txt']['name'] ?? null,
        'txt_mime' => $_FILES['txt']['type'] ?? null,
        'txt_size_bytes' => isset($_FILES['txt']['size']) ? (int)$_FILES['txt']['size'] : null,
    ]);

    // Validar que se recibieron ambos archivos
    if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No se recibió archivo PDF válido');
    }
    
    if (!isset($_FILES['txt']) || $_FILES['txt']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No se recibió archivo TXT válido');
    }
    
    $pdfFile = $_FILES['pdf'];
    $txtFile = $_FILES['txt'];
    
    // Validar tipo de archivo PDF
    if ($pdfFile['type'] !== 'application/pdf') {
        throw new Exception('El archivo PDF no es válido (tipo: ' . $pdfFile['type'] . ')');
    }
    
    // Validar tamaño (máximo 50MB)
    $maxSize = 50 * 1024 * 1024; // 50MB
    if ($pdfFile['size'] > $maxSize) {
        throw new Exception('El archivo PDF es demasiado grande (máximo 50MB)');
    }
    
    // Parsear archivo TXT (DicomData)
    $txtContent = file_get_contents($txtFile['tmp_name']);
    if ($txtContent === false) {
        throw new Exception('No se pudo leer el archivo TXT');
    }

    $dicomData = ir_parse_dicom_txt_content($txtContent);
    $accessionNumber = $dicomData['accession_number'];

    $ingest = ir_ingest_recibido_pair_from_paths($db, $pdfFile['tmp_name'], $txtFile['tmp_name'], $dicomData, $pdfFile['name'] ?? '');

    $informeRecibidoId = $ingest['informe_recibido_id'];
    $estudioId = $ingest['estudio_id'];
    $informeId = $ingest['informe_id'];
    $estado = $ingest['estado'];
    $metodoVinculacion = $ingest['metodo_vinculacion'];
    $autoScore = $ingest['auto_score'];
    $autoReasons = $ingest['auto_reasons'];
    $mensajePendiente = $ingest['pendiente_mensaje'];
    $pdfRelativePath = $ingest['pdf_path'];
    $txtRelativePath = $ingest['txt_path'];
    $pendingPacsSend = $ingest['pending_pacs_send'];

    // Marcar intento como exitoso
    actualizarIntentoRecepcion($db, $intentoId, [
        'estado' => 'exitoso',
        'accession_number' => $accessionNumber,
        'informe_recibido_id' => (int)$informeRecibidoId,
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Informe recibido exitosamente',
        'data' => [
            'id'               => $informeRecibidoId,
            'accession_number' => $accessionNumber,
            'estudio_id'       => $estudioId,
            'informe_id'       => $informeId,
            'estado'           => $estado,
            'metodo_vinculacion' => $metodoVinculacion,
            'matching_score'   => $autoScore,
            'matching_reasons' => $autoReasons,
            'pendiente_mensaje' => $mensajePendiente,
            'pdf_path'         => $pdfRelativePath,
            'txt_path'         => $txtRelativePath
        ]
    ]);

    // Cerrar la conexión con el cliente antes del envío a PACS para evitar
    // "upstream sent too big header" por exceso de error_log() en el buffer FastCGI.
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    if (!empty($pendingPacsSend)) {
        ir_enviarPacsSiCorresponde($db, (int)$pendingPacsSend, (int)$informeRecibidoId);
    }

} catch (Exception $e) {
    // Marcar intento como error; si no existe intento aún, intentar crearlo en estado error
    if ($intentoId) {
        actualizarIntentoRecepcion($db, $intentoId, [
            'estado' => 'error',
            'error_message' => $e->getMessage(),
            'accession_number' => $accessionNumber,
        ]);
    } elseif ($db) {
        registrarIntentoRecepcion($db, [
            'request_id' => $requestId,
            'estado' => 'error',
            'error_message' => $e->getMessage(),
            'accession_number' => $accessionNumber,
            'metodo_http' => $_SERVER['REQUEST_METHOD'] ?? null,
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? null),
            'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'pdf_filename' => $_FILES['pdf']['name'] ?? null,
            'pdf_mime' => $_FILES['pdf']['type'] ?? null,
            'pdf_size_bytes' => isset($_FILES['pdf']['size']) ? (int)$_FILES['pdf']['size'] : null,
            'txt_filename' => $_FILES['txt']['name'] ?? null,
            'txt_mime' => $_FILES['txt']['type'] ?? null,
            'txt_size_bytes' => isset($_FILES['txt']['size']) ? (int)$_FILES['txt']['size'] : null,
        ]);
    }

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'request_id' => $requestId
    ]);
}

} // !$irRecibirPdfFunctionsOnly
?>
