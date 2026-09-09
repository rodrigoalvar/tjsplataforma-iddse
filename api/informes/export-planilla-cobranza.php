<?php
/**
 * Listado / export CSV/Excel planilla cobranza (regiones por modalidad).
 * Requiere permiso datosCobranzaInformes. Mismo alcance de informes que list.php.
 */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

require_once __DIR__ . '/informes_list_common.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/User.php';

function expCobranza_getToken() {
    $token = null;
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (!empty($headers['Authorization']) && preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $m)) {
            $token = $m[1];
        }
    }
    if (!$token && !empty($_SERVER['HTTP_AUTHORIZATION']) && preg_match('/Bearer\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
        $token = $m[1];
    }
    if (!$token && !empty($_GET['session_token'])) {
        $token = $_GET['session_token'];
    }
    if (!$token && !empty($_COOKIE['session_token'])) {
        $token = $_COOKIE['session_token'];
    }
    return $token;
}

function expCobranza_pacsOk(array $row, $hasSeries, $hasInst, $hasFecha) {
    if ($hasFecha && !empty($row['fecha_enviado_pacs'])) {
        return true;
    }
    if ($hasSeries && trim((string) ($row['pacs_series_id'] ?? '')) !== '') {
        return true;
    }
    if ($hasInst && trim((string) ($row['pacs_instance_id'] ?? '')) !== '') {
        return true;
    }
    return false;
}

function expCobranza_buildUserLabel(array $userData) {
    $fullName = trim((string) (($userData['nombre'] ?? '') . ' ' . ($userData['apellido'] ?? '')));
    if ($fullName !== '') {
        return $fullName;
    }
    foreach (['username', 'usuario', 'name', 'email'] as $k) {
        if (!empty($userData[$k])) {
            return (string) $userData[$k];
        }
    }
    return 'usuario';
}

function expCobranza_slugFilename($value) {
    $v = trim((string) $value);
    if ($v === '') {
        return 'usuario';
    }
    if (function_exists('iconv')) {
        $tmp = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $v);
        if ($tmp !== false) {
            $v = $tmp;
        }
    }
    $v = strtolower($v);
    $v = preg_replace('/[^a-z0-9]+/', '_', $v);
    $v = trim((string) $v, '_');
    return $v !== '' ? $v : 'usuario';
}

function expCobranza_extractAgeYears($ageRaw, $birthRaw) {
    if ($ageRaw !== null && $ageRaw !== '') {
        $ageText = strtoupper(trim((string) $ageRaw));
        if (preg_match('/^(\d{1,3})\s*Y$/', $ageText, $m)) {
            return (int) $m[1];
        }
        if (is_numeric($ageRaw)) {
            $n = (int) $ageRaw;
            if ($n >= 0 && $n <= 130) {
                return $n;
            }
        }
    }

    if ($birthRaw === null || $birthRaw === '') {
        return null;
    }
    $birthRaw = trim((string) $birthRaw);
    $birthDate = null;
    if (preg_match('/^\d{8}$/', $birthRaw)) {
        $birthDate = DateTime::createFromFormat('Ymd', $birthRaw);
    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthRaw)) {
        $birthDate = DateTime::createFromFormat('Y-m-d', $birthRaw);
    }
    if (!$birthDate) {
        return null;
    }
    $today = new DateTime('today');
    $age = (int) $birthDate->diff($today)->y;
    return ($age >= 0 && $age <= 130) ? $age : null;
}

function expCobranza_formatDateDisplay($raw) {
    if ($raw === null) {
        return '';
    }
    $value = trim((string) $raw);
    if ($value === '') {
        return '';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        $dt = DateTime::createFromFormat('Y-m-d', $value);
        return $dt ? $dt->format('d-m-Y') : $value;
    }
    $dt = date_create($value);
    if ($dt) {
        return $dt->format('d-m-Y');
    }
    return $value;
}

try {
    $token = expCobranza_getToken();
    if (!$token) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'No autenticado']);
        exit;
    }

    $user = new User();
    $userData = $user->validateSession($token);
    if (!$userData) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Sesión inválida']);
        exit;
    }

    $perms = $userData['permisos'] ?? [];
    if (!listInformes_userHasPermission($perms, 'datosCobranzaInformes')) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Sin permiso datosCobranzaInformes']);
        exit;
    }

    $db = getDBConnection();
    if (!$db) {
        throw new Exception('Sin BD');
    }

    $user_id = (int) $userData['id'];
    $stmt = $db->prepare('SELECT padre_id FROM usuarios WHERE id = ? AND activo = 1');
    $stmt->execute([$user_id]);
    $pinfo = $stmt->fetch(PDO::FETCH_ASSOC);
    $user_padre_id = $pinfo ? $pinfo['padre_id'] : null;

    $can_view_all = listInformes_userHasPermission($perms, 'verTodosInformes');
    $hierarchyFilter = buildUserHierarchyFilter($db, $user_id, $user_padre_id, $can_view_all);
    $userFilterCondition = $hierarchyFilter['condition'];
    $params = $hierarchyFilter['params'];

    $fecha_inicio = isset($_GET['fecha_inicio']) ? trim((string) $_GET['fecha_inicio']) : '';
    $fecha_fin = isset($_GET['fecha_fin']) ? trim((string) $_GET['fecha_fin']) : '';
    $solo_habilitados = isset($_GET['solo_habilitados']) ? (int) $_GET['solo_habilitados'] : 0;
    $format = isset($_GET['format']) ? strtolower(trim((string) $_GET['format'])) : 'json';
    if ($format === 'xls') {
        $format = 'excel';
    }
    if ($format !== 'csv' && $format !== 'excel' && $format !== 'json') {
        $format = 'json';
    }

    if ($fecha_inicio !== '') {
        $params[':fecha_inicio'] = $fecha_inicio;
    }
    if ($fecha_fin !== '') {
        $params[':fecha_fin'] = $fecha_fin;
    }

    $cols = $db->query('SHOW COLUMNS FROM informes')->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('cobranza_regiones', $cols, true)) {
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Columnas cobranza no instaladas']);
        exit;
    }

    $hasContenidoTexto = in_array('contenido_texto', $cols, true);
    $hasFechaFinalizacion = in_array('fecha_finalizacion', $cols, true);
    $hasPatientAge = in_array('patient_age', $cols, true);
    $hasPatientBirthDate = in_array('patient_birth_date', $cols, true);

    $hasPacsSeriesId = in_array('pacs_series_id', $cols, true);
    $hasPacsInstanceId = in_array('pacs_instance_id', $cols, true);
    $hasFechaEnviadoPacs = in_array('fecha_enviado_pacs', $cols, true);

    $hasStudyFlags = false;
    $sfHasInformeId = false;
    try {
        $t = $db->query("SHOW TABLES LIKE 'study_flags'");
        if ($t && $t->rowCount() > 0) {
            $hasStudyFlags = true;
            $c = $db->query("SHOW COLUMNS FROM study_flags LIKE 'informe_id'");
            $sfHasInformeId = ($c && $c->rowCount() > 0);
        }
    } catch (Throwable $e) {
        $hasStudyFlags = false;
    }

    $whereExtra = '';
    if ($fecha_inicio !== '') {
        $whereExtra .= ' AND DATE(i.fecha_modificacion) >= :fecha_inicio';
    }
    if ($fecha_fin !== '') {
        $whereExtra .= ' AND DATE(i.fecha_modificacion) <= :fecha_fin';
    }

    if ($solo_habilitados === 1) {
        $whereExtra .= " AND i.estado = 'finalizado' AND i.cobranza_regiones IS NOT NULL";
        $pacsParts = [];
        if ($hasFechaEnviadoPacs) {
            $pacsParts[] = 'i.fecha_enviado_pacs IS NOT NULL';
        }
        if ($hasPacsSeriesId) {
            $pacsParts[] = "NULLIF(TRIM(i.pacs_series_id), '') IS NOT NULL";
        }
        if ($hasPacsInstanceId) {
            $pacsParts[] = "NULLIF(TRIM(i.pacs_instance_id), '') IS NOT NULL";
        }
        if (!empty($pacsParts)) {
            $whereExtra .= ' AND (' . implode(' OR ', $pacsParts) . ')';
        } else {
            $whereExtra .= ' AND 1=0';
        }
        if ($hasStudyFlags) {
            if ($sfHasInformeId) {
                $whereExtra .= " AND NOT EXISTS (SELECT 1 FROM study_flags sf WHERE sf.informes_incompletos = 1 AND sf.informe_id = i.id LIMIT 1)";
            }
            $whereExtra .= " AND NOT EXISTS (SELECT 1 FROM study_flags sf2 WHERE sf2.informes_incompletos = 1 AND (sf2.informe_id IS NULL OR sf2.informe_id = 0) AND (sf2.study_id = i.estudio_id OR sf2.study_id = i.study_instance_uid OR sf2.study_id = i.study_id OR sf2.study_instance_uid = i.study_instance_uid OR sf2.study_instance_uid = i.estudio_id OR sf2.orthanc_id = i.study_id OR sf2.orthanc_id = i.estudio_id) LIMIT 1)";
        }
    }

    $select = [
        'i.id',
        'i.usuario_id',
        'i.estudio_id',
        'i.study_instance_uid',
        'i.study_id',
        'i.patient_name',
        'i.modality',
        'i.study_description',
        'i.cobranza_estudio_planilla',
        'i.cobranza_regiones',
        'i.estado',
        'i.fecha_modificacion',
        'i.fecha_creacion',
    ];
    if ($hasFechaFinalizacion) {
        $select[] = 'i.fecha_finalizacion';
    }
    if ($hasPatientAge) {
        $select[] = 'i.patient_age';
    }
    if ($hasPatientBirthDate) {
        $select[] = 'i.patient_birth_date';
    }
    if ($hasContenidoTexto) {
        $select[] = 'SUBSTRING(i.contenido_texto, 1, 900) AS cobranza_diagnostico_resumen';
    }
    if ($hasPacsSeriesId) {
        $select[] = 'i.pacs_series_id';
    }
    if ($hasPacsInstanceId) {
        $select[] = 'i.pacs_instance_id';
    }
    if ($hasFechaEnviadoPacs) {
        $select[] = 'i.fecha_enviado_pacs';
    }

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM informes i WHERE 1=1 ' . $userFilterCondition . $whereExtra
        . ' ORDER BY i.fecha_modificacion DESC, i.id DESC LIMIT 5000';

    $st = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // Intentar usar la fecha real del estudio desde tablas de estudios/asignaciones.
    $studyDateByKey = [];
    $studyKeys = [];
    foreach ($rows as $r) {
        foreach (['estudio_id', 'study_id', 'study_instance_uid'] as $k) {
            $v = trim((string) ($r[$k] ?? ''));
            if ($v !== '') {
                $studyKeys[$v] = true;
            }
        }
    }
    $studyKeyList = array_keys($studyKeys);
    if (!empty($studyKeyList)) {
        try {
            $tbl = $db->query("SHOW TABLES LIKE 'estudios'");
            if ($tbl && $tbl->rowCount() > 0) {
                $c1 = $db->query("SHOW COLUMNS FROM estudios LIKE 'orthanc_study_id'");
                $c2 = $db->query("SHOW COLUMNS FROM estudios LIKE 'study_date'");
                if ($c1 && $c1->rowCount() > 0 && $c2 && $c2->rowCount() > 0) {
                    $ph = implode(',', array_fill(0, count($studyKeyList), '?'));
                    $sq = $db->prepare("SELECT orthanc_study_id, study_date FROM estudios WHERE orthanc_study_id IN ($ph) AND study_date IS NOT NULL");
                    $sq->execute($studyKeyList);
                    foreach ($sq->fetchAll(PDO::FETCH_ASSOC) as $er) {
                        $k = trim((string) ($er['orthanc_study_id'] ?? ''));
                        $d = trim((string) ($er['study_date'] ?? ''));
                        if ($k !== '' && $d !== '' && !isset($studyDateByKey[$k])) {
                            $studyDateByKey[$k] = $d;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // ignorar lookup opcional
        }
        try {
            $tbl = $db->query("SHOW TABLES LIKE 'study_assignments'");
            if ($tbl && $tbl->rowCount() > 0) {
                $hasOrth = $db->query("SHOW COLUMNS FROM study_assignments LIKE 'orthanc_study_id'");
                $hasUid = $db->query("SHOW COLUMNS FROM study_assignments LIKE 'study_instance_uid'");
                $hasDate = $db->query("SHOW COLUMNS FROM study_assignments LIKE 'study_date'");
                if ($hasDate && $hasDate->rowCount() > 0 && (($hasOrth && $hasOrth->rowCount() > 0) || ($hasUid && $hasUid->rowCount() > 0))) {
                    $ph = implode(',', array_fill(0, count($studyKeyList), '?'));
                    $parts = [];
                    if ($hasOrth && $hasOrth->rowCount() > 0) {
                        $parts[] = "orthanc_study_id IN ($ph)";
                    }
                    if ($hasUid && $hasUid->rowCount() > 0) {
                        $parts[] = "study_instance_uid IN ($ph)";
                    }
                    $sqlDates = "SELECT orthanc_study_id, study_instance_uid, study_date FROM study_assignments WHERE study_date IS NOT NULL AND (" . implode(' OR ', $parts) . ")";
                    $dateParams = [];
                    if ($hasOrth && $hasOrth->rowCount() > 0) {
                        $dateParams = array_merge($dateParams, $studyKeyList);
                    }
                    if ($hasUid && $hasUid->rowCount() > 0) {
                        $dateParams = array_merge($dateParams, $studyKeyList);
                    }
                    $sq = $db->prepare($sqlDates);
                    $sq->execute($dateParams);
                    foreach ($sq->fetchAll(PDO::FETCH_ASSOC) as $ar) {
                        $d = trim((string) ($ar['study_date'] ?? ''));
                        if ($d === '') {
                            continue;
                        }
                        foreach (['orthanc_study_id', 'study_instance_uid'] as $kcol) {
                            $k = trim((string) ($ar[$kcol] ?? ''));
                            if ($k !== '' && !isset($studyDateByKey[$k])) {
                                $studyDateByKey[$k] = $d;
                            }
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // ignorar lookup opcional
        }
    }

    $modalitiesSet = [];
    foreach ($rows as $r) {
        $m = strtoupper(trim((string) ($r['modality'] ?? '')));
        if ($m !== '') {
            $modalitiesSet[$m] = true;
        }
    }
    $modalities = array_keys($modalitiesSet);
    sort($modalities);

    $processed = [];
    $totals = [];
    foreach ($modalities as $m) {
        $totals[$m] = 0;
    }

    foreach ($rows as $r) {
        $m = strtoupper(trim((string) ($r['modality'] ?? '')));
        $n = isset($r['cobranza_regiones']) && $r['cobranza_regiones'] !== null && $r['cobranza_regiones'] !== ''
            ? (int) $r['cobranza_regiones'] : null;

        $pacsOk = expCobranza_pacsOk($r, $hasPacsSeriesId, $hasPacsInstanceId, $hasFechaEnviadoPacs);
        $datosOk = $n !== null;
        $finalizado = (($r['estado'] ?? '') === 'finalizado');
        $informeInc = false;
        $estudioInc = false;
        if ($hasStudyFlags) {
            try {
                if ($sfHasInformeId) {
                    $q1 = $db->prepare('SELECT 1 FROM study_flags WHERE informes_incompletos = 1 AND informe_id = ? LIMIT 1');
                    $q1->execute([(int) $r['id']]);
                    $informeInc = (bool) $q1->fetchColumn();
                }
                $q2 = $db->prepare('SELECT 1 FROM study_flags WHERE informes_incompletos = 1 AND informe_id IS NULL AND (study_id = ? OR study_id = ? OR study_id = ? OR study_instance_uid = ? OR orthanc_id = ? OR orthanc_id = ?) LIMIT 1');
                $q2->execute([
                    $r['estudio_id'] ?? '',
                    $r['study_instance_uid'] ?? '',
                    $r['study_id'] ?? '',
                    $r['study_instance_uid'] ?? '',
                    $r['study_id'] ?? '',
                    $r['estudio_id'] ?? '',
                ]);
                $estudioInc = (bool) $q2->fetchColumn();
            } catch (Throwable $e) {
                // ignorar
            }
        }
        $incompleto = $informeInc || $estudioInc;
        $listoExport = $finalizado && $datosOk && $pacsOk && !$incompleto;

        $codigosPorMod = [];
        foreach ($modalities as $mod) {
            $codigosPorMod[$mod] = ($mod === $m && $n !== null) ? $n : 0;
        }
        if ($m !== '' && $n !== null) {
            if (!isset($totals[$m])) {
                $totals[$m] = 0;
            }
            $totals[$m] += $n;
        }

        $fechaRaw = '';
        foreach (['estudio_id', 'study_id', 'study_instance_uid'] as $k) {
            $lookupKey = trim((string) ($r[$k] ?? ''));
            if ($lookupKey !== '' && isset($studyDateByKey[$lookupKey])) {
                $fechaRaw = $studyDateByKey[$lookupKey];
                break;
            }
        }
        if ($fechaRaw === '') {
            $fechaRaw = $r['fecha_finalizacion'] ?? $r['fecha_modificacion'] ?? $r['fecha_creacion'] ?? '';
        }
        $fechaDisplay = expCobranza_formatDateDisplay($fechaRaw);
        $patientAge = expCobranza_extractAgeYears($r['patient_age'] ?? null, $r['patient_birth_date'] ?? null);

        $processed[] = [
            'id' => (int) $r['id'],
            'usuario_id' => isset($r['usuario_id']) ? (int) $r['usuario_id'] : null,
            'fecha' => $fechaDisplay,
            'patient_name' => $r['patient_name'],
            'patient_age' => $patientAge,
            'modality' => $m,
            'study_description_pacs' => $r['study_description'],
            'estudio_planilla' => $r['cobranza_estudio_planilla'],
            'diagnostico_resumen' => $r['cobranza_diagnostico_resumen'] ?? '',
            'cobranza_regiones' => $n,
            'codigos_por_modalidad' => $codigosPorMod,
            'estado' => $r['estado'],
            'flags' => [
                'finalizado' => $finalizado,
                'datos_cobranza_ok' => $datosOk,
                'pacs_ok' => $pacsOk,
                'informe_incompleto' => $informeInc,
                'estudio_incompleto' => $estudioInc,
                'listo_export' => $listoExport,
            ],
        ];
    }

    $grandTotal = array_sum($totals);
    $userLabel = expCobranza_buildUserLabel($userData);
    if (function_exists('mb_strtoupper')) {
        $userLabel = mb_strtoupper($userLabel, 'UTF-8');
    } else {
        $userLabel = strtoupper($userLabel);
    }
    $periodoInicio = $fecha_inicio !== '' ? expCobranza_formatDateDisplay($fecha_inicio) : 'sin inicio';
    $periodoFin = $fecha_fin !== '' ? expCobranza_formatDateDisplay($fecha_fin) : 'sin fin';
    $periodoLabel = $periodoInicio . ' / ' . $periodoFin;
    $filenameBase = expCobranza_slugFilename($userLabel) . '_planilla_codigos_' . date('d-m-Y');

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filenameBase . '.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        $head = array_merge(
            ['FECHA', 'PACIENTE', 'EDAD', 'MODALIDAD', 'ESTUDIO_PACS', 'ESTUDIO_PLANILLA', 'DIAGNOSTICO'],
            array_map(function ($mod) {
                return 'CODIGOS ' . $mod;
            }, $modalities)
        );
        fputcsv($out, ['USUARIO', $userLabel], ';');
        fputcsv($out, ['PERIODO', $periodoLabel], ';');
        fputcsv($out, [], ';');
        fputcsv($out, $head, ';');
        foreach ($processed as $p) {
            $line = [
                $p['fecha'],
                $p['patient_name'],
                $p['patient_age'] !== null ? (string) $p['patient_age'] : '',
                $p['modality'],
                $p['study_description_pacs'],
                $p['estudio_planilla'],
                $p['diagnostico_resumen'],
            ];
            foreach ($modalities as $mod) {
                $line[] = (string) ($p['codigos_por_modalidad'][$mod] ?? 0);
            }
            fputcsv($out, $line, ';');
        }
        $sumLine = array_merge(
            ['TOTAL', '', '', '', '', '', ''],
            array_map(function ($mod) use ($totals) {
                return (string) ($totals[$mod] ?? 0);
            }, $modalities)
        );
        fputcsv($out, $sumLine, ';');
        fclose($out);
        exit;
    }

    if ($format === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filenameBase . '.xls"');

        $xmlEsc = static function ($v) {
            return htmlspecialchars((string) ($v ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        };
        $cell = static function ($v, $type = 'String') use ($xmlEsc) {
            return '<Cell><Data ss:Type="' . $type . '">' . $xmlEsc($v) . '</Data></Cell>';
        };

        $headers = array_merge(
            ['FECHA', 'PACIENTE', 'EDAD', 'MODALIDAD', 'ESTUDIO_PACS', 'ESTUDIO_PLANILLA', 'DIAGNOSTICO'],
            array_map(function ($mod) {
                return 'CODIGOS ' . $mod;
            }, $modalities)
        );

        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<?mso-application progid="Excel.Sheet"?>';
        echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" ';
        echo 'xmlns:o="urn:schemas-microsoft-com:office:office" ';
        echo 'xmlns:x="urn:schemas-microsoft-com:office:excel" ';
        echo 'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet" ';
        echo 'xmlns:html="http://www.w3.org/TR/REC-html40">';
        echo '<Styles>';
        echo '<Style ss:ID="Header"><Font ss:Bold="1"/></Style>';
        echo '<Style ss:ID="Total"><Font ss:Bold="1"/></Style>';
        echo '</Styles>';
        echo '<Worksheet ss:Name="Planilla">';
        echo '<Table>';

        echo '<Row>' . $cell('Usuario', 'String') . $cell($userLabel, 'String') . '</Row>';
        echo '<Row>' . $cell('Período', 'String') . $cell($periodoLabel, 'String') . '</Row>';
        echo '<Row/>';

        echo '<Row>';
        foreach ($headers as $h) {
            echo '<Cell ss:StyleID="Header"><Data ss:Type="String">' . $xmlEsc($h) . '</Data></Cell>';
        }
        echo '</Row>';

        foreach ($processed as $p) {
            echo '<Row>';
            echo $cell($p['fecha'], 'String');
            echo $cell($p['patient_name'], 'String');
            if ($p['patient_age'] !== null) {
                echo $cell((string) $p['patient_age'], 'Number');
            } else {
                echo $cell('', 'String');
            }
            echo $cell($p['modality'], 'String');
            echo $cell($p['study_description_pacs'], 'String');
            echo $cell($p['estudio_planilla'], 'String');
            echo $cell($p['diagnostico_resumen'], 'String');
            foreach ($modalities as $mod) {
                echo $cell((string) ($p['codigos_por_modalidad'][$mod] ?? 0), 'Number');
            }
            echo '</Row>';
        }

        echo '<Row>';
        echo '<Cell ss:StyleID="Total"><Data ss:Type="String">TOTAL</Data></Cell>';
        for ($i = 0; $i < 6; $i++) {
            echo '<Cell ss:StyleID="Total"><Data ss:Type="String"></Data></Cell>';
        }
        foreach ($modalities as $mod) {
            echo '<Cell ss:StyleID="Total"><Data ss:Type="Number">' . (int) ($totals[$mod] ?? 0) . '</Data></Cell>';
        }
        echo '</Row>';

        echo '</Table></Worksheet></Workbook>';
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'data' => [
            'rows' => $processed,
            'modalities' => $modalities,
            'totals_by_modality' => $totals,
            'grand_total_regiones' => $grandTotal,
            'solo_habilitados' => $solo_habilitados === 1,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[export-planilla-cobranza] ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Error interno']);
}
