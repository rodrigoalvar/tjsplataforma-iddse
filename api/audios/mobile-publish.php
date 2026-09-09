<?php
/**
 * API Endpoint: mobile-publish.php
 * Permite que la grabadora móvil cambie el estado de sus audios usando
 * únicamente el session_id del QR (sin token de usuario).
 *
 * Acciones:
 *   publish  → en_papelera → listo_workspace  (el audio aparece en lista de grabadora workspace)
 *   delete   → cualquier estado → eliminado    (oculta de workspace, visible solo en papelera)
 *
 * Body JSON:
 *   {
 *     "session_id": "abc123",          // token QR de la sesión móvil
 *     "audio_id":   42,                // ID en audios_informe (único) — para publish/delete
 *     "audio_ids":  [42, 43],          // alternativamente, array de IDs (para publish masivo)
 *     "action":     "publish"|"delete"
 *   }
 */

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
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

require_once '../../config/database.php';

try {
    // Leer body JSON
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $sessionId = $input['session_id'] ?? null;
    $action    = $input['action']     ?? 'publish';

    // Normalizar audio_ids: acepta "audio_id" (int) o "audio_ids" (array)
    $audioIds = [];
    if (!empty($input['audio_ids']) && is_array($input['audio_ids'])) {
        $audioIds = array_map('intval', $input['audio_ids']);
    } elseif (!empty($input['audio_id'])) {
        $audioIds = [(int)$input['audio_id']];
    }

    if (!$sessionId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'session_id es requerido']);
        exit();
    }

    if (empty($audioIds)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'audio_id o audio_ids es requerido']);
        exit();
    }

    if (!in_array($action, ['publish', 'delete'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'action debe ser publish o delete']);
        exit();
    }

    $db = getDBConnection();

    // ── Validar que el session_id existe en mobile_sessions ──
    // No requerimos que esté activa ni vigente: el médico puede grabar y luego
    // presionar "Enviar" aunque la sesión QR haya expirado. La seguridad la
    // garantiza el JOIN con mobile_session_id en audios_informe (más abajo).
    $sessionStmt = $db->prepare(
        "SELECT id, session_id, study_id, workspace_id, status, expires_at, created_by
         FROM mobile_sessions
         WHERE session_id = ?
         LIMIT 1"
    );
    $sessionStmt->execute([$sessionId]);
    $session = $sessionStmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        // Si la sesión no existe en la tabla, intentar igual buscando audios
        // que tengan este session_id (compatibilidad con sesiones antiguas no registradas).
        $session = [
            'id'          => null,
            'session_id'  => $sessionId,
            'study_id'    => null,
            'workspace_id'=> null,
            'status'      => 'unknown',
            'created_by'  => null
        ];
    }

    // Determinar nuevo estado según la acción
    $nuevoEstado = ($action === 'delete') ? 'eliminado' : 'listo_workspace';
    $now = date('Y-m-d H:i:s');

    // ── Procesar cada audio ──
    $updated  = 0;
    $skipped  = 0;
    $errors   = [];

    // Estados desde los que se permite cada acción:
    $estadosPermitidosPublish = ['en_papelera', null];   // solo audios en papelera (resguardos)
    $estadosPermitidosDelete  = ['en_papelera', 'listo_workspace', null]; // se puede eliminar desde cualquiera

    foreach ($audioIds as $audioId) {
        try {
            // Obtener audio y verificar que pertenece a esta sesión móvil
            $audioStmt = $db->prepare(
                "SELECT id, estado, mobile_session_id, usuario_id
                 FROM audios_informe
                 WHERE id = ?
                   AND mobile_session_id = ?
                   AND activo = 1
                 LIMIT 1"
            );
            $audioStmt->execute([$audioId, $sessionId]);
            $audio = $audioStmt->fetch(PDO::FETCH_ASSOC);

            if (!$audio) {
                $errors[] = "Audio ID $audioId no encontrado o no pertenece a esta sesión";
                $skipped++;
                continue;
            }

            $estadoActual = $audio['estado'];

            // Validar que la transición es permitida
            if ($action === 'publish') {
                if (!in_array($estadoActual, $estadosPermitidosPublish)) {
                    // Si ya está en listo_workspace o estado posterior, saltar sin error
                    if (in_array($estadoActual, ['listo_workspace', 'guardado_informe', 'enviado_ftp', 'enviado_transcripcion'])) {
                        $skipped++;
                        continue;
                    }
                    $errors[] = "Audio ID $audioId en estado '$estadoActual' no puede ser publicado";
                    $skipped++;
                    continue;
                }
            } elseif ($action === 'delete') {
                if (!in_array($estadoActual, $estadosPermitidosDelete)) {
                    // Si ya está eliminado, saltar sin error
                    if ($estadoActual === 'eliminado') {
                        $skipped++;
                        continue;
                    }
                    $errors[] = "Audio ID $audioId en estado '$estadoActual' no puede ser eliminado desde el móvil";
                    $skipped++;
                    continue;
                }
            }

            // Actualizar estado
            $updateStmt = $db->prepare("UPDATE audios_informe SET estado = ? WHERE id = ?");
            $updateStmt->execute([$nuevoEstado, $audioId]);

            // Actualizar campo activo si se elimina
            if ($action === 'delete') {
                try {
                    $db->prepare("UPDATE audios_informe SET activo = 0, fecha_eliminacion = ? WHERE id = ? AND fecha_eliminacion IS NULL")
                       ->execute([$now, $audioId]);
                } catch (PDOException $e) {
                    // columna puede no existir en versión antigua
                }
            }

            // Registrar en log de estados
            try {
                $logStmt = $db->prepare(
                    "INSERT INTO audios_estado_log
                       (audio_id, estado_anterior, estado_nuevo, accion, usuario_id, estudio_id, metadata)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $logStmt->execute([
                    $audioId,
                    $estadoActual ?: null,
                    $nuevoEstado,
                    'mobile_' . $action,
                    $session['created_by'] ?: null,  // puede ser null si la sesión expiró o no se encontró
                    $session['study_id'] ?? null,
                    json_encode([
                        'session_id'     => $sessionId,
                        'session_status' => $session['status'] ?? 'unknown',
                        'via'            => 'mobile-publish.php'
                    ])
                ]);
            } catch (Exception $logEx) {
                // No fallar si el log falla
                error_log('mobile-publish.php: Error en log: ' . $logEx->getMessage());
            }

            $updated++;

        } catch (Exception $e) {
            $errors[] = "Error procesando audio ID $audioId: " . $e->getMessage();
            error_log("mobile-publish.php: Error en audio $audioId: " . $e->getMessage());
        }
    }

    // Respuesta
    echo json_encode([
        'success' => true,
        'message' => $action === 'publish'
            ? "$updated audio(s) enviado(s) al workspace"
            : "$updated audio(s) eliminado(s)",
        'data' => [
            'action'       => $action,
            'nuevo_estado' => $nuevoEstado,
            'updated'      => $updated,
            'skipped'      => $skipped,
            'errors'       => $errors
        ]
    ]);

} catch (Exception $e) {
    error_log('mobile-publish.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno: ' . $e->getMessage()
    ]);
}
?>
