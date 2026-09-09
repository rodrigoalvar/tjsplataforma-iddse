<?php
/**
 * Helpers compartidos para plantillas (columnas de trazabilidad).
 */

if (!function_exists('plantillasEnsureTraceColumns')) {
    /**
     * Asegura columnas creado_por y copiado_de en plantillas.
     */
    function plantillasEnsureTraceColumns(PDO $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            $cols = $db->query('SHOW COLUMNS FROM plantillas')->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('creado_por', $cols, true)) {
                $db->exec('ALTER TABLE plantillas ADD COLUMN creado_por INT NULL DEFAULT NULL COMMENT \'Usuario que creó la plantilla\' AFTER usuario_id');
                try {
                    $db->exec('ALTER TABLE plantillas ADD INDEX idx_creado_por (creado_por)');
                } catch (Exception $e) {
                    // índice puede fallar si ya existe
                }
            }
            if (!in_array('copiado_de', $cols, true)) {
                $db->exec('ALTER TABLE plantillas ADD COLUMN copiado_de INT NULL DEFAULT NULL COMMENT \'ID interno de plantilla origen si es copia\' AFTER creado_por');
                try {
                    $db->exec('ALTER TABLE plantillas ADD INDEX idx_copiado_de (copiado_de)');
                } catch (Exception $e) {
                    // ignore
                }
            }
            // Backfill: si creado_por vacío, usar usuario_id
            $db->exec('UPDATE plantillas SET creado_por = usuario_id WHERE creado_por IS NULL AND usuario_id IS NOT NULL');
        } catch (Exception $e) {
            error_log('[PLANTILLAS] plantillasEnsureTraceColumns: ' . $e->getMessage());
        }
        $done = true;
    }
}

if (!function_exists('plantillasAuthFromRequest')) {
    /**
     * @return array{user: array, permisos: array, can_manage: bool, can_view_all: bool, rol: string}
     */
    function plantillasAuthFromRequest(): array
    {
        require_once __DIR__ . '/../../classes/User.php';

        $token = null;
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        } else {
            $headers = [];
            foreach ($_SERVER as $name => $value) {
                if (substr($name, 0, 5) == 'HTTP_') {
                    $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
                }
            }
        }
        if (isset($headers['Authorization']) && preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $m)) {
            $token = $m[1];
        }

        if (!$token) {
            throw new Exception('Token de sesión requerido');
        }

        $user = new User();
        $userData = $user->validateSession($token);
        if (!$userData) {
            throw new Exception('Sesión inválida o expirada');
        }

        $permisos = $userData['permisos'] ?? [];
        if (is_string($permisos)) {
            $permisos = json_decode($permisos, true) ?: [];
        }
        if (!is_array($permisos)) {
            $permisos = [];
        }

        $canManage = in_array('all', $permisos, true) || in_array('plantillas', $permisos, true);
        $canViewAll = in_array('all', $permisos, true)
            || in_array('ver_todas_plantillas', $permisos, true);

        $rol = '';
        try {
            $db = getDBConnection();
            $r = $db->prepare('SELECT rol FROM usuarios WHERE id = ? LIMIT 1');
            $r->execute([(int) $userData['id']]);
            $rol = strtolower(trim((string) ($r->fetchColumn() ?: '')));
        } catch (Exception $e) {
            $rol = '';
        }
        if ($rol === '' && !empty($userData['rol'])) {
            $rol = strtolower(trim((string) $userData['rol']));
        }

        return [
            'user' => $userData,
            'permisos' => $permisos,
            'can_manage' => $canManage,
            'can_view_all' => $canViewAll,
            'rol' => $rol,
        ];
    }
}

if (!function_exists('plantillasCanCopyToOwners')) {
    function plantillasCanCopyToOwners(array $auth): bool
    {
        if (!empty($auth['permisos']) && in_array('all', $auth['permisos'], true)) {
            return true;
        }
        if (empty($auth['can_manage'])) {
            return false;
        }
        if (($auth['rol'] ?? '') === 'transcriptor') {
            return true;
        }
        return !empty($auth['can_view_all']);
    }
}

if (!function_exists('plantillasMakeUniqueTemplateId')) {
    function plantillasMakeUniqueTemplateId(PDO $db, string $baseId, int $ownerUserId): string
    {
        $base = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $baseId);
        $base = trim($base, '_');
        if ($base === '') {
            $base = 'plantilla';
        }
        $candidate = $base . '_u' . $ownerUserId;
        $check = $db->prepare('SELECT id FROM plantillas WHERE template_id = ? LIMIT 1');
        $check->execute([$candidate]);
        if (!$check->fetchColumn()) {
            return $candidate;
        }
        $candidate = $base . '_u' . $ownerUserId . '_' . time();
        $check->execute([$candidate]);
        if (!$check->fetchColumn()) {
            return $candidate;
        }
        return $base . '_u' . $ownerUserId . '_' . uniqid();
    }
}
