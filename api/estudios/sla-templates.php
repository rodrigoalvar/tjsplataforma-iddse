<?php
/**
 * CRUD plantillas SLA (GET lista / POST crear-actualizar / DELETE).
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../config/database.php';

try {
    $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? $_GET['session_token'] ?? null;
    if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
        $sessionToken = substr($sessionToken, 7);
    }
    if (!$sessionToken && !empty($_COOKIE['session_token'])) {
        $sessionToken = $_COOKIE['session_token'];
    }
    if (!$sessionToken) {
        throw new Exception('Token de sesión requerido');
    }
    $user = new User();
    $userData = $user->validateSession($sessionToken);
    if (!$userData) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sesión inválida']);
        exit;
    }
    $permisos = json_decode($userData['permisos'] ?? '[]', true) ?: [];
    // Lectura: monitorear o all; escritura: all o gestion config típica (all / configuracion)
    $canRead = in_array('all', $permisos, true)
        || in_array('monitorearSlaEstudios', $permisos, true)
        || in_array('configuracion', $permisos, true);
    $canWrite = in_array('all', $permisos, true) || in_array('configuracion', $permisos, true);
    if (!$canRead) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Sin permiso']);
        exit;
    }

    $db = getDBConnection();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $st = $db->query('SELECT * FROM sla_plantillas ORDER BY prioridad ASC, id ASC');
        echo json_encode(['success' => true, 'items' => $st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!$canWrite) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Sin permiso para modificar plantillas']);
        exit;
    }

    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            throw new Exception('id requerido');
        }
        $db->prepare('DELETE FROM sla_plantillas WHERE id = ?')->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($input['id'] ?? 0);
        $nombre = trim((string)($input['nombre'] ?? ''));
        $horas = (int)($input['horas'] ?? 72);
        $modalidades = trim((string)($input['modalidades'] ?? ''));
        $prioridad = (int)($input['prioridad'] ?? 100);
        $activo = !empty($input['activo']) ? 1 : 0;
        if ($nombre === '' || $horas < 1) {
            throw new Exception('nombre y horas (>=1) requeridos');
        }
        if ($id > 0) {
            $db->prepare('UPDATE sla_plantillas SET nombre=?, horas=?, modalidades=?, prioridad=?, activo=? WHERE id=?')
                ->execute([$nombre, $horas, $modalidades !== '' ? $modalidades : null, $prioridad, $activo, $id]);
        } else {
            $db->prepare('INSERT INTO sla_plantillas (nombre, horas, modalidades, prioridad, activo) VALUES (?,?,?,?,?)')
                ->execute([$nombre, $horas, $modalidades !== '' ? $modalidades : null, $prioridad, $activo]);
            $id = (int)$db->lastInsertId();
        }
        echo json_encode(['success' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new Exception('Método no permitido');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
