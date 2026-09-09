<?php
/**
 * API: Copiar plantilla a cuentas Médico Informante (asignar dueños = copiar).
 *
 * GET  ?action=list-medicos  → lista médicos informantes activos
 * POST JSON:
 * {
 *   "source_template_id": "rx_torax",
 *   "user_ids": [1,2,3],
 *   "skip_if_exists": true
 * }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/plantillas_common.php';

try {
    $db = getDBConnection();
    plantillasEnsureTraceColumns($db);

    $auth = plantillasAuthFromRequest();
    if (!plantillasCanCopyToOwners($auth)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'No tiene permiso para copiar plantillas a médicos informantes. Se requiere gestión de plantillas (típicamente Transcriptor con ver todas).',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $actorId = (int) $auth['user']['id'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? 'list-medicos';
        if ($action !== 'list-medicos') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Acción no válida']);
            exit;
        }

        $stmt = $db->query("
            SELECT id, nombre, apellido, email, rol
            FROM usuarios
            WHERE activo = 1 AND rol = 'medico_informante'
            ORDER BY apellido ASC, nombre ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode([
            'success' => true,
            'data' => $rows,
            'count' => count($rows),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Método no permitido']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new Exception('JSON inválido');
    }

    $sourceTemplateId = trim((string) ($input['source_template_id'] ?? ''));
    $userIds = $input['user_ids'] ?? [];
    $skipIfExists = !isset($input['skip_if_exists']) || (bool) $input['skip_if_exists'];

    if ($sourceTemplateId === '' || !is_array($userIds) || empty($userIds)) {
        throw new Exception('source_template_id y user_ids son requeridos');
    }

    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (empty($userIds)) {
        throw new Exception('user_ids inválido');
    }

    $srcStmt = $db->prepare('SELECT * FROM plantillas WHERE template_id = ? AND activo = 1 LIMIT 1');
    $srcStmt->execute([$sourceTemplateId]);
    $source = $srcStmt->fetch(PDO::FETCH_ASSOC);
    if (!$source) {
        http_response_code(404);
        throw new Exception('Plantilla origen no encontrada');
    }

    // Validar que destinos sean médicos informantes activos
    $ph = implode(',', array_fill(0, count($userIds), '?'));
    $medStmt = $db->prepare("
        SELECT id, nombre, apellido
        FROM usuarios
        WHERE activo = 1 AND rol = 'medico_informante' AND id IN ($ph)
    ");
    $medStmt->execute($userIds);
    $medicos = $medStmt->fetchAll(PDO::FETCH_ASSOC);
    $validIds = array_map(static function ($r) {
        return (int) $r['id'];
    }, $medicos);

    $copied = 0;
    $skipped = 0;
    $details = [];

    $insert = $db->prepare('
        INSERT INTO plantillas
            (template_id, nombre, contenido_html, usuario_id, creado_por, copiado_de, activo)
        VALUES
            (?, ?, ?, ?, ?, ?, 1)
    ');

    $existsOwnerCopy = $db->prepare('
        SELECT id, template_id FROM plantillas
        WHERE activo = 1 AND usuario_id = ? AND copiado_de = ?
        LIMIT 1
    ');

    foreach ($userIds as $uid) {
        if (!in_array($uid, $validIds, true)) {
            $skipped++;
            $details[] = ['user_id' => $uid, 'result' => 'skipped_not_medico_informante'];
            continue;
        }

        if ($skipIfExists) {
            $existsOwnerCopy->execute([$uid, (int) $source['id']]);
            $existing = $existsOwnerCopy->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $skipped++;
                $details[] = [
                    'user_id' => $uid,
                    'result' => 'skipped_already_has_copy',
                    'template_id' => $existing['template_id'],
                ];
                continue;
            }
        }

        $newTid = plantillasMakeUniqueTemplateId($db, (string) $source['template_id'], $uid);
        $newName = (string) $source['nombre'];

        try {
            $insert->execute([
                $newTid,
                $newName,
                $source['contenido_html'],
                $uid,
                $actorId,
                (int) $source['id'],
            ]);
            $copied++;
            $details[] = [
                'user_id' => $uid,
                'result' => 'copied',
                'template_id' => $newTid,
            ];
        } catch (Exception $e) {
            $skipped++;
            $details[] = [
                'user_id' => $uid,
                'result' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'copied' => $copied,
        'skipped' => $skipped,
        'details' => $details,
        'message' => "Plantilla copiada a {$copied} médico(s)" . ($skipped > 0 ? ", {$skipped} omitido(s)" : ''),
        'source_template_id' => $sourceTemplateId,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    $code = (int) (http_response_code() ?: 500);
    if ($code < 400) {
        $code = 500;
        http_response_code(500);
    }
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
