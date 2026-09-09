<?php
/**
 * api/audios/enqueue.php
 * Agrega audios existentes (ya en audios_informe) a la cola de transcripción AI.
 * Pensado especialmente para audios subidos desde la grabadora móvil, una vez
 * que el informe ha sido finalizado en el workspace.
 *
 * POST JSON: { audio_ids: int[] }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/User.php';

// ---- Helpers ----

/**
 * Obtiene el usuario autenticado a partir del token de sesión (Authorization: Bearer ...)
 */
function getAuthUserForEnqueue(PDO $db): ?array {
    $token = null;

    // Header Authorization
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        if (stripos($authHeader, 'Bearer ') === 0) {
            $token = substr($authHeader, 7);
        }
    }

    if (!$token) {
        return null;
    }

    try {
        $user = new User();
        $userData = $user->validateSession($token);
        return $userData ?: null;
    } catch (Exception $e) {
        error_log("enqueue.php: Error validando sesión: " . $e->getMessage());
        return null;
    }
}

// ---- Validar método ----

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit();
}

// ---- Leer input ----

$input = json_decode(file_get_contents('php://input'), true);
$audioIds = $input['audio_ids'] ?? [];

if (empty($audioIds) || !is_array($audioIds)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'audio_ids es requerido']);
    exit();
}

try {
    $db = getDBConnection();

    // Validar usuario
    $user = getAuthUserForEnqueue($db);
    if (!$user || !isset($user['id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No autorizado']);
        exit();
    }
    $currentUserId = (int)$user['id'];

    // Verificar configuración global de AI (ai_config.auto_transcribe_enabled)
    $configCheck = $db->query("SHOW TABLES LIKE 'ai_config'");
    if ($configCheck->rowCount() === 0) {
        // Si no existe la tabla, simplemente no hacemos nada
        echo json_encode([
            'success'   => true,
            'enqueued'  => 0,
            'skipped'   => count($audioIds),
            'reason'    => 'Tabla ai_config no existe; auto-transcripción deshabilitada'
        ]);
        exit();
    }

    $configStmt = $db->prepare("SELECT auto_transcribe_enabled FROM ai_config WHERE id = 1");
    $configStmt->execute();
    $config = $configStmt->fetch(PDO::FETCH_ASSOC);

    if (!$config || empty($config['auto_transcribe_enabled'])) {
        // Auto-transcripción global deshabilitada
        echo json_encode([
            'success'   => true,
            'enqueued'  => 0,
            'skipped'   => count($audioIds),
            'reason'    => 'auto_transcribe_enabled está desactivado'
        ]);
        exit();
    }

    require_once __DIR__ . '/../transcription_health.php';
    try {
        $txHealth = transcriptionAssertReadyToEnqueue($db);
    } catch (Exception $eHealth) {
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'error' => $eHealth->getMessage(),
            'enqueued' => 0,
            'skipped' => count($audioIds),
            'transcription_health' => [
                'status' => 'down',
                'ready' => false,
                'allow_enqueue' => false,
                'message' => $eHealth->getMessage(),
            ],
        ]);
        exit();
    }

    // Verificar que existan tablas de cola y transcripciones
    $queueCheck = $db->query("SHOW TABLES LIKE 'ai_transcription_queue'");
    if ($queueCheck->rowCount() === 0) {
        echo json_encode([
            'success'   => false,
            'error'     => 'Tabla ai_transcription_queue no existe',
            'enqueued'  => 0
        ]);
        exit();
    }

    $transCheck = $db->query("SHOW TABLES LIKE 'ai_transcriptions'");
    if ($transCheck->rowCount() === 0) {
        // Podemos continuar sin esta tabla, pero la lógica de deduplicación sería incompleta
        // Aun así, seguimos para no romper el flujo.
        error_log("enqueue.php: Tabla ai_transcriptions NO existe; se continuará sin esta verificación");
    }

    // Normalizar y sanear IDs
    $ids = array_filter(array_map('intval', $audioIds));
    if (empty($ids)) {
        echo json_encode(['success' => true, 'enqueued' => 0]);
        exit();
    }

    // Obtener datos necesarios de audios_informe + informes
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $audioQuery = "
        SELECT 
            a.id AS audio_id,
            a.informe_id,
            i.estudio_id AS orthanc_study_id
        FROM audios_informe a
        LEFT JOIN informes i ON a.informe_id = i.id
        WHERE a.id IN ($placeholders)
    ";
    $audioStmt = $db->prepare($audioQuery);
    $audioStmt->execute($ids);
    $audios = $audioStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$audios) {
        echo json_encode([
            'success'  => false,
            'error'    => 'No se encontraron audios para los IDs proporcionados',
            'enqueued' => 0
        ]);
        exit();
    }

    $enqueued = 0;
    $skipped  = 0;

    foreach ($audios as $row) {
        $audioId        = (int)$row['audio_id'];
        $orthancStudyId = $row['orthanc_study_id'] ?? null;
        $studyId        = null;

        // Resolver study_id local desde estudios, igual que en upload.php
        if ($orthancStudyId) {
            try {
                $studyStmt = $db->prepare("SELECT id FROM estudios WHERE orthanc_study_id = ? LIMIT 1");
                $studyStmt->execute([$orthancStudyId]);
                $study = $studyStmt->fetch(PDO::FETCH_ASSOC);
                if ($study && isset($study['id'])) {
                    $studyId = (int)$study['id'];
                }
            } catch (Exception $e) {
                error_log("enqueue.php: Error buscando estudio local para orthanc_study_id=$orthancStudyId: " . $e->getMessage());
            }
        }

        // Verificar si ya está en cola o ya tiene transcripción completada
        $existingInQueue = null;
        $existingTrans   = null;

        // Cola
        $checkQueueStmt = $db->prepare("
            SELECT id FROM ai_transcription_queue 
            WHERE audio_id = ? AND status IN ('pending', 'processing')
            LIMIT 1
        ");
        $checkQueueStmt->execute([$audioId]);
        $existingInQueue = $checkQueueStmt->fetch(PDO::FETCH_ASSOC);

        // Transcripciones (si existe la tabla)
        $transTableExists = ($transCheck->rowCount() > 0);
        if ($transTableExists) {
            $checkTransStmt = $db->prepare("
                SELECT id FROM ai_transcriptions
                WHERE audio_id = ? AND status = 'completed'
                LIMIT 1
            ");
            $checkTransStmt->execute([$audioId]);
            $existingTrans = $checkTransStmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($existingInQueue || $existingTrans) {
            $skipped++;
            continue;
        }

        // Insertar en la cola
        $insertQueueStmt = $db->prepare("
            INSERT INTO ai_transcription_queue 
            (audio_id, study_id, orthanc_study_id, status, priority, created_by, created_at)
            VALUES (?, ?, ?, 'pending', 0, ?, NOW())
        ");
        $insertQueueStmt->execute([
            $audioId,
            $studyId,
            $orthancStudyId,
            $currentUserId
        ]);

        $queueId = $db->lastInsertId();
        $enqueued++;

        error_log("enqueue.php: Audio ID $audioId agregado a la cola de transcripción (queue_id=$queueId, study_id=" . ($studyId ?? 'NULL') . ", orthanc_study_id=" . ($orthancStudyId ?? 'NULL') . ")");
    }

    echo json_encode([
        'success'  => true,
        'enqueued' => $enqueued,
        'skipped'  => $skipped,
        'transcription_health' => [
            'status' => $txHealth['status'] ?? null,
            'degraded' => !empty($txHealth['degraded']),
            'message' => $txHealth['message'] ?? null,
        ],
        'warning' => !empty($txHealth['degraded'])
            ? ($txHealth['message'] ?? 'Servidor de transcripción degradado')
            : null,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Error interno: ' . $e->getMessage()
    ]);
}

