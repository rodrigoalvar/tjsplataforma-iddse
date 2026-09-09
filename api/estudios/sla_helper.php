<?php
/**
 * SLA estudios recibidos: llegada local, timeline y resolución de horas.
 */

if (!function_exists('sla_config_value')) {
    function sla_config_value(PDO $db, string $key, string $default = ''): string
    {
        static $cache = [];
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        try {
            $st = $db->prepare('SELECT valor FROM configuracion WHERE clave = ? LIMIT 1');
            $st->execute([$key]);
            $v = $st->fetchColumn();
            $cache[$key] = ($v === false || $v === null) ? $default : (string)$v;
        } catch (Throwable $e) {
            $cache[$key] = $default;
        }
        return $cache[$key];
    }
}

if (!function_exists('sla_is_feature_active')) {
    function sla_is_feature_active(PDO $db): bool
    {
        return sla_config_value($db, 'sla_activo', '0') === '1';
    }
}

if (!function_exists('sla_default_hours')) {
    function sla_default_hours(PDO $db): int
    {
        $h = (int)sla_config_value($db, 'sla_default_horas', '72');
        return $h > 0 ? $h : 72;
    }
}

if (!function_exists('sla_warning_hours')) {
    function sla_warning_hours(PDO $db): int
    {
        $h = (int)sla_config_value($db, 'sla_warning_horas', '24');
        return $h >= 0 ? $h : 24;
    }
}

if (!function_exists('sla_estudios_columns_ready')) {
    function sla_estudios_columns_ready(PDO $db): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        try {
            $st = $db->query("SHOW COLUMNS FROM estudios LIKE 'local_arrived_at'");
            $ok = $st && $st->rowCount() > 0;
        } catch (Throwable $e) {
            $ok = false;
        }
        return $ok;
    }
}

if (!function_exists('sla_timeline_table_ready')) {
    function sla_timeline_table_ready(PDO $db): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        try {
            $st = $db->query("SHOW TABLES LIKE 'study_informe_timeline'");
            $ok = $st && $st->rowCount() > 0;
        } catch (Throwable $e) {
            $ok = false;
        }
        return $ok;
    }
}

if (!function_exists('sla_find_estudio_id')) {
    /**
     * @param array{estudios_id?:int|string,estudio_id?:int|string,id?:int|string,orthanc_study_id?:string,study_instance_uid?:string,study_id?:string} $keys
     */
    function sla_find_estudio_id(PDO $db, array $keys): ?int
    {
        foreach (['estudios_id', 'estudio_id', 'id'] as $k) {
            if (!empty($keys[$k]) && ctype_digit((string)$keys[$k])) {
                $id = (int)$keys[$k];
                if ($id > 0) {
                    return $id;
                }
            }
        }
        $orthanc = trim((string)($keys['orthanc_study_id'] ?? $keys['study_id'] ?? ''));
        $uid = trim((string)($keys['study_instance_uid'] ?? ''));
        if ($orthanc !== '') {
            $st = $db->prepare('SELECT id FROM estudios WHERE orthanc_study_id = ? LIMIT 1');
            $st->execute([$orthanc]);
            $id = $st->fetchColumn();
            if ($id) {
                return (int)$id;
            }
            // orthanc id a veces guardado como study_id string en otras tablas
            if (ctype_digit($orthanc)) {
                $st = $db->prepare('SELECT id FROM estudios WHERE id = ? LIMIT 1');
                $st->execute([(int)$orthanc]);
                $id = $st->fetchColumn();
                if ($id) {
                    return (int)$id;
                }
            }
        }
        if ($uid !== '') {
            $st = $db->prepare('SELECT id FROM estudios WHERE study_instance_uid = ? LIMIT 1');
            $st->execute([$uid]);
            $id = $st->fetchColumn();
            if ($id) {
                return (int)$id;
            }
        }
        return null;
    }
}

if (!function_exists('sla_record_event')) {
    /**
     * Inserta hito idempotente (UNIQUE estudios_id+evento). No sobrescribe occurred_at existente.
     */
    function sla_record_event(
        PDO $db,
        int $estudiosId,
        string $evento,
        ?string $occurredAt = null,
        ?string $source = null,
        ?string $refId = null,
        ?array $meta = null
    ): bool {
        if ($estudiosId <= 0 || $evento === '') {
            return false;
        }
        if (!sla_timeline_table_ready($db)) {
            return false;
        }
        $at = $occurredAt ?: date('Y-m-d H:i:s');
        $uid = null;
        $orthanc = null;
        try {
            $st = $db->prepare('SELECT study_instance_uid, orthanc_study_id FROM estudios WHERE id = ? LIMIT 1');
            $st->execute([$estudiosId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $uid = $row['study_instance_uid'] ?: null;
                $orthanc = $row['orthanc_study_id'] ?: null;
            }
        } catch (Throwable $e) {
            /* ignore */
        }
        $metaJson = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;
        try {
            $sql = "INSERT IGNORE INTO study_informe_timeline
                (estudios_id, study_instance_uid, orthanc_study_id, evento, occurred_at, source, ref_id, meta_json)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $st = $db->prepare($sql);
            $st->execute([$estudiosId, $uid, $orthanc, $evento, $at, $source, $refId, $metaJson]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) {
            error_log('[SLA] record_event: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('sla_mark_local_arrived')) {
    /**
     * Marca primera llegada a PACS local (idempotente) + evento arrived.
     * @param array $keys ver sla_find_estudio_id
     * @return array{ok:bool,estudios_id:?int,created_row:bool,updated:bool}
     */
    function sla_mark_local_arrived(PDO $db, array $keys, ?string $at = null, string $source = 'system'): array
    {
        $result = ['ok' => false, 'estudios_id' => null, 'created_row' => false, 'updated' => false];
        if (!sla_estudios_columns_ready($db)) {
            return $result;
        }
        $at = $at ?: date('Y-m-d H:i:s');
        $id = sla_find_estudio_id($db, $keys);
        $orthanc = trim((string)($keys['orthanc_study_id'] ?? $keys['study_id'] ?? ''));
        $uid = trim((string)($keys['study_instance_uid'] ?? ''));
        $modality = trim((string)($keys['modality'] ?? ''));
        $patientId = trim((string)($keys['patient_id'] ?? $keys['patient_id_pacs'] ?? ''));
        $patientName = trim((string)($keys['patient_name'] ?? $keys['patient_name_pacs'] ?? ''));

        if ($id === null) {
            if ($orthanc === '' && $uid === '') {
                return $result;
            }
            try {
                $ins = $db->prepare("
                    INSERT INTO estudios (
                        orthanc_study_id, patient_id_pacs, patient_name_pacs, modality,
                        study_instance_uid, status, fecha_creacion, local_arrived_at
                    ) VALUES (?, ?, ?, ?, ?, 'COMPLETADO', ?, ?)
                ");
                $ins->execute([
                    $orthanc !== '' ? $orthanc : null,
                    $patientId !== '' ? $patientId : null,
                    $patientName !== '' ? $patientName : null,
                    $modality !== '' ? $modality : null,
                    $uid !== '' ? $uid : null,
                    $at,
                    $at,
                ]);
                $id = (int)$db->lastInsertId();
                $result['created_row'] = true;
            } catch (Throwable $e) {
                // carrera / columnas: reintentar find
                error_log('[SLA] create estudio on arrived: ' . $e->getMessage());
                $id = sla_find_estudio_id($db, $keys);
            }
        }

        if (!$id) {
            return $result;
        }
        $result['estudios_id'] = $id;

        try {
            $upd = $db->prepare('UPDATE estudios SET local_arrived_at = ? WHERE id = ? AND local_arrived_at IS NULL');
            $upd->execute([$at, $id]);
            $result['updated'] = $upd->rowCount() > 0;
        } catch (Throwable $e) {
            error_log('[SLA] mark arrived: ' . $e->getMessage());
            return $result;
        }

        // Si ya tenía arrived, usar el existente para el evento
        $occ = $at;
        try {
            $st = $db->prepare('SELECT local_arrived_at FROM estudios WHERE id = ?');
            $st->execute([$id]);
            $existing = $st->fetchColumn();
            if ($existing) {
                $occ = (string)$existing;
            }
        } catch (Throwable $e) {
            /* ignore */
        }

        sla_record_event($db, $id, 'arrived', $occ, $source);
        $result['ok'] = true;
        return $result;
    }
}

if (!function_exists('sla_resolve_hours_for_estudio')) {
    /**
     * Prioridad: override estudio → plantilla modalidad → default config.
     * @return array{horas:int,fuente:string,plantilla_id:?int}
     */
    function sla_resolve_hours_for_estudio(PDO $db, array $estudioRow): array
    {
        $default = sla_default_hours($db);
        if (isset($estudioRow['sla_override_horas']) && $estudioRow['sla_override_horas'] !== null && $estudioRow['sla_override_horas'] !== '') {
            $oh = (int)$estudioRow['sla_override_horas'];
            if ($oh > 0) {
                return ['horas' => $oh, 'fuente' => 'override', 'plantilla_id' => null];
            }
        }
        $mod = strtoupper(trim((string)($estudioRow['modality'] ?? '')));
        try {
            $st = $db->query("SHOW TABLES LIKE 'sla_plantillas'");
            if ($st && $st->rowCount() > 0) {
                $q = $db->query('SELECT id, horas, modalidades FROM sla_plantillas WHERE activo = 1 ORDER BY prioridad ASC, id ASC');
                while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                    $mods = trim((string)($row['modalidades'] ?? ''));
                    if ($mods === '') {
                        return ['horas' => max(1, (int)$row['horas']), 'fuente' => 'plantilla', 'plantilla_id' => (int)$row['id']];
                    }
                    $list = array_filter(array_map(static function ($x) {
                        return strtoupper(trim($x));
                    }, preg_split('/[,;\\s]+/', $mods) ?: []));
                    if ($mod !== '' && in_array($mod, $list, true)) {
                        return ['horas' => max(1, (int)$row['horas']), 'fuente' => 'plantilla', 'plantilla_id' => (int)$row['id']];
                    }
                }
            }
        } catch (Throwable $e) {
            /* ignore */
        }
        return ['horas' => $default, 'fuente' => 'default', 'plantilla_id' => null];
    }
}

if (!function_exists('sla_estudio_is_published')) {
    function sla_estudio_is_published(PDO $db, int $estudiosId, ?string $orthancId = null, ?string $uid = null): bool
    {
        $conds = [];
        $params = [];
        $conds[] = '(i.estudio_id = ? OR i.study_id = ?)';
        $params[] = (string)$estudiosId;
        $params[] = (string)$estudiosId;
        if ($orthancId) {
            $conds[] = '(i.estudio_id = ? OR i.study_id = ?)';
            $params[] = $orthancId;
            $params[] = $orthancId;
        }
        if ($uid) {
            $conds[] = '(i.study_instance_uid = ?)';
            $params[] = $uid;
        }
        $where = '(' . implode(' OR ', $conds) . ')';
        $sql = "SELECT 1 FROM informes i
            WHERE $where
              AND (
                (i.pacs_series_id IS NOT NULL AND i.pacs_series_id <> '')
                OR (i.pacs_instance_id IS NOT NULL AND i.pacs_instance_id <> '')
                OR i.fecha_enviado_pacs IS NOT NULL
              )
            LIMIT 1";
        try {
            $st = $db->prepare($sql);
            $st->execute($params);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('sla_try_mark_dictated')) {
    /**
     * Dictado = MIN(primer audio, primer informe). Idempotente.
     */
    function sla_try_mark_dictated(PDO $db, int $estudiosId): void
    {
        if ($estudiosId <= 0 || !sla_timeline_table_ready($db)) {
            return;
        }
        $audioAt = null;
        $audioId = null;
        $informeAt = null;
        $informeId = null;

        try {
            $row = $db->prepare('SELECT orthanc_study_id, study_instance_uid FROM estudios WHERE id = ?');
            $row->execute([$estudiosId]);
            $est = $row->fetch(PDO::FETCH_ASSOC) ?: [];
            $orthanc = (string)($est['orthanc_study_id'] ?? '');
            $uid = (string)($est['study_instance_uid'] ?? '');

            // Audios: tabla audios con estudio_id / orthanc / informe
            $hasAudios = $db->query("SHOW TABLES LIKE 'audios'");
            if ($hasAudios && $hasAudios->rowCount() > 0) {
                $parts = ['estudio_id = ?'];
                $p = [(string)$estudiosId];
                if ($orthanc !== '') {
                    $parts[] = 'estudio_id = ? OR orthanc_study_id = ?';
                    $p[] = $orthanc;
                    $p[] = $orthanc;
                }
                // Simplificar consulta
                $sqlA = "SELECT id, fecha_creacion FROM audios
                         WHERE estudio_id IN (?, ?) OR orthanc_study_id = ?
                         ORDER BY fecha_creacion ASC LIMIT 1";
                try {
                    $stA = $db->prepare($sqlA);
                    $stA->execute([(string)$estudiosId, $orthanc !== '' ? $orthanc : (string)$estudiosId, $orthanc]);
                    $a = $stA->fetch(PDO::FETCH_ASSOC);
                    if ($a && !empty($a['fecha_creacion'])) {
                        $audioAt = $a['fecha_creacion'];
                        $audioId = (string)$a['id'];
                    }
                } catch (Throwable $e) {
                    // columnas alternativas
                    try {
                        $stA = $db->prepare('SELECT id, fecha_creacion FROM audios WHERE estudio_id = ? ORDER BY fecha_creacion ASC LIMIT 1');
                        $stA->execute([(string)$estudiosId]);
                        $a = $stA->fetch(PDO::FETCH_ASSOC);
                        if ($a && !empty($a['fecha_creacion'])) {
                            $audioAt = $a['fecha_creacion'];
                            $audioId = (string)$a['id'];
                        }
                    } catch (Throwable $e2) {
                        /* ignore */
                    }
                }
            }

            $sqlI = "SELECT id, fecha_creacion FROM informes
                     WHERE estudio_id = ? OR study_id = ? OR estudio_id = ? OR study_id = ?
                        OR (study_instance_uid IS NOT NULL AND study_instance_uid <> '' AND study_instance_uid = ?)
                     ORDER BY fecha_creacion ASC LIMIT 1";
            $stI = $db->prepare($sqlI);
            $stI->execute([
                (string)$estudiosId,
                (string)$estudiosId,
                $orthanc !== '' ? $orthanc : (string)$estudiosId,
                $orthanc !== '' ? $orthanc : (string)$estudiosId,
                $uid !== '' ? $uid : '__none__',
            ]);
            $inf = $stI->fetch(PDO::FETCH_ASSOC);
            if ($inf && !empty($inf['fecha_creacion'])) {
                $informeAt = $inf['fecha_creacion'];
                $informeId = (string)$inf['id'];
            }
        } catch (Throwable $e) {
            error_log('[SLA] try_mark_dictated: ' . $e->getMessage());
            return;
        }

        if (!$audioAt && !$informeAt) {
            return;
        }
        $useAudio = false;
        if ($audioAt && $informeAt) {
            $useAudio = (strtotime($audioAt) <= strtotime($informeAt));
        } elseif ($audioAt) {
            $useAudio = true;
        }
        if ($useAudio) {
            sla_record_event($db, $estudiosId, 'dictated', $audioAt, 'audio', $audioId);
        } else {
            sla_record_event($db, $estudiosId, 'dictated', $informeAt, 'informe', $informeId);
        }
    }
}

if (!function_exists('sla_mark_assigned_for_study')) {
    function sla_mark_assigned_for_study(PDO $db, $studyId, ?string $assignedAt = null, ?string $orthancId = null, ?string $uid = null): void
    {
        $keys = [
            'study_id' => (string)$studyId,
            'orthanc_study_id' => $orthancId ?: (string)$studyId,
            'study_instance_uid' => $uid,
        ];
        if (ctype_digit((string)$studyId)) {
            $keys['estudios_id'] = (int)$studyId;
        }
        $id = sla_find_estudio_id($db, $keys);
        if (!$id) {
            return;
        }
        sla_record_event($db, $id, 'assigned', $assignedAt ?: date('Y-m-d H:i:s'), 'assignment');
    }
}

if (!function_exists('sla_mark_informe_estado_event')) {
    function sla_mark_informe_estado_event(PDO $db, array $informeRow, string $estado): void
    {
        $map = [
            'transcripto' => 'transcripto',
            'firmado' => 'firmado',
        ];
        if (!isset($map[$estado])) {
            return;
        }
        $keys = [
            'estudios_id' => $informeRow['estudios_pk'] ?? null,
            'orthanc_study_id' => $informeRow['estudio_id'] ?? $informeRow['study_id'] ?? null,
            'study_id' => $informeRow['study_id'] ?? $informeRow['estudio_id'] ?? null,
            'study_instance_uid' => $informeRow['study_instance_uid'] ?? null,
        ];
        $id = sla_find_estudio_id($db, $keys);
        if (!$id && !empty($informeRow['estudio_id']) && ctype_digit((string)$informeRow['estudio_id'])) {
            $id = (int)$informeRow['estudio_id'];
        }
        if (!$id) {
            return;
        }
        $at = null;
        if ($estado === 'firmado' && !empty($informeRow['firmado_en'])) {
            $at = $informeRow['firmado_en'];
        }
        $source = $estado === 'firmado' ? 'sign' : 'informe';
        sla_record_event($db, $id, $map[$estado], $at, $source, isset($informeRow['id']) ? (string)$informeRow['id'] : null);
        sla_try_mark_dictated($db, $id);
    }
}

if (!function_exists('sla_mark_publicado_for_informe')) {
    function sla_mark_publicado_for_informe(PDO $db, array $informeRow): void
    {
        $keys = [
            'orthanc_study_id' => $informeRow['estudio_id'] ?? $informeRow['study_id'] ?? null,
            'study_id' => $informeRow['study_id'] ?? $informeRow['estudio_id'] ?? null,
            'study_instance_uid' => $informeRow['study_instance_uid'] ?? null,
        ];
        $id = sla_find_estudio_id($db, $keys);
        if (!$id && !empty($informeRow['estudio_id']) && ctype_digit((string)$informeRow['estudio_id'])) {
            $id = (int)$informeRow['estudio_id'];
        }
        if (!$id) {
            return;
        }
        $at = $informeRow['fecha_enviado_pacs'] ?? date('Y-m-d H:i:s');
        sla_record_event($db, $id, 'publicado', $at, 'pacs', isset($informeRow['id']) ? (string)$informeRow['id'] : null);
    }
}
