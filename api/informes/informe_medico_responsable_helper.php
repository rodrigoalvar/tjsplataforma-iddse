<?php
/**
 * Resolución del médico responsable de un informe / estudio.
 * Usado al vincular recibidos, adjuntar PDF y sincronizar dueño al asignar estudios.
 */

if (!function_exists('ir_resolve_medico_responsable_estudio')) {

    /**
     * @param PDO $db
     * @param array $studyKeys claves posibles: estudio_id (PK o orthanc), study_id, study_instance_uid, orthanc_study_id
     * @return array{usuario_id:?int,medico_informante_rol:?string,sin_medico_asignado:bool,source:?string}
     */
    function ir_resolve_medico_responsable_estudio(PDO $db, array $studyKeys): array
    {
        $keys = [];
        foreach (['estudio_id', 'study_id', 'study_instance_uid', 'orthanc_study_id'] as $k) {
            $v = trim((string)($studyKeys[$k] ?? ''));
            if ($v !== '') {
                $keys[] = $v;
            }
        }
        $keys = array_values(array_unique($keys));
        if ($keys === []) {
            return [
                'usuario_id' => null,
                'medico_informante_rol' => null,
                'sin_medico_asignado' => true,
                'source' => null,
            ];
        }

        // 1) Informe de plataforma previo (primer informe del estudio)
        try {
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $params = array_merge($keys, $keys, $keys);
            $sql = "SELECT i.usuario_id, i.medico_informante_rol, u.rol AS user_rol
                    FROM informes i
                    LEFT JOIN usuarios u ON u.id = i.usuario_id
                    WHERE (i.estudio_id IN ($placeholders)
                       OR i.study_instance_uid IN ($placeholders)
                       OR i.study_id IN ($placeholders))
                      AND i.usuario_id IS NOT NULL
                      AND i.usuario_id > 0
                    ORDER BY
                      CASE WHEN COALESCE(i.origen, 'plataforma') = 'plataforma' THEN 0 ELSE 1 END,
                      i.fecha_creacion ASC, i.id ASC
                    LIMIT 1";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && (int)$row['usuario_id'] > 0) {
                // Evitar heredar el primer usuario Admin técnico si es el único y es externo
                $firstUserId = (int)$db->query('SELECT id FROM usuarios ORDER BY id ASC LIMIT 1')->fetchColumn();
                $uid = (int)$row['usuario_id'];
                if (!($uid === $firstUserId && ($row['user_rol'] ?? '') !== 'medico_informante')) {
                    $rol = $row['medico_informante_rol'] ?: ($row['user_rol'] ?? null);
                    return [
                        'usuario_id' => $uid,
                        'medico_informante_rol' => $rol,
                        'sin_medico_asignado' => false,
                        'source' => 'informe_previo',
                    ];
                }
            }
        } catch (Throwable $e) {
            error_log('[IR_RESOLVE_MEDICO] informe previo: ' . $e->getMessage());
        }

        // 2) study_assignments activas
        try {
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $params = array_merge($keys, $keys, $keys);
            $sql = "SELECT sa.user_id, u.rol AS user_rol, sa.assigned_date, sa.id
                    FROM study_assignments sa
                    INNER JOIN usuarios u ON u.id = sa.user_id
                    WHERE sa.status = 'active'
                      AND (
                        sa.study_id IN ($placeholders)
                        OR (sa.study_instance_uid IS NOT NULL AND sa.study_instance_uid <> '' AND sa.study_instance_uid IN ($placeholders))
                        OR (sa.orthanc_study_id IS NOT NULL AND sa.orthanc_study_id <> '' AND sa.orthanc_study_id IN ($placeholders))
                      )
                    ORDER BY
                      CASE WHEN u.rol = 'medico_informante' THEN 0 ELSE 1 END,
                      sa.assigned_date ASC,
                      sa.id ASC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) {
                $pick = $rows[0];
                return [
                    'usuario_id' => (int)$pick['user_id'],
                    'medico_informante_rol' => $pick['user_rol'] ?? 'medico_informante',
                    'sin_medico_asignado' => false,
                    'source' => 'study_assignments',
                ];
            }
        } catch (Throwable $e) {
            error_log('[IR_RESOLVE_MEDICO] assignments: ' . $e->getMessage());
        }

        // 3) study_subassignments activas
        try {
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $sql = "SELECT ss.main_user_id, ss.subassigned_to_user_id,
                           um.rol AS main_rol, us.rol AS sub_rol
                    FROM study_subassignments ss
                    LEFT JOIN usuarios um ON um.id = ss.main_user_id
                    LEFT JOIN usuarios us ON us.id = ss.subassigned_to_user_id
                    WHERE ss.status = 'active'
                      AND ss.study_id IN ($placeholders)
                    ORDER BY ss.subassigned_at ASC, ss.id ASC
                    LIMIT 1";
            $stmt = $db->prepare($sql);
            $stmt->execute($keys);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $mainId = (int)($row['main_user_id'] ?? 0);
                $subId = (int)($row['subassigned_to_user_id'] ?? 0);
                if ($mainId > 0) {
                    return [
                        'usuario_id' => $mainId,
                        'medico_informante_rol' => $row['main_rol'] ?? 'medico_informante',
                        'sin_medico_asignado' => false,
                        'source' => 'study_subassignments_main',
                    ];
                }
                if ($subId > 0) {
                    return [
                        'usuario_id' => $subId,
                        'medico_informante_rol' => $row['sub_rol'] ?? 'medico_informante',
                        'sin_medico_asignado' => false,
                        'source' => 'study_subassignments_sub',
                    ];
                }
            }
        } catch (Throwable $e) {
            error_log('[IR_RESOLVE_MEDICO] subassignments: ' . $e->getMessage());
        }

        return [
            'usuario_id' => null,
            'medico_informante_rol' => null,
            'sin_medico_asignado' => true,
            'source' => null,
        ];
    }

    /**
     * ¿Puede el usuario firmar este informe? (dueño o asignado/subasignado del estudio)
     */
    function ir_usuario_puede_firmar_informe(PDO $db, array $informe, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        if ((int)($informe['usuario_id'] ?? 0) === $userId) {
            return true;
        }
        $keys = [
            'estudio_id' => $informe['estudio_id'] ?? null,
            'study_id' => $informe['study_id'] ?? null,
            'study_instance_uid' => $informe['study_instance_uid'] ?? null,
            'orthanc_study_id' => $informe['estudio_id'] ?? null,
        ];
        $vals = [];
        foreach ($keys as $v) {
            $v = trim((string)$v);
            if ($v !== '') {
                $vals[] = $v;
            }
        }
        $vals = array_values(array_unique($vals));
        if ($vals === []) {
            return false;
        }
        try {
            $ph = implode(',', array_fill(0, count($vals), '?'));
            $params = array_merge([$userId], $vals, $vals, $vals);
            $sql = "SELECT 1 FROM study_assignments
                    WHERE status = 'active' AND user_id = ?
                      AND (
                        study_id IN ($ph)
                        OR (study_instance_uid IS NOT NULL AND study_instance_uid <> '' AND study_instance_uid IN ($ph))
                        OR (orthanc_study_id IS NOT NULL AND orthanc_study_id <> '' AND orthanc_study_id IN ($ph))
                      )
                    LIMIT 1";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            if ($stmt->fetchColumn()) {
                return true;
            }
            $params2 = array_merge([$userId, $userId], $vals);
            $sql2 = "SELECT 1 FROM study_subassignments
                     WHERE status = 'active'
                       AND (main_user_id = ? OR subassigned_to_user_id = ?)
                       AND study_id IN ($ph)
                     LIMIT 1";
            $stmt2 = $db->prepare($sql2);
            $stmt2->execute($params2);
            return (bool)$stmt2->fetchColumn();
        } catch (Throwable $e) {
            error_log('[IR_PUEDE_FIRMAR] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Actualiza usuario_id / medico_informante_rol de informes del estudio tras asignar.
     */
    function ir_sync_medico_informes_estudio(PDO $db, array $studyKeys, int $medicoUserId): int
    {
        if ($medicoUserId <= 0) {
            return 0;
        }
        $keys = [];
        foreach (['estudio_id', 'study_id', 'study_instance_uid', 'orthanc_study_id'] as $k) {
            $v = trim((string)($studyKeys[$k] ?? ''));
            if ($v !== '') {
                $keys[] = $v;
            }
        }
        $keys = array_values(array_unique($keys));
        if ($keys === []) {
            return 0;
        }
        $rol = null;
        try {
            $st = $db->prepare('SELECT rol FROM usuarios WHERE id = ?');
            $st->execute([$medicoUserId]);
            $rol = $st->fetchColumn() ?: 'medico_informante';
        } catch (Throwable $e) {
            $rol = 'medico_informante';
        }
        $ph = implode(',', array_fill(0, count($keys), '?'));
        $params = array_merge([$medicoUserId, $rol], $keys, $keys, $keys);
        $sql = "UPDATE informes
                SET usuario_id = ?,
                    medico_informante_rol = ?,
                    fecha_modificacion = CURRENT_TIMESTAMP
                WHERE (
                    estudio_id IN ($ph)
                    OR study_instance_uid IN ($ph)
                    OR study_id IN ($ph)
                  )
                  AND (
                    origen = 'externo'
                    OR usuario_id = (SELECT id FROM (SELECT id FROM usuarios ORDER BY id ASC LIMIT 1) t)
                    OR usuario_id IS NULL
                  )
                  AND (firmado_en IS NULL)";
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (Throwable $e) {
            error_log('[IR_SYNC_MEDICO] ' . $e->getMessage());
            return 0;
        }
    }

    function ir_informe_es_externo(array $informe): bool
    {
        $origen = strtolower(trim((string)($informe['origen'] ?? '')));
        if ($origen === 'externo') {
            return true;
        }
        if (!empty($informe['es_adjunto'])) {
            return true;
        }
        $html = (string)($informe['contenido_html'] ?? '');
        $pdf = trim((string)($informe['pdf_path'] ?? ''));
        if ($pdf !== '' && (
            strpos($html, 'informe-adjunto') !== false
            || strpos($html, 'pdf-viewer-btn') !== false
        )) {
            return true;
        }
        return false;
    }
}
