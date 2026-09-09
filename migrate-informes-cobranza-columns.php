<?php
/**
 * Agrega columnas de datos de cobranza/planilla a la tabla informes.
 * Ejecutar una vez: php migrate-informes-cobranza-columns.php
 */
require_once __DIR__ . '/config/database.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "Error: no hay conexión a la base de datos.\n");
    exit(1);
}

echo "Migración: columnas cobranza en informes\n\n";

$cols = $db->query("SHOW COLUMNS FROM informes")->fetchAll(PDO::FETCH_COLUMN, 0);

$alters = [];
if (!in_array('cobranza_regiones', $cols, true)) {
    $alters[] = "ADD COLUMN cobranza_regiones INT UNSIGNED NULL DEFAULT NULL COMMENT 'Regiones informadas (CODIGOS) para facturación'";
}
if (!in_array('cobranza_estudio_planilla', $cols, true)) {
    $alters[] = "ADD COLUMN cobranza_estudio_planilla TEXT NULL COMMENT 'Texto de estudio editable para planilla (distinto de study_description PACS)'";
}
if (!in_array('cobranza_actualizado_en', $cols, true)) {
    $alters[] = "ADD COLUMN cobranza_actualizado_en DATETIME NULL DEFAULT NULL";
}
if (!in_array('cobranza_actualizado_por', $cols, true)) {
    $alters[] = "ADD COLUMN cobranza_actualizado_por INT NULL DEFAULT NULL COMMENT 'usuario_id que registró/actualizó cobranza'";
}

if (empty($alters)) {
    echo "✅ Las columnas de cobranza ya existen. Nada que hacer.\n";
    exit(0);
}

$sql = "ALTER TABLE informes " . implode(", ", $alters);
echo $sql . "\n\n";
$db->exec($sql);
echo "✅ Migración aplicada.\n";
