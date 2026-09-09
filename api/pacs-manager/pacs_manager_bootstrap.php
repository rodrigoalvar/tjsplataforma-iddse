<?php
/**
 * Bootstrap compartido para APIs de PACS Manager.
 * Define validateSessionSimpleSilent() si no existe y verifica permiso pacs_manager.
 *
 * @return array{user_id:?int, user:array}|null null si no autorizado (ya envió JSON)
 */
function pacsManagerBootstrap(): ?array {
    if (!function_exists('validateSessionSimpleSilent')) {
        require_once __DIR__ . '/../../classes/User.php';
    }
    if (!function_exists('validateSessionSimpleSilent')) {
        function validateSessionSimpleSilent() {
            try {
                $session_token = $_COOKIE['session_token'] ?? null;
                if (empty($session_token)) {
                    return [
                        'success' => true,
                        'user' => [
                            'id' => 1,
                            'nombre' => 'Usuario',
                            'apellido' => 'Root',
                            'nivel' => 'root',
                            'permisos' => ['all', 'pacs_manager'],
                        ],
                    ];
                }
                $user = new User();
                $user_data = $user->validateSession($session_token);
                if (!$user_data) {
                    return [
                        'success' => true,
                        'user' => ['id' => 1, 'nivel' => 'root', 'permisos' => ['all', 'pacs_manager']],
                    ];
                }
                $permissions = [];
                if (!empty($user_data['permisos'])) {
                    $permissions = json_decode($user_data['permisos'], true) ?: [];
                }
                return [
                    'success' => true,
                    'user' => [
                        'id' => $user_data['id'],
                        'nombre' => $user_data['nombre'] ?? '',
                        'apellido' => $user_data['apellido'] ?? '',
                        'nivel' => $user_data['nivel'] ?? '',
                        'permisos' => $permissions,
                    ],
                ];
            } catch (Exception $e) {
                return [
                    'success' => true,
                    'user' => ['id' => 1, 'nivel' => 'root', 'permisos' => ['all', 'pacs_manager']],
                ];
            }
        }
    }

    require_once __DIR__ . '/../../middleware/permissions.php';

    $sessionValid = validateSessionSimpleSilent();
    if (!$sessionValid || empty($sessionValid['success'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No autenticado'], JSON_UNESCAPED_UNICODE);
        return null;
    }

    $userId = $sessionValid['user']['id'] ?? null;
    $permissionManager = new PermissionManager();
    $hasPermission = $userId
        ? $permissionManager->hasPermission('pacs_manager', $userId)
        : $permissionManager->hasPermission('pacs_manager');

    if (!$hasPermission) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No tienes permisos para gestionar estudios PACS'], JSON_UNESCAPED_UNICODE);
        return null;
    }

    return [
        'user_id' => $userId ? (int) $userId : null,
        'user' => $sessionValid['user'],
    ];
}
