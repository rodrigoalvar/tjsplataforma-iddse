<?php
/**
 * Backfill de informes_recibidos.numero_informe_pdf
 *
 * Pobla el campo numero_informe_pdf para registros existentes usando dos fuentes:
 *  1. informes_recibidos_intentos.pdf_filename  → nombre original del PDF recibido por API
 *  2. informes_carpeta_archivos.sufijo_nombre   → sufijo del PDF procesado desde carpeta SMB
 *
 * Seguro de re-ejecutar: solo actualiza filas donde numero_informe_pdf IS NULL.
 *
 * Uso:
 *   php database/backfill_numero_informe_pdf.php
 */

require_once __DIR__ . '/../config/database.php';

$db = getDBConnection();

function extractNumeroInformePdf(string $filename): ?string
{
    $bn = basename($filename);
    // Solo formato: idpaciente(numerico)_sufijo.extension
    if (!preg_match('/^[0-9]+_(.+)\.[^.]+$/i', $bn, $m)) {
        return null;
    }
    $suf = trim($m[1]);
    return $suf !== '' ? $suf : null;
}

$upd = $db->prepare('UPDATE informes_recibidos SET numero_informe_pdf = ? WHERE id = ? AND numero_informe_pdf IS NULL');

// --- Fuente 1: informes_recibidos_intentos ---
$rows = $db->query("
    SELECT it.informe_recibido_id, MIN(it.pdf_filename) AS pdf_filename
    FROM informes_recibidos_intentos it
    JOIN informes_recibidos ir ON ir.id = it.informe_recibido_id
    WHERE ir.numero_informe_pdf IS NULL
      AND it.pdf_filename IS NOT NULL
      AND it.pdf_filename LIKE '%\\_%'
    GROUP BY it.informe_recibido_id
")->fetchAll(PDO::FETCH_ASSOC);

$cnt1 = 0; $skip1 = 0;
foreach ($rows as $r) {
    $num = extractNumeroInformePdf($r['pdf_filename']);
    if ($num !== null) {
        $upd->execute([$num, (int)$r['informe_recibido_id']]);
        if ($upd->rowCount() > 0) {
            $cnt1++;
        }
    } else {
        $skip1++;
    }
}

// --- Fuente 2: informes_carpeta_archivos ---
$rows2 = $db->query("
    SELECT ica.informe_recibido_id, MIN(ica.sufijo_nombre) AS sufijo_nombre
    FROM informes_carpeta_archivos ica
    JOIN informes_recibidos ir ON ir.id = ica.informe_recibido_id
    WHERE ir.numero_informe_pdf IS NULL
      AND ica.tipo = 'pdf'
      AND ica.sufijo_nombre IS NOT NULL AND ica.sufijo_nombre <> ''
    GROUP BY ica.informe_recibido_id
")->fetchAll(PDO::FETCH_ASSOC);

$cnt2 = 0;
foreach ($rows2 as $r) {
    $upd->execute([$r['sufijo_nombre'], (int)$r['informe_recibido_id']]);
    if ($upd->rowCount() > 0) {
        $cnt2++;
    }
}

$totalCon = (int)$db->query('SELECT COUNT(*) FROM informes_recibidos WHERE numero_informe_pdf IS NOT NULL')->fetchColumn();
$totalSin = (int)$db->query('SELECT COUNT(*) FROM informes_recibidos WHERE numero_informe_pdf IS NULL')->fetchColumn();

echo "Backfill completado:" . PHP_EOL;
echo "  Poblados desde intentos: {$cnt1} (omitidos sin formato válido: {$skip1})" . PHP_EOL;
echo "  Poblados desde carpeta:  {$cnt2}" . PHP_EOL;
echo "  Total con numero_informe_pdf: {$totalCon}" . PHP_EOL;
echo "  Sin número (sin fuente recuperable): {$totalSin}" . PHP_EOL;
