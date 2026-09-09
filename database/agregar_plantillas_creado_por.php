<?php
/**
 * Migración: creado_por y copiado_de en plantillas.
 * Ejecutar una vez o dejar que las APIs lo auto-apliquen.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../api/plantillas/plantillas_common.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $db = getDBConnection();
    plantillasEnsureTraceColumns($db);
    $cols = $db->query('SHOW COLUMNS FROM plantillas')->fetchAll(PDO::FETCH_COLUMN);
    echo "OK plantillas columns:\n";
    foreach ($cols as $c) {
        echo " - $c\n";
    }
} catch (Exception $e) {
    http_response_code(500);
    echo 'ERROR: ' . $e->getMessage() . "\n";
}
