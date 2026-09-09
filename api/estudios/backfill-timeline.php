<?php
/**
 * Backfill study_informe_timeline desde datos existentes.
 * Uso: php api/estudios/backfill-timeline.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/sla_helper.php';

$db = getDBConnection();
if (!sla_timeline_table_ready($db) || !sla_estudios_columns_ready($db)) {
    fwrite(STDERR, "Migración SLA pendiente\n");
    exit(1);
}

$n = 0;
$st = $db->query('SELECT id, local_arrived_at FROM estudios WHERE local_arrived_at IS NOT NULL');
while ($e = $st->fetch(PDO::FETCH_ASSOC)) {
    sla_record_event($db, (int)$e['id'], 'arrived', $e['local_arrived_at'], 'system');
    $n++;
}
echo "arrived: $n\n";

$n = 0;
$st = $db->query("SELECT study_id, orthanc_study_id, study_instance_uid, MIN(assigned_date) AS ad
                  FROM study_assignments WHERE status = 'active'
                  GROUP BY study_id, orthanc_study_id, study_instance_uid");
while ($a = $st->fetch(PDO::FETCH_ASSOC)) {
    sla_mark_assigned_for_study($db, $a['study_id'], $a['ad'], $a['orthanc_study_id'], $a['study_instance_uid']);
    $n++;
}
echo "assigned: $n\n";

$n = 0;
// Solo estudios con audio o informe (evita barrido completo lento)
$st = $db->query("SELECT DISTINCT e.id FROM estudios e
  WHERE EXISTS (
    SELECT 1 FROM informes i
    WHERE i.estudio_id = e.orthanc_study_id OR i.study_id = e.orthanc_study_id
       OR i.estudio_id = CAST(e.id AS CHAR) OR i.study_id = CAST(e.id AS CHAR)
       OR (e.study_instance_uid IS NOT NULL AND e.study_instance_uid <> '' AND i.study_instance_uid = e.study_instance_uid)
  )
  OR EXISTS (
    SELECT 1 FROM audios a WHERE a.estudio_id = CAST(e.id AS CHAR) OR a.estudio_id = e.orthanc_study_id
  )
  LIMIT 20000");
while ($e = $st->fetch(PDO::FETCH_ASSOC)) {
    sla_try_mark_dictated($db, (int)$e['id']);
    $n++;
}
echo "dictated scan: $n\n";

$n = 0;
$st = $db->query("SELECT id, estudio_id, study_id, study_instance_uid, fecha_modificacion, firmado_en, estado,
                         fecha_enviado_pacs, pacs_series_id, pacs_instance_id
                  FROM informes
                  WHERE estado IN ('transcripto','firmado','finalizado')
                     OR fecha_enviado_pacs IS NOT NULL
                     OR (pacs_series_id IS NOT NULL AND pacs_series_id <> '')");
while ($i = $st->fetch(PDO::FETCH_ASSOC)) {
    $est = strtolower((string)$i['estado']);
    if (in_array($est, ['transcripto', 'firmado', 'finalizado'], true)) {
        sla_mark_informe_estado_event($db, $i, 'transcripto');
        $n++;
    }
    if ($est === 'firmado' || $est === 'finalizado' || !empty($i['firmado_en'])) {
        sla_mark_informe_estado_event($db, $i, 'firmado');
    }
    if (!empty($i['fecha_enviado_pacs']) || !empty($i['pacs_series_id']) || !empty($i['pacs_instance_id'])) {
        sla_mark_publicado_for_informe($db, $i);
    }
}
echo "informe events scanned: $n\n";
echo "OK\n";
