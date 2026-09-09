<?php
/**
 * Gestión de API keys para ingesta push de Worklist.
 * GET  => listar (sin exponer key/hash)
 * POST => crear nueva key
 * PUT  => activar/desactivar
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/WorklistIngestionService.php';

try {
    $token = null;
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        $token = $headers['Authorization'] ?? null;
    }
    if (!$token) {
        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    }
    if ($token && strpos($token, 'Bearer ') === 0) {
        $token = substr($token, 7);
    }
    if (!$token) {
        throw new Exception('Token de autorización requerido');
    }

    $user = new User();
    $userData = $user->validateSession($token);
    if (!$userData) {
        throw new Exception('Sesión inválida');
    }
    if (!in_array(strtolower($userData['nivel'] ?? 'user'), ['root', 'admin'], true)) {
        throw new Exception('No tienes permisos para gestionar API keys');
    }

    $db = getDBConnection();
    if (!$db) {
        throw new Exception('No se pudo conectar a la base de datos');
    }

    $service = new WorklistIngestionService($db);
    $service->ensureSchema();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $db->query("
            SELECT id, name, allowed_ips, is_active, last_used_at, created_at
            FROM worklist_api_keys
            ORDER BY id DESC
        ");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit();
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = trim((string)($input['name'] ?? ''));
        $allowedIps = trim((string)($input['allowed_ips'] ?? ''));
        if ($name === '') {
            throw new Exception('name es requerido');
        }

        $plain = bin2hex(random_bytes(24));
        $hash = password_hash($plain, PASSWORD_DEFAULT);

        $stmt = $db->prepare("
            INSERT INTO worklist_api_keys (name, key_hash, allowed_ips, is_active)
            VALUES (?, ?, ?, 1)
        ");
        $stmt->execute([$name, $hash, $allowedIps !== '' ? $allowedIps : null]);

        echo json_encode([
            'success' => true,
            'message' => 'API key creada',
            'data' => [
                'id' => (int)$db->lastInsertId(),
                'name' => $name,
                'api_key' => $plain
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            throw new Exception('id inválido');
        }
        $isActive = isset($input['is_active']) ? (int)$input['is_active'] : 1;
        $allowedIps = isset($input['allowed_ips']) ? trim((string)$input['allowed_ips']) : null;

        if ($allowedIps !== null) {
            $stmt = $db->prepare("UPDATE worklist_api_keys SET is_active = ?, allowed_ips = ? WHERE id = ?");
            $stmt->execute([$isActive, $allowedIps !== '' ? $allowedIps : null, $id]);
        } else {
            $stmt = $db->prepare("UPDATE worklist_api_keys SET is_active = ? WHERE id = ?");
            $stmt->execute([$isActive, $id]);
        }

        echo json_encode(['success' => true, 'message' => 'API key actualizada']);
        exit();
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
