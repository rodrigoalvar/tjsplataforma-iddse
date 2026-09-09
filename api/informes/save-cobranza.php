<?php
/**
 * Guardar datos de cobranza/planilla para un informe (solo el dueño del informe + permiso datosCobranzaInformes).
 */
header('Content-Type: application/json; charset=utf-8');
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

require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/informes_list_common.php';

function saveCobranza_readToken() {
    $sessionToken = null;
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (!empty($headers['Authorization']) && preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $m)) {
            $sessionToken = $m[1];
        }
    }
    if (!$sessionToken && !empty($_SERVER['HTTP_AUTHORIZATION']) && preg_match('/Bearer\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
        $sessionToken = $m[1];
    }
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!$sessionToken && !empty($input['session_token'])) {
        $sessionToken = $input['session_token'];
    }
    if (!$sessionToken && !empty($_COOKIE['session_token'])) {
        $sessionToken = $_COOKIE['session_token'];
    }
    return [$sessionToken, $input];
}

try {
    [$sessionToken, $input] = saveCobranza_readToken();
    if (!$sessionToken) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Token de sesión requerido']);
        exit;
    }

    $user = new User();
    $userData = $user->validateSession($sessionToken);
    if (!$userData) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sesión inválida']);
        exit;
    }

    $perms = $userData['permisos'] ?? [];
    if (!listInformes_userHasPermission($perms, 'datosCobranzaInformes')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Sin permiso datosCobranzaInformes']);
        exit;
    }

    $informeId = isset($input['informe_id']) ? (int) $input['informe_id'] : (isset($input['id']) ? (int) $input['id'] : 0);
    if ($informeId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'informe_id requerido']);
        exit;
    }

    if (!array_key_exists('cobranza_regiones', $input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'cobranza_regiones requerido (entero >= 0)']);
        exit;
    }

    $regiones = $input['cobranza_regiones'];
    if ($regiones !== null && $regiones !== '') {
        if (!is_numeric($regiones) || (int) $regiones < 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'cobranza_regiones debe ser un entero >= 0']);
            exit;
        }
        $regiones = (int) $regiones;
    } else {
        $regiones = null;
    }

    $estudioPlanilla = isset($input['cobranza_estudio_planilla']) ? (string) $input['cobranza_estudio_planilla'] : null;
    if ($estudioPlanilla !== null && strlen($estudioPlanilla) > 8000) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'cobranza_estudio_planilla demasiado largo']);
        exit;
    }

    $db = getDBConnection();
    if (!$db) {
        throw new Exception('Sin conexión a BD');
    }

    $check = $db->query("SHOW COLUMNS FROM informes LIKE 'cobranza_regiones'");
    if ($check->rowCount() === 0) {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Columnas de cobranza no instaladas. Ejecute migrate-informes-cobranza-columns.php']);
        exit;
    }

    $stmt = $db->prepare('SELECT id, usuario_id, study_description FROM informes WHERE id = ? LIMIT 1');
    $stmt->execute([$informeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Informe no encontrado']);
        exit;
    }

    if ((int) $row['usuario_id'] !== (int) $userData['id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Solo el usuario creador del informe puede editar estos datos']);
        exit;
    }

    $upd = $db->prepare('UPDATE informes SET cobranza_regiones = ?, cobranza_estudio_planilla = ?, cobranza_actualizado_en = NOW(), cobranza_actualizado_por = ? WHERE id = ?');
    $upd->execute([
        $regiones,
        $estudioPlanilla,
        (int) $userData['id'],
        $informeId
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Datos de cobranza guardados',
        'data' => [
            'informe_id' => $informeId,
            'cobranza_regiones' => $regiones,
            'cobranza_estudio_planilla' => $estudioPlanilla
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[save-cobranza] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error interno']);
}
