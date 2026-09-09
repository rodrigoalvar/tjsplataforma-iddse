<?php
/**
 * Cola / conteo SLA: estudios locales sin informe publicado.
 * GET: count_only, bucket=vencido|por_vencer|sin_informe|all, fecha_inicio, fecha_fin, limit
 * Respeta sla_activo=0 → counts 0 / items [].
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/sla_helper.php';

try {
    $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? $_GET['session_token'] ?? null;
    if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
        $sessionToken = substr($sessionToken, 7);
    }
    if (!$sessionToken && !empty($_COOKIE['session_token'])) {
        $sessionToken = $_COOKIE['session_token'];
    }
    if (!$sessionToken) {
        throw new Exception('Token de sesión requerido');
    }

    $user = new User();
    $userData = $user->validateSession($sessionToken);
    if (!$userData) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sesión inválida']);
        exit;
    }

    $permisos = json_decode($userData['permisos'] ?? '[]', true) ?: [];
    $can = in_array('all', $permisos, true) || in_array('monitorearSlaEstudios', $permisos, true);
    if (!$can) {
        echo json_encode(['success' => true, 'activo' => false, 'vencidos' => 0, 'por_vencer' => 0, 'items' => [], 'message' => 'Sin permiso']);
        exit;
    }

    $db = getDBConnection();
    if (!sla_estudios_columns_ready($db)) {
        echo json_encode(['success' => true, 'activo' => false, 'vencidos' => 0, 'por_vencer' => 0, 'items' => [], 'message' => 'Migración SLA pendiente']);
        exit;
    }

    $activo = sla_is_feature_active($db);
    $countOnly = isset($_GET['count_only']) && $_GET['count_only'] !== '0' && $_GET['count_only'] !== 'false';
    $bucket = trim((string)($_GET['bucket'] ?? 'all'));
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 100)));
    $fechaInicio = trim((string)($_GET['fecha_inicio'] ?? ''));
    $fechaFin = trim((string)($_GET['fecha_fin'] ?? ''));

    if (!$activo) {
        echo json_encode([
            'success' => true,
            'activo' => false,
            'vencidos' => 0,
            'por_vencer' => 0,
            'sin_informe' => 0,
            'items' => [],
            'message' => 'SLA desactivado (sla_activo=0)',
        ]);
        exit;
    }

    $defaultH = sla_default_hours($db);
    $warnH = sla_warning_hours($db);

    // Cargar plantillas una vez
    $plantillas = [];
    try {
        $pq = $db->query('SELECT id, horas, modalidades FROM sla_plantillas WHERE activo = 1 ORDER BY prioridad ASC, id ASC');
        $plantillas = $pq ? $pq->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        $plantillas = [];
    }

    $resolveHoras = static function (array $e) use ($defaultH, $plantillas): array {
        if (isset($e['sla_override_horas']) && $e['sla_override_horas'] !== null && $e['sla_override_horas'] !== '') {
            $oh = (int)$e['sla_override_horas'];
            if ($oh > 0) {
                return ['horas' => $oh, 'fuente' => 'override', 'plantilla_id' => null];
            }
        }
        $mod = strtoupper(trim((string)($e['modality'] ?? '')));
        foreach ($plantillas as $p) {
            $mods = trim((string)($p['modalidades'] ?? ''));
            if ($mods === '') {
                return ['horas' => max(1, (int)$p['horas']), 'fuente' => 'plantilla', 'plantilla_id' => (int)$p['id']];
            }
            $list = array_filter(array_map(static function ($x) {
                return strtoupper(trim($x));
            }, preg_split('/[,;\\s]+/', $mods) ?: []));
            if ($mod !== '' && in_array($mod, $list, true)) {
                return ['horas' => max(1, (int)$p['horas']), 'fuente' => 'plantilla', 'plantilla_id' => (int)$p['id']];
            }
        }
        return ['horas' => $defaultH, 'fuente' => 'default', 'plantilla_id' => null];
    };

    $dateSql = '';
    $params = [];
    if ($fechaInicio !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaInicio)) {
        $dateSql .= ' AND DATE(e.local_arrived_at) >= ?';
        $params[] = $fechaInicio;
    }
    if ($fechaFin !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaFin)) {
        $dateSql .= ' AND DATE(e.local_arrived_at) <= ?';
        $params[] = $fechaFin;
    }

    // Candidatos: con ancla, no excluidos, sin publicación PACS
    $sql = "
        SELECT e.id, e.orthanc_study_id, e.study_instance_uid, e.patient_id_pacs, e.patient_name_pacs,
               e.modality, e.study_description, e.study_date, e.local_arrived_at,
               e.sla_override_horas, e.sla_excluido, e.sla_excluido_motivo,
               (
                 SELECT i.id FROM informes i
                 WHERE (i.estudio_id = e.orthanc_study_id OR i.study_id = e.orthanc_study_id
                        OR i.estudio_id = CAST(e.id AS CHAR) OR i.study_id = CAST(e.id AS CHAR)
                        OR (e.study_instance_uid IS NOT NULL AND e.study_instance_uid <> '' AND i.study_instance_uid = e.study_instance_uid))
                 ORDER BY i.fecha_modificacion DESC LIMIT 1
               ) AS informe_id,
               (
                 SELECT i.estado FROM informes i
                 WHERE (i.estudio_id = e.orthanc_study_id OR i.study_id = e.orthanc_study_id
                        OR i.estudio_id = CAST(e.id AS CHAR) OR i.study_id = CAST(e.id AS CHAR)
                        OR (e.study_instance_uid IS NOT NULL AND e.study_instance_uid <> '' AND i.study_instance_uid = e.study_instance_uid))
                 ORDER BY i.fecha_modificacion DESC LIMIT 1
               ) AS informe_estado,
               (
                 SELECT 1 FROM informes i
                 WHERE (i.estudio_id = e.orthanc_study_id OR i.study_id = e.orthanc_study_id
                        OR i.estudio_id = CAST(e.id AS CHAR) OR i.study_id = CAST(e.id AS CHAR)
                        OR (e.study_instance_uid IS NOT NULL AND e.study_instance_uid <> '' AND i.study_instance_uid = e.study_instance_uid))
                   AND (
                     (i.pacs_series_id IS NOT NULL AND i.pacs_series_id <> '')
                     OR (i.pacs_instance_id IS NOT NULL AND i.pacs_instance_id <> '')
                     OR i.fecha_enviado_pacs IS NOT NULL
                   )
                 LIMIT 1
               ) AS publicado
        FROM estudios e
        WHERE e.local_arrived_at IS NOT NULL
          AND IFNULL(e.sla_excluido, 0) = 0
          {$dateSql}
        ORDER BY e.local_arrived_at ASC
        LIMIT 5000
    ";
    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $now = time();
    $vencidos = 0;
    $porVencer = 0;
    $sinInforme = 0;
    $items = [];

    foreach ($rows as $e) {
        if (!empty($e['publicado'])) {
            continue;
        }
        $resH = $resolveHoras($e);
        $horas = $resH['horas'];
        $arrivedTs = strtotime($e['local_arrived_at']);
        if (!$arrivedTs) {
            continue;
        }
        $dueTs = $arrivedTs + ($horas * 3600);
        $warnTs = $dueTs - ($warnH * 3600);
        $isVencido = $now > $dueTs;
        $isPorVencer = !$isVencido && $now > $warnTs;
        $hasInforme = !empty($e['informe_id']);
        if (!$hasInforme) {
            $sinInforme++;
        }
        if ($isVencido) {
            $vencidos++;
        } elseif ($isPorVencer) {
            $porVencer++;
        }

        $itemBucket = $isVencido ? 'vencido' : ($isPorVencer ? 'por_vencer' : 'en_plazo');
        if ($bucket === 'vencido' && !$isVencido) {
            continue;
        }
        if ($bucket === 'por_vencer' && !$isPorVencer) {
            continue;
        }
        if ($bucket === 'sin_informe' && $hasInforme) {
            continue;
        }
        if (in_array($bucket, ['vencido', 'por_vencer'], true) === false && $bucket === 'en_plazo' && $itemBucket !== 'en_plazo') {
            continue;
        }
        // Para lista operativa por defecto: solo vencidos + por vencer (salvo bucket explícito all/sin_informe/en_plazo)
        if ($bucket === 'all' || $bucket === '') {
            if (!$isVencido && !$isPorVencer) {
                continue;
            }
        }

        $secsLeft = $dueTs - $now;
        $items[] = [
            'estudios_id' => (int)$e['id'],
            'orthanc_study_id' => $e['orthanc_study_id'],
            'study_instance_uid' => $e['study_instance_uid'],
            'patient_id' => $e['patient_id_pacs'],
            'patient_name' => $e['patient_name_pacs'],
            'modality' => $e['modality'],
            'study_description' => $e['study_description'],
            'local_arrived_at' => $e['local_arrived_at'],
            'sla_horas' => $horas,
            'sla_fuente' => $resH['fuente'],
            'vence_at' => date('Y-m-d H:i:s', $dueTs),
            'seconds_remaining' => $secsLeft,
            'bucket' => $itemBucket,
            'sin_informe' => !$hasInforme,
            'informe_id' => $e['informe_id'] ? (int)$e['informe_id'] : null,
            'informe_estado' => $e['informe_estado'] ?: null,
            'sla_override_horas' => $e['sla_override_horas'] !== null ? (int)$e['sla_override_horas'] : null,
        ];
    }

    // Orden: más vencidos primero (seconds_remaining ASC)
    usort($items, static function ($a, $b) {
        return $a['seconds_remaining'] <=> $b['seconds_remaining'];
    });

    if ($countOnly) {
        echo json_encode([
            'success' => true,
            'activo' => true,
            'vencidos' => $vencidos,
            'por_vencer' => $porVencer,
            'sin_informe' => $sinInforme,
            'default_horas' => $defaultH,
            'warning_horas' => $warnH,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $items = array_slice($items, 0, $limit);

    // Timeline breve
    if ($items && sla_timeline_table_ready($db)) {
        $ids = array_column($items, 'estudios_id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $tq = $db->prepare("SELECT estudios_id, evento, occurred_at, source FROM study_informe_timeline WHERE estudios_id IN ($in)");
        $tq->execute($ids);
        $byEst = [];
        while ($t = $tq->fetch(PDO::FETCH_ASSOC)) {
            $byEst[(int)$t['estudios_id']][] = [
                'evento' => $t['evento'],
                'occurred_at' => $t['occurred_at'],
                'source' => $t['source'],
            ];
        }
        foreach ($items as &$it) {
            $it['timeline'] = $byEst[$it['estudios_id']] ?? [];
        }
        unset($it);
    }

    echo json_encode([
        'success' => true,
        'activo' => true,
        'vencidos' => $vencidos,
        'por_vencer' => $porVencer,
        'sin_informe' => $sinInforme,
        'count' => count($items),
        'items' => $items,
        'default_horas' => $defaultH,
        'warning_horas' => $warnH,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
