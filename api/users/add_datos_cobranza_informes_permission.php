<?php
/**
 * Agrega permiso datosCobranzaInformes (planilla / regiones).
 * Ejecutar una vez: php api/users/add_datos_cobranza_informes_permission.php
 */
require_once __DIR__ . '/../../config/database.php';

try {
    $pdo = getDBConnection();
    if (!$pdo) {
        throw new Exception('No se pudo conectar a la base de datos');
    }

    echo "=== PERMISO datosCobranzaInformes ===\n\n";

    $key = 'datosCobranzaInformes';
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
        'Datos cobranza / planilla informes',
        'Registro de regiones informadas, texto de estudio para planilla, listado provisorio y exportación CSV en Gestión de Informes.',
        'informes',
    ]);
    echo "✅ Insertado: {$key}\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
