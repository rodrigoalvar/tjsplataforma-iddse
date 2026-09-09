<?php
/**
 * api/audios/link.php
 * Vincula uno o varios audios móviles (ya en DB) a un informe_id definitivo.
 * Llamado desde workspace cuando finaliza el informe, para los audios que
 * ya fueron subidos por la grabadora móvil y NO deben re-subirse.
 *
 * POST { informe_id: int, audio_ids: int[] }
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../../config/database.php';

// ---------- Auth ----------
function getAuthUser(PDO $db): ?array {
    $token = null;
    $auth  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) $token = $m[1];
    if (!$token) {
        $body = json_decode(file_get_contents('php://input'), true);
        $token = $body['session_token'] ?? null;
    }
    if (!$token) return null;
    // La tabla de sesiones utiliza la columna token_sesion (ver classes/User.php)
    $s = $db->prepare("SELECT u.id, u.nombre 
                       FROM usuarios u 
                       JOIN sesiones s ON s.usuario_id = u.id 
                       WHERE s.token_sesion = ? 
                         AND s.activa = 1 
                       LIMIT 1");
    $s->execute([$token]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$informeId = isset($input['informe_id']) ? (int)$input['informe_id'] : 0;
$audioIds  = $input['audio_ids']  ?? [];

if (!$informeId || empty($audioIds) || !is_array($audioIds)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'informe_id y audio_ids son requeridos']);
    exit();
}

try {
    $db   = getDBConnection();
    $user = getAuthUser($db);
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No autorizado']);
        exit();
    }

    // Sanear IDs
    $ids = array_filter(array_map('intval', $audioIds));
    if (empty($ids)) {
        echo json_encode(['success' => true, 'updated' => 0]);
        exit();
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$informeId], $ids);

    $stmt = $db->prepare("UPDATE audios_informe SET informe_id = ? WHERE id IN ($placeholders)");
    $stmt->execute($params);
    $updated = $stmt->rowCount();

    echo json_encode(['success' => true, 'updated' => $updated]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error interno: ' . $e->getMessage()]);
}
