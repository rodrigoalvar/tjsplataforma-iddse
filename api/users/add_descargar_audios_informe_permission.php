<?php
/**
 * Registra el permiso descargar_audios_informe (descarga MP3 desde Gestión de Informes).
 * Ejecutar una vez: php api/users/add_descargar_audios_informe_permission.php
 */

require_once __DIR__ . '/../../config/database.php';

try {
    $pdo = getDBConnection();
    if (!$pdo) {
        throw new Exception('No se pudo conectar a la base de datos');
    }

    echo "=== PERMISO DESCARGAR AUDIOS INFORME ===\n\n";

    $key = 'descargar_audios_informe';
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
        'Descargar audios del informe (MP3)',
        'Permite el icono de descarga en la columna Audios de Gestión de Informes (conversión a MP3 cuando aplica).',
        'audio',
    ]);
    echo "✅ Insertado: {$key}\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
