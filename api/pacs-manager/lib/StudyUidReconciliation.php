<?php
/**
 * Reemplaza referencias old → new (Orthanc UUID y StudyInstanceUID) en tablas del sistema.
 */

class StudyUidReconciliation {

    /**
     * @return array{success:bool, status:string, summary:array, details:array, error:?string}
     */
    public static function reconcile(
        PDO $db,
        string $oldOrthancId,
        string $newOrthancId,
        ?string $oldStudyInstanceUid,
        ?string $newStudyInstanceUid
    ): array {
        $oldOrthancId = trim($oldOrthancId);
        $newOrthancId = trim($newOrthancId);
        $oldStudyInstanceUid = $oldStudyInstanceUid ? trim($oldStudyInstanceUid) : null;
        $newStudyInstanceUid = $newStudyInstanceUid ? trim($newStudyInstanceUid) : null;

        if ($oldOrthancId === '' || $newOrthancId === '') {
            return [
                'success' => false,
                'status' => 'failed',
                'summary' => [],
                'details' => [],
                'error' => 'old_orthanc_study_id y new_orthanc_study_id son requeridos',
            ];
        }

        $details = [];
        $totalRows = 0;
        $errors = [];

        $plans = self::buildUpdatePlans($oldOrthancId, $newOrthancId, $oldStudyInstanceUid, $newStudyInstanceUid);

        foreach ($plans as $plan) {
            if (!self::tableExists($db, $plan['table']) || !self::columnExists($db, $plan['table'], $plan['column'])) {
                continue;
            }
            try {
                $sql = "UPDATE `{$plan['table']}` SET `{$plan['column']}` = ? WHERE `{$plan['column']}` = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute([$plan['new_value'], $plan['old_value']]);
                $count = $stmt->rowCount();
                $totalRows += $count;
                $details[] = [
                    'table_name' => $plan['table'],
                    'column_name' => $plan['column'],
                    'rows_updated' => $count,
                    'error_message' => null,
                ];
            } catch (Exception $e) {
                $msg = $e->getMessage();
                $errors[] = "{$plan['table']}.{$plan['column']}: $msg";
                $details[] = [
                    'table_name' => $plan['table'],
                    'column_name' => $plan['column'],
                    'rows_updated' => 0,
                    'error_message' => $msg,
                ];
            }
        }

        // Si estudios ya tiene el nuevo orthanc_study_id pero study_instance_uid vacío, rellenarlo.
        // Ocurre cuando un auto-import actualizó orthanc_study_id antes de que corriera esta reconciliación.
        if ($newSuid && self::tableExists($db, 'estudios') && self::columnExists($db, 'estudios', 'study_instance_uid')) {
            try {
                $stmt = $db->prepare(
                    "UPDATE estudios SET study_instance_uid = ?
                     WHERE orthanc_study_id = ? AND (study_instance_uid IS NULL OR study_instance_uid = '')"
                );
                $stmt->execute([$newSuid, $newOrthancId]);
                $c = $stmt->rowCount();
                if ($c > 0) {
                    $totalRows += $c;
                    $details[] = [
                        'table_name' => 'estudios',
                        'column_name' => 'study_instance_uid (fill)',
                        'rows_updated' => $c,
                        'error_message' => null,
                    ];
                }
            } catch (Exception $e) {
                $errors[] = 'estudios.study_instance_uid fill: ' . $e->getMessage();
            }
        }

        // Legacy: orthanc_study_id que guardó StudyInstanceUID
        if ($oldStudyInstanceUid && $oldStudyInstanceUid !== $oldOrthancId) {
            try {
                if (self::tableExists($db, 'estudios') && self::columnExists($db, 'estudios', 'orthanc_study_id')) {
                    $stmt = $db->prepare(
                        'UPDATE estudios SET orthanc_study_id = ? WHERE orthanc_study_id = ?'
                    );
                    $stmt->execute([$newOrthancId, $oldStudyInstanceUid]);
                    $c = $stmt->rowCount();
                    if ($c > 0) {
                        $totalRows += $c;
                        $details[] = [
                            'table_name' => 'estudios',
                            'column_name' => 'orthanc_study_id (legacy suid)',
                            'rows_updated' => $c,
                            'error_message' => null,
                        ];
                    }
                }
            } catch (Exception $e) {
                $errors[] = 'estudios.orthanc_study_id legacy: ' . $e->getMessage();
            }
        }

        // Invalidar referencias PACS de informes ligadas al estudio Orthanc antiguo
        $pacsCleared = self::clearInformesPacsRefs($db, $oldOrthancId, $oldStudyInstanceUid);
        if ($pacsCleared > 0) {
            $totalRows += $pacsCleared;
            $details[] = [
                'table_name' => 'informes',
                'column_name' => 'pacs_* cleared',
                'rows_updated' => $pacsCleared,
                'error_message' => null,
            ];
        }

        $status = empty($errors) ? 'success' : (count($details) > 0 && $totalRows > 0 ? 'partial' : 'failed');

        return [
            'success' => $status === 'success',
            'status' => $status,
            'summary' => [
                'total_rows_updated' => $totalRows,
                'tables_touched' => count(array_filter($details, fn($d) => ($d['rows_updated'] ?? 0) > 0)),
                'errors_count' => count($errors),
            ],
            'details' => $details,
            'error' => empty($errors) ? null : implode('; ', $errors),
        ];
    }

    private static function buildUpdatePlans(
        string $oldOrthancId,
        string $newOrthancId,
        ?string $oldSuid,
        ?string $newSuid
    ): array {
        $plans = [];
        $add = function ($table, $column, $oldVal, $newVal) use (&$plans) {
            if ($oldVal === null || $oldVal === '' || $oldVal === $newVal) {
                return;
            }
            $plans[] = [
                'table' => $table,
                'column' => $column,
                'old_value' => $oldVal,
                'new_value' => $newVal,
            ];
        };

        $add('estudios', 'orthanc_study_id', $oldOrthancId, $newOrthancId);
        if ($oldSuid && $newSuid) {
            $add('estudios', 'study_instance_uid', $oldSuid, $newSuid);
        }

        foreach (['study_assignments', 'study_subassignments', 'study_antecedents', 'mobile_sessions'] as $t) {
            $add($t, 'study_id', $oldOrthancId, $newOrthancId);
        }

        $add('study_assignments', 'orthanc_study_id', $oldOrthancId, $newOrthancId);
        if ($oldSuid && $newSuid) {
            $add('study_assignments', 'study_instance_uid', $oldSuid, $newSuid);
        }

        $add('study_flags', 'study_id', $oldOrthancId, $newOrthancId);
        $add('study_flags', 'orthanc_id', $oldOrthancId, $newOrthancId);
        if ($oldSuid && $newSuid) {
            $add('study_flags', 'study_instance_uid', $oldSuid, $newSuid);
        }

        $add('informes', 'estudio_id', $oldOrthancId, $newOrthancId);
        $add('informes', 'study_id', $oldOrthancId, $newOrthancId);
        $add('informes', 'pacs_study_id', $oldOrthancId, $newOrthancId);
        if ($oldSuid && $newSuid) {
            $add('informes', 'estudio_id', $oldSuid, $newSuid);
            $add('informes', 'study_instance_uid', $oldSuid, $newSuid);
        }

        foreach (['audios_informe', 'audios_workspace_sessions', 'audios_estado_log'] as $t) {
            $add($t, 'estudio_id', $oldOrthancId, $newOrthancId);
            if ($oldSuid && $newSuid) {
                $add($t, 'estudio_id', $oldSuid, $newSuid);
            }
        }

        $add('ai_transcription_queue', 'orthanc_study_id', $oldOrthancId, $newOrthancId);
        $add('transcription_queue', 'orthanc_study_id', $oldOrthancId, $newOrthancId);

        $add('r2_queue', 'orthanc_study_id', $oldOrthancId, $newOrthancId);
        if ($oldSuid && $newSuid) {
            $add('r2_queue', 'study_instance_uid', $oldSuid, $newSuid);
        }
        $add('r2_studies', 'orthanc_study_id', $oldOrthancId, $newOrthancId);
        if ($oldSuid && $newSuid) {
            $add('r2_studies', 'study_instance_uid', $oldSuid, $newSuid);
        }

        return $plans;
    }

    private static function clearInformesPacsRefs(PDO $db, string $oldOrthancId, ?string $oldSuid): int {
        if (!self::tableExists($db, 'informes')) {
            return 0;
        }
        $sets = [];
        if (self::columnExists($db, 'informes', 'pacs_series_id')) {
            $sets[] = 'pacs_series_id = NULL';
        }
        if (self::columnExists($db, 'informes', 'pacs_instance_id')) {
            $sets[] = 'pacs_instance_id = NULL';
        }
        if (self::columnExists($db, 'informes', 'pacs_study_id')) {
            $sets[] = 'pacs_study_id = NULL';
        }
        if (self::columnExists($db, 'informes', 'fecha_enviado_pacs')) {
            $sets[] = 'fecha_enviado_pacs = NULL';
        }
        if (empty($sets)) {
            return 0;
        }
        $conds = ['pacs_study_id = ?'];
        $params = [$oldOrthancId];
        if ($oldSuid) {
            $conds[] = '(estudio_id = ? OR study_instance_uid = ?)';
            $params[] = $oldSuid;
            $params[] = $oldSuid;
        }
        $where = '(' . implode(' OR ', $conds) . ')';
        if (self::columnExists($db, 'informes', 'pacs_series_id')) {
            $where .= " AND (pacs_series_id IS NOT NULL AND pacs_series_id != '')";
        }
        try {
            $sql = 'UPDATE informes SET ' . implode(', ', $sets) . ' WHERE ' . $where;
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (Exception $e) {
            error_log('[StudyUidReconciliation] clearInformesPacsRefs: ' . $e->getMessage());
            return 0;
        }
    }

    private static function tableExists(PDO $db, string $table): bool {
        try {
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
            );
            $stmt->execute([$table]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    private static function columnExists(PDO $db, string $table, string $column): bool {
        try {
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
            );
            $stmt->execute([$table, $column]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
}
