<?php
/**
 * Agrega permiso informes_carpeta (cola SMB / inotify).
 * Ejecutar una vez: php api/users/add_informes_carpeta_permission.php
 */

require_once __DIR__ . '/../../config/database.php';

try {
    $pdo = getDBConnection();
    if (!$pdo) {
        throw new Exception('No se pudo conectar a la base de datos');
    }

    echo "=== PERMISO INFORMES CARPETA ===\n\n";

    $key = 'informes_carpeta';
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
        'Informes desde carpetas (SMB)',
        'Permite ver el botón y la cola de PDF/TXT leídos desde carpetas montadas en Gestión de Informes.',
        'informes',
    ]);
    echo "✅ Insertado: {$key}\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
