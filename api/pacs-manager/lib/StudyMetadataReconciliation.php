<?php
/**
 * Propaga metadatos DICOM editados (PatientName, PatientID, etc.) a tablas de la plataforma.
 * Se ejecuta después de StudyUidReconciliation; el alcance es por IDs del estudio (old + new),
 * nunca por PatientID global.
 */

class StudyMetadataReconciliation {

    private const INTERNAL_TAG_KEYS = ['_study_fingerprint', '_old_patient_id'];

    /** @var array<string, array<string, string>> tag DICOM => [tabla => columna] */
    private const TAG_COLUMN_MAP = [
        'PatientID' => [
            'informes' => 'patient_id',
            'estudios' => 'patient_id_pacs',
            'study_assignments' => 'patient_id',
            'mobile_sessions' => 'patient_id',
            'informes_recibidos' => 'patient_id',
        ],
        'PatientName' => [
            'informes' => 'patient_name',
            'estudios' => 'patient_name_pacs',
            'study_assignments' => 'patient_name',
            'mobile_sessions' => 'patient_name',
            'informes_recibidos' => 'patient_name',
        ],
        'StudyDescription' => [
            'informes' => 'study_description',
            'estudios' => 'study_description',
            'study_assignments' => 'study_description',
            'mobile_sessions' => 'study_description',
            'informes_recibidos' => 'procedure_description',
        ],
        'AccessionNumber' => [
            'informes' => 'accession_number',
            'estudios' => 'accession_number',
            'study_assignments' => 'accession_number',
            'informes_recibidos' => 'accession_number',
        ],
        'ReferringPhysicianName' => [
            'estudios' => 'referring_physician',
            'study_assignments' => 'referring_physician',
            'informes_recibidos' => 'referring_physician',
        ],
    ];

    /**
     * @return array{success:bool, status:string, summary:array, details:array, error:?string}
     */
    public static function reconcile(
        PDO $db,
        array $log,
        string $oldOrthancId,
        string $newOrthancId,
        ?string $oldStudyInstanceUid,
        ?string $newStudyInstanceUid
    ): array {
        $oldOrthancId = trim($oldOrthancId);
        $newOrthancId = trim($newOrthancId);
        $oldStudyInstanceUid = $oldStudyInstanceUid ? trim($oldStudyInstanceUid) : null;
        $newStudyInstanceUid = $newStudyInstanceUid ? trim($newStudyInstanceUid) : null;

        $fieldUpdates = self::buildFieldUpdatesFromLog($log);
        if (empty($fieldUpdates)) {
            return [
                'success' => true,
                'status' => 'skipped',
                'summary' => ['total_rows_updated' => 0, 'tables_touched' => 0, 'errors_count' => 0],
                'details' => [],
                'error' => null,
            ];
        }

        $details = [];
        $totalRows = 0;
        $errors = [];
        $estudiosId = isset($log['estudios_id']) ? (int) $log['estudios_id'] : 0;

        $tableUpdates = self::groupUpdatesByTable($fieldUpdates);

        foreach ($tableUpdates as $table => $columns) {
            if (!self::tableExists($db, $table)) {
                continue;
            }

            $sets = [];
            $params = [];
            foreach ($columns as $column => $value) {
                if (!self::columnExists($db, $table, $column)) {
                    continue;
                }
                $sets[] = "`$column` = ?";
                $params[] = $value;
            }
            if (empty($sets)) {
                continue;
            }

            $where = self::buildWhereForTable(
                $db,
                $table,
                $oldOrthancId,
                $newOrthancId,
                $oldStudyInstanceUid,
                $newStudyInstanceUid,
                $estudiosId
            );
            if ($where === null) {
                continue;
            }

            try {
                $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE ' . $where['sql'];
                $stmt = $db->prepare($sql);
                $stmt->execute(array_merge($params, $where['params']));
                $count = $stmt->rowCount();
                $totalRows += $count;
                $details[] = [
                    'table_name' => $table,
                    'column_name' => 'metadata',
                    'rows_updated' => $count,
                    'error_message' => 'fields=' . implode(',', array_keys($columns)),
                ];
            } catch (Exception $e) {
                $msg = $e->getMessage();
                $errors[] = "$table: $msg";
                $details[] = [
                    'table_name' => $table,
                    'column_name' => 'metadata',
                    'rows_updated' => 0,
                    'error_message' => $msg,
                ];
            }
        }

        // study_date (StudyDate) en tablas que lo soportan
        if (isset($fieldUpdates['estudios']['study_date']) || isset($fieldUpdates['study_assignments']['study_date'])) {
            foreach (['estudios', 'study_assignments'] as $dateTable) {
                if (!isset($fieldUpdates[$dateTable]['study_date'])) {
                    continue;
                }
                if (!self::tableExists($db, $dateTable) || !self::columnExists($db, $dateTable, 'study_date')) {
                    continue;
                }
                $where = self::buildWhereForTable(
                    $db,
                    $dateTable,
                    $oldOrthancId,
                    $newOrthancId,
                    $oldStudyInstanceUid,
                    $newStudyInstanceUid,
                    $estudiosId
                );
                if ($where === null) {
                    continue;
                }
                try {
                    $sql = 'UPDATE `' . $dateTable . '` SET study_date = ? WHERE ' . $where['sql'];
                    $stmt = $db->prepare($sql);
                    $stmt->execute(array_merge([$fieldUpdates[$dateTable]['study_date']], $where['params']));
                    $count = $stmt->rowCount();
                    $totalRows += $count;
                    $details[] = [
                        'table_name' => $dateTable,
                        'column_name' => 'metadata:study_date',
                        'rows_updated' => $count,
                        'error_message' => null,
                    ];
                } catch (Exception $e) {
                    $errors[] = "$dateTable.study_date: " . $e->getMessage();
                }
            }
        }

        // Limpiar refs PACS huérfanas (pacs_study_id sin serie/instancia → UI y portal coherentes)
        $normalized = self::normalizeOrphanedPacsRefs(
            $db,
            $oldOrthancId,
            $newOrthancId,
            $oldStudyInstanceUid,
            $newStudyInstanceUid
        );
        if ($normalized > 0) {
            $totalRows += $normalized;
            $details[] = [
                'table_name' => 'informes',
                'column_name' => 'metadata:pacs_orphan_clear',
                'rows_updated' => $normalized,
                'error_message' => null,
            ];
        }

        $status = empty($errors) ? 'success' : ($totalRows > 0 ? 'partial' : 'failed');

        return [
            'success' => $status === 'success' || $status === 'skipped',
            'status' => $status,
            'summary' => [
                'total_rows_updated' => $totalRows,
                'tables_touched' => count(array_unique(array_column(
                    array_filter($details, fn($d) => ($d['rows_updated'] ?? 0) > 0),
                    'table_name'
                ))),
                'errors_count' => count($errors),
                'fields_updated' => array_keys($fieldUpdates),
            ],
            'details' => $details,
            'error' => empty($errors) ? null : implode('; ', $errors),
        ];
    }

    /**
     * @return array<string, array<string, string>> tabla => [columna => valor]
     */
    private static function buildFieldUpdatesFromLog(array $log): array {
        $tags = self::parseTagsFromLog($log);
        foreach (self::INTERNAL_TAG_KEYS as $key) {
            unset($tags[$key]);
        }

        // Fallback para reintentos sobre ediciones ya auditadas sin tags_requested completos
        if (empty($tags['PatientID']) && !empty($log['patient_id_pacs'])) {
            $tags['PatientID'] = $log['patient_id_pacs'];
        }
        if (empty($tags['PatientName']) && !empty($log['patient_name_pacs'])) {
            $tags['PatientName'] = $log['patient_name_pacs'];
        }
        if (empty($tags['AccessionNumber']) && !empty($log['accession_number'])) {
            $tags['AccessionNumber'] = $log['accession_number'];
        }

        $updates = [];
        foreach (self::TAG_COLUMN_MAP as $tagName => $tableMap) {
            if (!array_key_exists($tagName, $tags)) {
                continue;
            }
            $value = is_string($tags[$tagName]) ? trim($tags[$tagName]) : $tags[$tagName];
            if ($value === null) {
                continue;
            }
            foreach ($tableMap as $table => $column) {
                $updates[$table][$column] = (string) $value;
            }
        }

        if (isset($tags['StudyDate'])) {
            $studyDate = self::normalizeStudyDate($tags['StudyDate']);
            if ($studyDate !== null) {
                $updates['estudios']['study_date'] = $studyDate;
                $updates['study_assignments']['study_date'] = $studyDate;
            }
        }

        return $updates;
    }

    private static function parseTagsFromLog(array $log): array {
        if (empty($log['tags_requested'])) {
            return [];
        }
        $decoded = is_string($log['tags_requested'])
            ? json_decode($log['tags_requested'], true)
            : $log['tags_requested'];
        return is_array($decoded) ? $decoded : [];
    }

    private static function normalizeStudyDate($raw): ?string {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d{8}$/', $raw)) {
            return substr($raw, 0, 4) . '-' . substr($raw, 4, 2) . '-' . substr($raw, 6, 2);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }
        $ts = strtotime($raw);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    /**
     * @param array<string, array<string, string>> $fieldUpdates
     * @return array<string, array<string, string>>
     */
    private static function groupUpdatesByTable(array $fieldUpdates): array {
        $grouped = [];
        foreach ($fieldUpdates as $table => $columns) {
            if ($table === 'estudios' && isset($columns['study_date'])) {
                unset($columns['study_date']);
            }
            if ($table === 'study_assignments' && isset($columns['study_date'])) {
                unset($columns['study_date']);
            }
            if (!empty($columns)) {
                $grouped[$table] = $columns;
            }
        }
        return $grouped;
    }

    /**
     * @return array{sql:string, params:array}|null
     */
    private static function buildWhereForTable(
        PDO $db,
        string $table,
        string $oldOrthancId,
        string $newOrthancId,
        ?string $oldSuid,
        ?string $newSuid,
        int $estudiosId
    ): ?array {
        $orthancIds = array_values(array_unique(array_filter([$oldOrthancId, $newOrthancId])));
        $suids = array_values(array_unique(array_filter([$oldSuid, $newSuid])));

        switch ($table) {
            case 'informes':
                return self::buildInOrWhere(
                    ['estudio_id', 'study_id', 'study_instance_uid'],
                    array_merge($orthancIds, $suids)
                );

            case 'estudios':
                $parts = ['orthanc_study_id IN (' . self::placeholders(count($orthancIds)) . ')'];
                $params = $orthancIds;
                if ($estudiosId > 0) {
                    $parts[] = 'id = ?';
                    $params[] = $estudiosId;
                }
                return ['sql' => '(' . implode(' OR ', $parts) . ')', 'params' => $params];

            case 'study_assignments':
                $clauses = [];
                $params = [];
                if (!empty($orthancIds)) {
                    $clauses[] = 'study_id IN (' . self::placeholders(count($orthancIds)) . ')';
                    $params = array_merge($params, $orthancIds);
                }
                if (!empty($orthancIds) && self::columnExists($db, $table, 'orthanc_study_id')) {
                    $clauses[] = 'orthanc_study_id IN (' . self::placeholders(count($orthancIds)) . ')';
                    $params = array_merge($params, $orthancIds);
                }
                if (!empty($suids)) {
                    $clauses[] = 'study_instance_uid IN (' . self::placeholders(count($suids)) . ')';
                    $params = array_merge($params, $suids);
                }
                return empty($clauses) ? null : ['sql' => '(' . implode(' OR ', $clauses) . ')', 'params' => $params];

            case 'mobile_sessions':
                return self::buildInOrWhere(['study_id'], $orthancIds);

            case 'informes_recibidos':
                if ($estudiosId <= 0) {
                    return null;
                }
                return ['sql' => 'estudio_id = ?', 'params' => [$estudiosId]];

            default:
                return null;
        }
    }

    /** @param string[] $ids */
    private static function buildInOrWhere(array $columns, array $ids): ?array {
        $ids = array_values(array_unique(array_filter($ids, fn($v) => $v !== '')));
        if (empty($ids)) {
            return null;
        }
        $clauses = [];
        foreach ($columns as $col) {
            $clauses[] = "`$col` IN (" . self::placeholders(count($ids)) . ')';
        }
        $params = [];
        foreach ($columns as $_col) {
            $params = array_merge($params, $ids);
        }
        return ['sql' => '(' . implode(' OR ', $clauses) . ')', 'params' => $params];
    }

    private static function placeholders(int $n): string {
        return implode(',', array_fill(0, $n, '?'));
    }

    /**
     * Si no hay serie/instancia en PACS, anular pacs_study_id y fecha_enviado_pacs
     * (evita botón amarillo “eliminar de PACS” sin PDF real en el estudio nuevo).
     */
    private static function normalizeOrphanedPacsRefs(
        PDO $db,
        string $oldOrthancId,
        string $newOrthancId,
        ?string $oldSuid,
        ?string $newSuid
    ): int {
        if (!self::tableExists($db, 'informes')) {
            return 0;
        }
        $where = self::buildWhereForTable($db, 'informes', $oldOrthancId, $newOrthancId, $oldSuid, $newSuid, 0);
        if ($where === null) {
            return 0;
        }
        $sets = [];
        if (self::columnExists($db, 'informes', 'pacs_study_id')) {
            $sets[] = 'pacs_study_id = NULL';
        }
        if (self::columnExists($db, 'informes', 'fecha_enviado_pacs')) {
            $sets[] = 'fecha_enviado_pacs = NULL';
        }
        if (empty($sets)) {
            return 0;
        }
        $orphanCond = "(pacs_series_id IS NULL OR pacs_series_id = '') AND (pacs_instance_id IS NULL OR pacs_instance_id = '')";
        try {
            $sql = 'UPDATE informes SET ' . implode(', ', $sets)
                . ' WHERE ' . $orphanCond . ' AND (' . $where['sql'] . ')'
                . ' AND (pacs_study_id IS NOT NULL OR fecha_enviado_pacs IS NOT NULL)';
            $stmt = $db->prepare($sql);
            $stmt->execute($where['params']);
            return $stmt->rowCount();
        } catch (Exception $e) {
            error_log('[StudyMetadataReconciliation] normalizeOrphanedPacsRefs: ' . $e->getMessage());
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
