<?php
/**
 * Endpoint de salud del módulo (Fase 0).
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../includes/DbSchema.php';

$user = requireRecepcionTurneroAuth('turnero');

try {
    $db = getDBConnection();
    $tables = [
        'rt_module_meta',
        'rt_turnos',
        'obras_sociales',
        'nomencladores',
    ];
    $status = [];
    foreach ($tables as $table) {
        $status[$table] = RtDbSchema::tableExists($db, $table);
    }

    rtJsonSuccess([
        'module' => 'recepcion-turnero',
        'version' => '1.0.0',
        'phase' => 0,
        'user_id' => $user['id'] ?? null,
        'tables' => $status,
    ], 'Módulo operativo (scaffold)');
} catch (Throwable $e) {
    rtAuthJsonError('Error', $e->getMessage(), 500);
}
