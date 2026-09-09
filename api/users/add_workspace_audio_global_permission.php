<?php
/**
 * Agrega permiso audios_ver_todos_workspace.
 * Ejecutar una vez: php api/users/add_workspace_audio_global_permission.php
 */

require_once __DIR__ . '/../../config/database.php';

try {
    $pdo = getDBConnection();
    if (!$pdo) {
        throw new Exception('No se pudo conectar a la base de datos');
    }

    echo "=== PERMISO AUDIOS WORKSPACE GLOBAL ===\n\n";

    $key = 'audios_ver_todos_workspace';
    $chk = $pdo->prepare('SELECT id FROM system_permissions WHERE permission_key = ? LIMIT 1');
    $chk->execute([$key]);
    if ($chk->fetchColumn()) {
        echo "✅ Ya existe: {$key}\n";
        exit(0);
    }

    $ins = $pdo->prepare(
        'INSERT INTO system_permissions (permission_key, permission_name, description, category) VALUES (?, ?, ?, ?)'
    );
    $ins->execute([
        $key,
        'Workspace: ver audios de todos',
        'Permite cargar y recuperar audios de cualquier usuario al abrir un estudio en Workspace. Sin este permiso, solo se muestran audios propios.',
        'informes',
    ]);
    echo "✅ Insertado: {$key}\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
