<?php
/**
 * Agrega permisos para Informes recibidos (API) y Adjuntar informe PDF a estudio en Gestión de Informes.
 * Ejecutar una vez: php api/users/add_informes_recibidos_permissions.php
 */

require_once __DIR__ . '/../../config/database.php';

try {
    $pdo = getDBConnection();
    if (!$pdo) {
        throw new Exception('No se pudo conectar a la base de datos');
    }

    echo "=== PERMISOS INFORMES RECIBIDOS / ADJUNTAR ESTUDIO ===\n\n";

    $keys = ['informes_recibidos', 'adjuntar_informe_estudio'];
    $checkStmt = $pdo->prepare(
        'SELECT id, permission_key, permission_name FROM system_permissions WHERE permission_key IN (?, ?)'
    );
    $checkStmt->execute($keys);
    $existing = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
    $existingKeys = array_column($existing, 'permission_key');

    $defs = [
        'informes_recibidos' => [
            'Informes recibidos (API)',
            'Permite ver el botón y el modal de informes recibidos por API en Gestión de Informes.',
            'informes',
        ],
        'adjuntar_informe_estudio' => [
            'Adjuntar informe PDF a estudio',
            'Permite ver el botón para subir un PDF y asociarlo a un estudio en Gestión de Informes.',
            'informes',
        ],
    ];

    $insertStmt = $pdo->prepare(
        'INSERT INTO system_permissions (permission_key, permission_name, description, category) VALUES (?, ?, ?, ?)'
    );

    foreach ($defs as $key => $meta) {
        if (in_array($key, $existingKeys, true)) {
            echo "✅ Ya existe: {$key}\n";
            continue;
        }
        $insertStmt->execute([$key, $meta[0], $meta[1], $meta[2]]);
        echo "✅ Insertado: {$key}\n";
    }

    echo "\nListo.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
