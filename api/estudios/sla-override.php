<?php
/**
 * Override / exclusión SLA por estudio.
 * POST JSON: estudios_id, action=override|clear_override|exclude|include,
 *            sla_override_horas?, motivo?
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/sla_helper.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Método no permitido');
    }
    $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
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
    $can = in_array('all', $permisos, true) || in_array('monitorearSlaEstudios', $permisos, true);
    if (!$can) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Sin permiso']);
        exit;
    }
    if (!sla_estudios_columns_ready(getDBConnection())) {
        throw new Exception('Migración SLA pendiente');
    }

    $db = getDBConnection();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($input['estudios_id'] ?? 0);
    $action = trim((string)($input['action'] ?? ''));
    if ($id <= 0 || $action === '') {
        throw new Exception('estudios_id y action requeridos');
    }

    if ($action === 'override') {
        $h = (int)($input['sla_override_horas'] ?? 0);
        if ($h < 1) {
            throw new Exception('sla_override_horas debe ser >= 1');
        }
        $db->prepare('UPDATE estudios SET sla_override_horas = ? WHERE id = ?')->execute([$h, $id]);
    } elseif ($action === 'clear_override') {
        $db->prepare('UPDATE estudios SET sla_override_horas = NULL WHERE id = ?')->execute([$id]);
    } elseif ($action === 'exclude') {
        $motivo = trim((string)($input['motivo'] ?? 'Excluido manualmente'));
        $db->prepare('UPDATE estudios SET sla_excluido = 1, sla_excluido_motivo = ? WHERE id = ?')->execute([$motivo, $id]);
    } elseif ($action === 'include') {
        $db->prepare('UPDATE estudios SET sla_excluido = 0, sla_excluido_motivo = NULL WHERE id = ?')->execute([$id]);
    } else {
        throw new Exception('action inválida');
    }

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
