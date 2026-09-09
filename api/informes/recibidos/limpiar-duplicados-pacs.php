<?php
/**
 * Limpiador de series DOC duplicadas en Orthanc.
 *
 * Escenario típico: el primer intento de envío llegó a Orthanc pero PHP murió
 * antes de actualizar informes.pacs_series_id en BD.  El reintento automático
 * subió una segunda serie DOC al mismo estudio.  La BD apunta a la segunda
 * serie; la primera queda huérfana en Orthanc.
 *
 * Uso:
 *   GET  ?dry_run=1   → muestra qué se eliminaría (sin borrar nada)
 *   POST              → ejecuta la limpieza real
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../api/OrthancPacsSender.php';

header('Content-Type: application/json; charset=utf-8');

$db     = getDBConnection();
$isDry  = ($_SERVER['REQUEST_METHOD'] !== 'POST') || !empty($_GET['dry_run']);

$sender  = new OrthancPacsSender();
$reflect = new ReflectionClass($sender);
$reqMethod = $reflect->getMethod('makeRequestWithRetry');
$reqMethod->setAccessible(true);

// --- Obtener informes candidatos a duplicado (try_count >= 2 o sin restricción si se pide full) ---
// Por defecto solo revisa try_count >= 2 (mucho más rápido).  ?full=1 para scan completo.
$fullScan = !empty($_GET['full']);
$stmt = $db->query('
    SELECT i.id AS informe_id, i.pacs_series_id, ir.accession_number, ir.id AS ir_id
    FROM informes i
    JOIN informes_recibidos ir ON ir.informe_id = i.id
    WHERE i.pacs_series_id IS NOT NULL
      AND i.pacs_series_id <> \'\'
      ' . ($fullScan ? '' : 'AND ir.auto_pacs_try_count >= 2') . '
    ORDER BY i.id DESC
    LIMIT 1000
');
$informes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$results  = [];
$totalDup = 0;
$deleted  = 0;
$errors   = 0;

foreach ($informes as $inf) {
    $knownSeriesId = $inf['pacs_series_id'];

    // Obtener study del series conocido
    try {
        $sr = $reqMethod->invoke($sender, '/series/' . urlencode($knownSeriesId), 'GET', null, 8);
    } catch (Exception $e) {
        continue; // Orthanc no disponible o serie ya no existe
    }
    if (!$sr['success']) {
        continue;
    }
    $studyId = $sr['data']['ParentStudy'] ?? null;
    if (!$studyId) {
        continue;
    }

    // Obtener todas las series del estudio
    try {
        $st = $reqMethod->invoke($sender, '/studies/' . urlencode($studyId), 'GET', null, 10);
    } catch (Exception $e) {
        continue;
    }
    if (!$st['success']) {
        continue;
    }
    $seriesInStudy = (array)($st['data']['Series'] ?? []);

    // Identificar series DOC extra (no la que tenemos en BD)
    $orphans = [];
    foreach ($seriesInStudy as $sid) {
        if ($sid === $knownSeriesId) {
            continue;
        }
        try {
            $s = $reqMethod->invoke($sender, '/series/' . urlencode($sid), 'GET', null, 6);
        } catch (Exception $e) {
            continue;
        }
        if (!$s['success']) {
            continue;
        }
        $modality = strtoupper($s['data']['MainDicomTags']['Modality'] ?? '');
        if ($modality === 'DOC') {
            $orphans[] = $sid;
        }
    }

    if (empty($orphans)) {
        continue;
    }

    $totalDup++;
    $entry = [
        'informe_id'      => $inf['informe_id'],
        'ir_id'           => $inf['ir_id'],
        'accession_number' => $inf['accession_number'],
        'study_id'        => $studyId,
        'kept_series'     => $knownSeriesId,
        'orphan_series'   => $orphans,
        'deleted'         => [],
        'failed'          => [],
    ];

    if (!$isDry) {
        foreach ($orphans as $sid) {
            try {
                $del = $reqMethod->invoke($sender, '/series/' . urlencode($sid), 'DELETE', null, 10);
                if ($del['success'] || ($del['status_code'] ?? 0) === 200) {
                    $entry['deleted'][] = $sid;
                    $deleted++;
                } else {
                    $entry['failed'][] = $sid;
                    $errors++;
                    error_log('[LIMPIAR_DUP_PACS] No se pudo eliminar serie ' . $sid . ': ' . json_encode($del));
                }
            } catch (Exception $e) {
                $entry['failed'][] = $sid;
                $errors++;
                error_log('[LIMPIAR_DUP_PACS] Excepción al eliminar serie ' . $sid . ': ' . $e->getMessage());
            }
        }
    }

    $results[] = $entry;
}

echo json_encode([
    'dry_run'        => $isDry,
    'total_afectados' => $totalDup,
    'total_eliminados' => $deleted,
    'total_errores'  => $errors,
    'detalle'        => $results,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
