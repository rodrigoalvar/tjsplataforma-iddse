<?php
/**
 * Acumula tiempo de sesión “con uso” (pestaña visible + interacción reciente en el cliente).
 * El cliente envía latidos espaciados; el servidor suma segundos entre marcas con techo y
 * descarta huecos largos (usuario inactivo / sin pestaña en primer plano).
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/_common.php';

$user = auditManagerRequireLogin();
$db = getDBConnection();
$token = auditManagerGetToken();
if ($token === null || $token === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autenticado'], JSON_UNESCAPED_UNICODE);
    exit;
}

$MIN_INTERVAL = 5;
$MAX_GAP = 175;

function auditSesionesHasActiveSeconds(PDO $db): bool {
    try {
        $st = $db->query("SHOW COLUMNS FROM sesiones LIKE 'active_seconds'");

        return $st && $st->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

if (!auditSesionesHasActiveSeconds($db)) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error' => 'Ejecute install.php del módulo audit-manager para añadir active_seconds',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$uid = (int) $user['id'];

$sel = $db->prepare(
    'SELECT id, active_seconds, active_tick_at FROM sesiones
     WHERE token_sesion = ? AND usuario_id = ? AND activa = 1 AND fecha_expiracion > NOW() LIMIT 1'
);
$sel->execute([$token, $uid]);
$row = $sel->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Sesión no encontrada o cerrada'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sessionId = (int) $row['id'];
$activeSeconds = (int) $row['active_seconds'];

if ($row['active_tick_at'] === null) {
    $db->prepare('UPDATE sesiones SET active_tick_at = NOW() WHERE id = ?')->execute([$sessionId]);
    echo json_encode([
        'success' => true,
        'active_seconds' => $activeSeconds,
        'initialized' => true,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$tickAt = $row['active_tick_at'];
$elapsedStmt = $db->prepare('SELECT TIMESTAMPDIFF(SECOND, ?, NOW()) AS elapsed');
$elapsedStmt->execute([$tickAt]);
$elapsed = (int) $elapsedStmt->fetchColumn();

if ($elapsed < $MIN_INTERVAL) {
    echo json_encode([
        'success' => true,
        'active_seconds' => $activeSeconds,
        'skipped' => true,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($elapsed > $MAX_GAP) {
    $db->prepare('UPDATE sesiones SET active_tick_at = NOW() WHERE id = ?')->execute([$sessionId]);
    echo json_encode([
        'success' => true,
        'active_seconds' => $activeSeconds,
        'gap_reset' => true,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$add = min($elapsed, $MAX_GAP);
$up = $db->prepare('UPDATE sesiones SET active_seconds = active_seconds + ?, active_tick_at = NOW() WHERE id = ?');
$up->execute([$add, $sessionId]);

$cur = $db->prepare('SELECT active_seconds FROM sesiones WHERE id = ?');
$cur->execute([$sessionId]);
$newTotal = (int) $cur->fetchColumn();

echo json_encode([
    'success' => true,
    'active_seconds' => $newTotal,
    'credited_seconds' => $add,
], JSON_UNESCAPED_UNICODE);
