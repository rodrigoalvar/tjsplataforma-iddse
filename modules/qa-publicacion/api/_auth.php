<?php
/**
 * Autenticación para API del módulo Control de Calidad.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../classes/User.php';
require_once __DIR__ . '/../../../middleware/permissions.php';

function qaExtractToken(): ?string
{
    if (!empty($_COOKIE['session_token'])) {
        return (string) $_COOKIE['session_token'];
    }
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (strpos($authHeader, 'Bearer ') === 0) {
        return substr($authHeader, 7);
    }
    return null;
}

function requireQaManagerAccess(): array
{
    $user = qaValidateSessionOnly();
    if (qaUserHasAnyPermission($user, [
        'gui_qa_publicacion', 'qa_revisar', 'qa_config', 'qa_ver_registro', 'qa_despublicar_rapido',
    ])) {
        return $user;
    }
    qaJsonError('Sin permisos para el módulo QA', 403);
}

function requireQaRevisarOrQuick(): array
{
    $user = qaValidateSessionOnly();
    if (qaUserHasAnyPermission($user, ['qa_revisar', 'qa_despublicar_rapido'])) {
        return $user;
    }
    qaJsonError('Se requiere permiso qa_revisar o qa_despublicar_rapido', 403);
}

/** @return array<string, mixed> */
function qaValidateSessionOnly(): array
{
    $token = qaExtractToken();
    if (!$token) {
        qaJsonError('Token de sesión requerido', 401);
    }
    $userObj = new User();
    $userData = $userObj->validateSession($token);
    if (!$userData) {
        qaJsonError('Sesión inválida o expirada', 401);
    }
    return $userData;
}

/** @return array<string, mixed> */
function requireQaAuth(string $permissionKey): array
{
    $token = qaExtractToken();
    if (!$token) {
        qaJsonError('Token de sesión requerido', 401);
    }

    $userObj = new User();
    $userData = $userObj->validateSession($token);
    if (!$userData) {
        qaJsonError('Sesión inválida o expirada', 401);
    }

    $level = $userData['nivel'] ?? $userData['level'] ?? '';
    if ($level === 'root') {
        return $userData;
    }

    $permManager = new PermissionManager();
    if (!$permManager->hasPermission($permissionKey, (int) $userData['id'])) {
        qaJsonError("Se requiere el permiso: {$permissionKey}", 403);
    }

    return $userData;
}

function qaUserHasAnyPermission(array $userData, array $permissionKeys): bool
{
    $level = $userData['nivel'] ?? $userData['level'] ?? '';
    if ($level === 'root') {
        return true;
    }
    $permManager = new PermissionManager();
    foreach ($permissionKeys as $key) {
        if ($permManager->hasPermission($key, (int) $userData['id'])) {
            return true;
        }
    }
    return false;
}

/** ¿Puede editar la configuración QA? (root o permiso qa_config) */
function qaUserCanManageConfig(array $userData): bool
{
    return qaUserHasAnyPermission($userData, ['qa_config']);
}

/** ¿Puede ver el registro de auditoría QA? */
function qaUserCanViewLog(array $userData): bool
{
    return qaUserHasAnyPermission($userData, ['qa_ver_registro', 'qa_config']);
}

function qaJsonError(string $message, int $code = 400): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

/** @param array<string, mixed> $data */
function qaJsonSuccess(array $data): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(array_merge(['success' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function qaReadJsonInput(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '[]', true);
    return is_array($data) ? $data : [];
}
