<?php
/**
 * Prueba config / login Gasalud (sin PDF).
 * POST { dry_run: true } → valida config
 * POST { dry_run: false, test_login: true } → login real y cachea token
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
require_once __DIR__ . '/gasalud_envio_helper.php';

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
    $can = in_array('all', $permisos, true)
        || in_array('configuracion', $permisos, true)
        || in_array('gestionInformes', $permisos, true);
    if (!$can) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Sin permiso']);
        exit;
    }

    $db = getDBConnection();
    $cfg = gasalud_load_envio_config($db);
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $dryRun = !isset($input['dry_run']) || $input['dry_run'] !== false;
    $testLogin = !empty($input['test_login']);

    if ($cfg['url'] === '') {
        throw new Exception('Configure gasalud_api_url antes de probar');
    }
    if (!preg_match('#^https?://#i', $cfg['url'])) {
        throw new Exception('La URL Informes debe comenzar con http:// o https://');
    }

    if (($cfg['auth_mode'] ?? '') === 'login') {
        if ($cfg['login_url'] === '' || !preg_match('#^https?://#i', $cfg['login_url'])) {
            throw new Exception('Configure gasalud_login_url');
        }
        if ($cfg['auth_username'] === '' || $cfg['auth_password'] === '') {
            throw new Exception('Faltan usuario/password de Login Gasalud');
        }
    } elseif ($cfg['auth_mode'] === 'bearer' || $cfg['auth_mode'] === 'api_key') {
        if ($cfg['auth_token'] === '') {
            throw new Exception('Falta token/API key');
        }
    } elseif ($cfg['auth_mode'] === 'basic') {
        if ($cfg['auth_username'] === '') {
            throw new Exception('Falta usuario Basic Auth');
        }
    }

    if ($dryRun && !$testLogin) {
        echo json_encode([
            'success' => true,
            'message' => 'Configuración válida (dry-run). auth=' . $cfg['auth_mode']
                . ($cfg['activo'] ? ', envío ACTIVO' : ', envío APAGADO')
                . ', trigger=' . $cfg['trigger'],
            'config_summary' => [
                'activo' => $cfg['activo'],
                'url' => $cfg['url'],
                'login_url' => $cfg['login_url'],
                'auth_mode' => $cfg['auth_mode'],
                'trigger' => $cfg['trigger'],
                'prestador_modo' => $cfg['prestador_modo'],
                'tipo_default' => $cfg['tipo_default'],
                'username' => $cfg['auth_username'] !== '' ? $cfg['auth_username'] : null,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($testLogin || ($cfg['auth_mode'] === 'login' && !$dryRun)) {
        $got = gasalud_login_and_cache_token($db, $cfg);
        echo json_encode([
            'success' => true,
            'message' => 'Login Gasalud OK. Token cacheado'
                . ($got['expiration'] ? (' hasta ' . $got['expiration']) : ''),
            'expiration' => $got['expiration'],
            'token_preview' => substr($got['token'], 0, 12) . '…',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Nada que probar (use test_login o dry_run)',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
