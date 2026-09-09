<?php
/**
 * Descarta un informe recibido por API y deja trazabilidad de auditoría.
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

function resolveSessionUser(User $user): ?array {
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
    return $user->validateSession($sessionToken) ?: null;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new Exception('JSON inválido');
    }

    $recibidoId = (int)($input['recibido_id'] ?? 0);
    $motivo = trim((string)($input['motivo_descarte'] ?? ''));

    if ($recibidoId <= 0) {
        throw new Exception('Parámetro requerido: recibido_id');
    }
    if ($motivo === '') {
        throw new Exception('Debe indicar motivo de descarte');
    }

    $db = getDBConnection();
    $user = new User();
    $sessionUser = resolveSessionUser($user);
    if (!$sessionUser || empty($sessionUser['id'])) {
        throw new Exception('Sesión inválida o expirada');
    }
    $userId = (int)$sessionUser['id'];

    $rowStmt = $db->prepare("SELECT id, estado, estudio_id FROM informes_recibidos WHERE id = ? LIMIT 1");
    $rowStmt->execute([$recibidoId]);
    $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Informe recibido no encontrado');
    }
    if ((int)($row['estudio_id'] ?? 0) > 0 || (string)($row['estado'] ?? '') === 'vinculado') {
        throw new Exception('No se puede descartar un informe ya vinculado');
    }
    if ((string)($row['estado'] ?? '') === 'descartado') {
        throw new Exception('El informe ya está descartado');
    }

    $updateStmt = $db->prepare("
        UPDATE informes_recibidos
        SET estado = 'descartado',
            motivo_descarte = ?,
            descartado_por_usuario_id = ?,
            fecha_descarte = NOW()
        WHERE id = ?
    ");
    $updateStmt->execute([$motivo, $userId, $recibidoId]);

    echo json_encode([
        'success' => true,
        'message' => 'Informe descartado correctamente',
        'data' => [
            'recibido_id' => $recibidoId,
            'estado' => 'descartado',
            'motivo_descarte' => $motivo,
            'descartado_por_usuario_id' => $userId,
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

