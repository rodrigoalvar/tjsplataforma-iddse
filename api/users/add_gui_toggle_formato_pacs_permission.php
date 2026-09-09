<?php
/**
 * Registra el permiso gui_toggle_formato_pacs (Interfaz).
 * Ejecutar una vez: php api/users/add_gui_toggle_formato_pacs_permission.php
 */
require_once __DIR__ . '/../../config/database.php';

try {
    $pdo = getDBConnection();
    if (!$pdo) {
        throw new Exception('No se pudo conectar a la base de datos');
    }

    echo "=== PERMISO gui_toggle_formato_pacs ===\n\n";

    $key = 'gui_toggle_formato_pacs';
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
        'Selector formato PACS (PDF/IMG)',
        'En Gestión de Informes: muestra y permite cambiar el interruptor global PDF vs imagen al enviar a PACS. Sin este permiso, quien pueda enviar a PACS usa solo el formato definido en la configuración global.',
        'interfaz',
    ]);
    echo "✅ Insertado: {$key} (categoría interfaz)\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
