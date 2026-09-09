<?php
/**
 * Vinculación manual de informe recibido a un estudio.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit();
}

require_once '../../../classes/User.php';
require_once '../../../config/database.php';
require_once __DIR__ . '/../../OrthancClient.php';
require_once __DIR__ . '/informe_recibido_version_helper.php';
require_once __DIR__ . '/informe_recibido_upsert_informe.php';
require_once __DIR__ . '/informe_recibido_auto_pacs.php';

function resolveSystemUserId(PDO $db): int {
    $stmt = $db->query("SELECT id FROM usuarios ORDER BY id ASC LIMIT 1");
    $userId = (int)$stmt->fetchColumn();
    if ($userId <= 0) {
        throw new Exception('No se encontró usuario para registrar informe externo');
    }
    return $userId;
}

function resolveSessionUserId(User $user): ?int {
    $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
        $sessionToken = substr($sessionToken, 7);
    }
    if (!$sessionToken && !empty($_COOKIE['session_token'])) {
        $sessionToken = $_COOKIE['session_token'];
    }
    if (!$sessionToken) {
        return null;
    }
    $userData = $user->validateSession($sessionToken);
    return $userData ? (int)$userData['id'] : null;
}

function normalizeIsoDate(?string $value): ?string {
    $value = trim((string)($value ?? ''));
    if ($value === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }
    $digits = preg_replace('/\D/', '', $value);
    if (strlen($digits) >= 8) {
        $digits = substr($digits, 0, 8);
        return substr($digits, 0, 4) . '-' . substr($digits, 4, 2) . '-' . substr($digits, 6, 2);
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d', $ts);
}

/**
 * Orthanc y candidatos pueden traer varias modalidades ("CT, MR", "CT\\MR");
 * estudios.modality e informes.modality son VARCHAR(10).
 */
function normalizeModalityForDb(?string $value): string
{
    $raw = strtoupper(trim((string)($value ?? '')));
    if ($raw === '') {
        return 'DOC';
    }
    $parts = preg_split('/[\s,\\\\\/;|]+/', $raw);
    $first = trim((string)($parts[0] ?? ''));
    if ($first === '') {
        return 'DOC';
    }
    return substr($first, 0, 10);
}

function truncateDbString(?string $value, int $maxLen): ?string
{
    $s = trim((string)($value ?? ''));
    if ($s === '') {
        return null;
    }
    if (strlen($s) <= $maxLen) {
        return $s;
    }
    return substr($s, 0, $maxLen);
}

function resolveOrCreateStudyId(PDO $db, int $estudioId, array $input, array $recibido): int {
    if ($estudioId > 0) {
        $studyStmt = $db->prepare("SELECT id FROM estudios WHERE id = ? LIMIT 1");
        $studyStmt->execute([$estudioId]);
        if (!$studyStmt->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception('El estudio indicado no existe');
        }
        return $estudioId;
    }

    $orthancStudyId = trim((string)($input['orthanc_study_id'] ?? ''));
    $studyInstanceUid = trim((string)($input['study_instance_uid'] ?? ''));
    if ($orthancStudyId === '' && $studyInstanceUid === '') {
        throw new Exception('Parámetros requeridos: estudio_id o (orthanc_study_id / study_instance_uid)');
    }

    // Busca también el caso antiguo: orthanc_study_id almacenado como Study UID (1.2...).
    $findStmt = $db->prepare("
        SELECT id
        FROM estudios
        WHERE (orthanc_study_id = ? AND ? <> '')
           OR (study_instance_uid = ? AND ? <> '')
           OR (orthanc_study_id = ? AND ? <> '')
        ORDER BY id ASC
        LIMIT 1
    ");
    $findStmt->execute([
        $orthancStudyId, $orthancStudyId,
        $studyInstanceUid, $studyInstanceUid,
        $studyInstanceUid, $studyInstanceUid,
    ]);
    $existingId = (int)$findStmt->fetchColumn();
    if ($existingId > 0) {
        return $existingId;
    }

    $patientId = truncateDbString(trim((string)($input['patient_id'] ?? ($recibido['patient_id'] ?? ''))), 100);
    $patientName = truncateDbString(trim((string)($input['patient_name'] ?? ($recibido['patient_name'] ?? ''))), 200);
    $modality = normalizeModalityForDb((string)($input['modality'] ?? ($recibido['modality'] ?? '')));
    $studyDescription = trim((string)($input['study_description'] ?? ($recibido['procedure_description'] ?? '')));
    // Solo se guarda el ACCNO real del estudio en PACS (viene de candidatos-estudio.php → Orthanc).
    // No se usa el ACCNO del informe recibido como fallback: ese dato pertenece al informe,
    // no al estudio DICOM. Si PACS no tiene ACCNO aún, queda NULL hasta que la worklist lo asigne.
    $accessionNumber = truncateDbString(trim((string)($input['accession_number'] ?? '')), 100);

    $pacsStudyDateIso = null;
    try {
        $oc = new OrthancClient();
        if ($orthancStudyId === '' && $studyInstanceUid !== '') {
            $resolvedOid = $oc->findOrthancStudyIdByStudyInstanceUid($studyInstanceUid);
            if ($resolvedOid !== null && $resolvedOid !== '') {
                $orthancStudyId = $resolvedOid;
            }
        }
        // La columna orthanc_study_id a veces guarda el Study UID (1.2...) en vez del ID interno REST (UUID).
        if ($orthancStudyId !== '' && preg_match('/^1\\.2\\.\\d/', $orthancStudyId)) {
            $normOid = $oc->findOrthancStudyIdByStudyInstanceUid($orthancStudyId);
            if ($normOid !== null && $normOid !== '') {
                $orthancStudyId = $normOid;
            }
        }
        if ($orthancStudyId !== '') {
            $pacsStudyDateIso = $oc->getStudyDateIsoFromOrthanc($orthancStudyId);
        }
    } catch (Exception $e) {
        $pacsStudyDateIso = null;
    }
    $studyDate = normalizeIsoDate($pacsStudyDateIso);
    if ($studyDate === null || $studyDate === '') {
        $studyDate = normalizeIsoDate((string)($input['study_date'] ?? ($recibido['procedure_date'] ?? '')));
    }

    $createStmt = $db->prepare("
        INSERT INTO estudios (
            orthanc_study_id, patient_id_pacs, patient_name_pacs, modality, study_description,
            study_date, study_instance_uid, accession_number, status, fecha_creacion
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'COMPLETADO', NOW())
    ");
    $createStmt->execute([
        $orthancStudyId !== '' ? $orthancStudyId : null,
        $patientId,
        $patientName,
        $modality,
        $studyDescription !== '' ? $studyDescription : null,
        $studyDate,
        $studyInstanceUid !== '' ? $studyInstanceUid : null,
        $accessionNumber,
    ]);

    $newId = (int)$db->lastInsertId();
    try {
        require_once __DIR__ . '/../../estudios/sla_helper.php';
        sla_mark_local_arrived($db, [
            'estudios_id' => $newId,
            'orthanc_study_id' => $orthancStudyId,
            'study_instance_uid' => $studyInstanceUid,
            'modality' => $modality,
            'patient_id' => $patientId,
            'patient_name' => $patientName,
        ], null, 'system');
    } catch (Throwable $e) {
        error_log('[VINCULAR] sla arrived: ' . $e->getMessage());
    }
    return $newId;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new Exception('JSON inválido');
    }

    $recibidoId = (int)($input['recibido_id'] ?? 0);
    $estudioId = (int)($input['estudio_id'] ?? 0);
    $matchScore = isset($input['match_score']) ? (int)$input['match_score'] : null;
    $matchReasons = $input['match_reasons'] ?? null;
    if (is_array($matchReasons)) {
        $matchReasons = json_encode(array_values($matchReasons), JSON_UNESCAPED_UNICODE);
    } elseif ($matchReasons !== null) {
        $matchReasons = (string)$matchReasons;
    }
    $isSuggested = !empty($input['is_suggested']);
    $metodoVinculacion = $isSuggested ? 'manual_sugerido' : 'manual';
    if ($recibidoId <= 0) {
        throw new Exception('Parámetro requerido: recibido_id');
    }

    $db = getDBConnection();
    $user = new User();
    $sessionUserId = resolveSessionUserId($user);

    $recibidoStmt = $db->prepare("SELECT * FROM informes_recibidos WHERE id = ? LIMIT 1");
    $recibidoStmt->execute([$recibidoId]);
    $recibido = $recibidoStmt->fetch(PDO::FETCH_ASSOC);
    if (!$recibido) {
        throw new Exception('Informe recibido no encontrado');
    }

    $estudioId = resolveOrCreateStudyId($db, $estudioId, $input, $recibido);

    $historialUid = ($sessionUserId !== null && $sessionUserId > 0) ? $sessionUserId : null;
    $informeId = ir_upsert_informe_desde_recibido($db, [
        'estudio_id' => $estudioId,
        'accession_number' => $recibido['accession_number'] ?? null,
        'patient_id' => $recibido['patient_id'] ?? null,
        'patient_name' => $recibido['patient_name'] ?? null,
        'modality' => $recibido['modality'] ?? null,
        'procedure_description' => $recibido['procedure_description'] ?? null,
        'pdf_path' => $recibido['pdf_path'] ?? null,
        'orthanc_study_id' => $input['orthanc_study_id'] ?? null,
        'study_instance_uid' => $input['study_instance_uid'] ?? null,
        'orthanc_internal_id' => $input['orthanc_internal_id'] ?? null,
        'historial_usuario_id' => $historialUid,
        'historial_motivo' => 'Actualización PDF por vinculación manual desde informes recibidos',
    ], resolveSystemUserId($db));
    if ($informeId === null) {
        throw new Exception('El estudio vinculado no tiene identificador Orthanc ni Study UID ni id local');
    }

    try {
        $updateQuery = "
            UPDATE informes_recibidos
            SET estudio_id = ?, estado = 'vinculado', fecha_vinculacion = NOW(), metodo_vinculacion = ?
            WHERE id = ?
        ";
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->execute([$estudioId, $metodoVinculacion, $recibidoId]);
    } catch (Exception $e) {
        $updateStmt = $db->prepare("
            UPDATE informes_recibidos
            SET estudio_id = ?, estado = 'vinculado', fecha_vinculacion = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([$estudioId, $recibidoId]);
    }

    // Guardar datos de scoring si existen columnas opcionales
    if ($matchScore !== null || $matchReasons !== null) {
        try {
            $scoreStmt = $db->prepare("
                UPDATE informes_recibidos
                SET matching_score = COALESCE(?, matching_score),
                    matching_reasons = COALESCE(?, matching_reasons)
                WHERE id = ?
            ");
            $scoreStmt->execute([$matchScore, $matchReasons, $recibidoId]);
        } catch (Exception $e) {
            // Columnas opcionales; no bloquear vinculación por esto
        }
    }

    if ($informeId) {
        try {
            $linkStmt = $db->prepare("UPDATE informes_recibidos SET informe_id = ? WHERE id = ?");
            $linkStmt->execute([$informeId, $recibidoId]);
        } catch (Exception $e) {
            // Columna informe_id puede no existir aún
        }
    }

    if ($informeId) {
        ir_markAutoPacsPending($db, (int)$recibidoId);
    }

    // Guardar trazabilidad simple cuando existe columna vinculado_por_usuario_id
    if ($sessionUserId) {
        try {
            $auditStmt = $db->prepare("UPDATE informes_recibidos SET vinculado_por_usuario_id = ? WHERE id = ?");
            $auditStmt->execute([$sessionUserId, $recibidoId]);
        } catch (Exception $e) {
            // Columna opcional
        }
    }

    // Enviar la respuesta JSON al cliente ANTES del envío a PACS.
    // El proceso PACS genera muchas líneas de error_log() que saturan el buffer FastCGI
    // de nginx y provocan "upstream sent too big header". Con fastcgi_finish_request()
    // la conexión HTTP se cierra para el cliente y el PACS send corre sin afectarla.
    echo json_encode([
        'success' => true,
        'message' => 'Informe recibido vinculado correctamente',
        'data' => [
            'recibido_id' => $recibidoId,
            'estudio_id' => $estudioId,
            'informe_id' => $informeId,
            'vinculado_por_usuario_id' => $sessionUserId,
            'metodo_vinculacion' => $metodoVinculacion,
            'match_score' => $matchScore
        ]
    ], JSON_UNESCAPED_UNICODE);

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    ir_enviarPacsSiCorresponde($db, $informeId !== null ? (int)$informeId : null, (int)$recibidoId);
} catch (Throwable $e) {
    http_response_code(400);
    $msg = $e->getMessage();
    if ($e instanceof PDOException && $msg === '') {
        $msg = 'Error de base de datos al vincular';
    }
    echo json_encode([
        'success' => false,
        'error' => $msg
    ], JSON_UNESCAPED_UNICODE);
}

