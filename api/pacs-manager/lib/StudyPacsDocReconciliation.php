<?php
/**
 * Re-vincula referencias PACS de informes (serie DOC) tras modify/reconcile de UIDs.
 * Alcance: informes ligados al nuevo orthanc_study_id / StudyInstanceUID, nunca por PatientID global.
 */

require_once __DIR__ . '/../../OrthancPacsSender.php';

class StudyPacsDocReconciliation {

    /**
     * @return array{success:bool, status:string, summary:array, details:array, error:?string}
     */
    public static function reconcile(
        PDO $db,
        OrthancPacsSender $pacs,
        string $newOrthancId,
        ?string $newStudyInstanceUid
    ): array {
        $newOrthancId = trim($newOrthancId);
        $newStudyInstanceUid = $newStudyInstanceUid ? trim($newStudyInstanceUid) : null;

        if ($newOrthancId === '') {
            return self::result(false, 'failed', [], [], 'new_orthanc_study_id es requerido');
        }

        if (!self::columnExists($db, 'informes', 'pacs_series_id')) {
            return self::result(true, 'skipped', [
                'informes_total' => 0,
                'linked' => 0,
                'already_valid' => 0,
                'skipped_no_series' => 0,
                'errors_count' => 0,
            ], [], null);
        }

        $informes = self::fetchInformesForStudy($db, $newOrthancId, $newStudyInstanceUid);
        if (empty($informes)) {
            return self::result(true, 'skipped', [
                'informes_total' => 0,
                'linked' => 0,
                'already_valid' => 0,
                'skipped_no_series' => 0,
                'errors_count' => 0,
            ], [[
                'table_name' => 'informes',
                'column_name' => 'doc:scope',
                'rows_updated' => 0,
                'error_message' => 'Sin informes en alcance del estudio',
            ]], null);
        }

        $docSeriesPool = $pacs->listDocSeriesInStudy($newOrthancId);
        $claimedInDb = self::fetchClaimedSeriesIds($db, $newOrthancId);
        $assignedThisRun = [];

        $details = [];
        $linked = 0;
        $alreadyValid = 0;
        $skippedNoSeries = 0;
        // Informes que TENÍAN pacs_series_id pero la serie ya no existe en el estudio nuevo.
        $skippedPrevLinked = 0;
        $errors = [];

        foreach ($informes as $informe) {
            $informeId = (int) ($informe['id'] ?? 0);
            if ($informeId <= 0) {
                continue;
            }

            $currentSeriesId = trim((string) ($informe['pacs_series_id'] ?? ''));
            if ($currentSeriesId !== '' && self::seriesBelongsToStudy($pacs, $currentSeriesId, $newOrthancId)) {
                $alreadyValid++;
                $details[] = [
                    'table_name' => 'informes',
                    'column_name' => 'doc:informe_' . $informeId,
                    'rows_updated' => 0,
                    'error_message' => 'refs_pacs_ya_validas',
                ];
                $assignedThisRun[$currentSeriesId] = $informeId;
                continue;
            }

            $wasLinked = ($currentSeriesId !== '');

            $accession = trim((string) ($informe['accession_number'] ?? ''));
            $match = self::pickDocSeriesForInforme(
                $docSeriesPool,
                $accession,
                $claimedInDb,
                $assignedThisRun,
                $informeId
            );

            if ($match === null) {
                $skippedNoSeries++;
                if ($wasLinked) {
                    // La serie DOC que existía desapareció en el estudio nuevo — caso real de re-vinculación fallida.
                    $skippedPrevLinked++;
                }
                $details[] = [
                    'table_name' => 'informes',
                    'column_name' => 'doc:informe_' . $informeId,
                    'rows_updated' => 0,
                    'error_message' => $wasLinked
                        ? 'serie_doc_anterior_no_encontrada'
                        : ($accession !== '' ? 'sin_serie_doc_coincidente' : 'sin_serie_doc_disponible'),
                ];
                continue;
            }

            try {
                $updated = self::updateInformePacsRefs($db, $informeId, $newOrthancId, $match);
                if ($updated) {
                    $linked++;
                    $assignedThisRun[$match['series_id']] = $informeId;
                    $contentHint = self::describeContentType($match['sop_class_uid'] ?? '');
                    $details[] = [
                        'table_name' => 'informes',
                        'column_name' => 'doc:informe_' . $informeId,
                        'rows_updated' => 1,
                        'error_message' => $contentHint !== '' ? $contentHint : null,
                    ];
                }
            } catch (Exception $e) {
                $msg = 'informe #' . $informeId . ': ' . $e->getMessage();
                $errors[] = $msg;
                $details[] = [
                    'table_name' => 'informes',
                    'column_name' => 'doc:informe_' . $informeId,
                    'rows_updated' => 0,
                    'error_message' => mb_substr($msg, 0, 512),
                ];
            }
        }

        $summary = [
            'informes_total' => count($informes),
            'linked' => $linked,
            'already_valid' => $alreadyValid,
            'skipped_no_series' => $skippedNoSeries,
            'skipped_prev_linked' => $skippedPrevLinked,
            'errors_count' => count($errors),
            'doc_series_in_study' => count($docSeriesPool),
        ];

        if (!empty($errors)) {
            $status = ($linked > 0 || $alreadyValid > 0) ? 'partial' : 'failed';
            return self::result($status !== 'failed', $status, $summary, $details, implode('; ', $errors));
        }

        if ($skippedPrevLinked > 0) {
            // Informes que tenían serie DOC y ya no se pudo re-vincular → partial real.
            $anyOk = ($linked > 0 || $alreadyValid > 0);
            return self::result(true, 'partial', $summary, $details, 'No se encontró serie DOC para ' . $skippedPrevLinked . ' informe(s) previamente vinculado(s)');
        }

        if ($skippedNoSeries > 0 && ($linked > 0 || $alreadyValid > 0)) {
            // Algunos informes nuevos sin DOC pero otros OK → partial menor.
            return self::result(true, 'partial', $summary, $details, null);
        }

        // Todos los informes nunca tuvieron serie DOC (pacs_series_id vacío) y no hay DOC en el estudio.
        // No es un error: simplemente no hay serie DOC que vincular.
        if ($skippedNoSeries > 0 && $linked === 0 && $alreadyValid === 0 && $skippedPrevLinked === 0) {
            return self::result(true, 'skipped', $summary, $details, null);
        }

        if ($linked === 0 && $alreadyValid === 0) {
            return self::result(true, 'skipped', $summary, $details, null);
        }

        return self::result(true, 'success', $summary, $details, null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fetchInformesForStudy(PDO $db, string $newOrthancId, ?string $newSuid): array {
        $conds = ['study_id = ?', 'estudio_id = ?'];
        $params = [$newOrthancId, $newOrthancId];
        if ($newSuid) {
            $conds[] = 'study_instance_uid = ?';
            $params[] = $newSuid;
        }
        $cols = ['id', 'accession_number', 'pacs_series_id', 'pacs_instance_id', 'pacs_study_id'];
        $sql = 'SELECT ' . implode(', ', $cols) . ' FROM informes WHERE (' . implode(' OR ', $conds) . ') ORDER BY id ASC';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string, int> series_id => informe_id */
    private static function fetchClaimedSeriesIds(PDO $db, string $newOrthancId): array {
        $stmt = $db->prepare(
            'SELECT id, pacs_series_id FROM informes
             WHERE pacs_series_id IS NOT NULL AND pacs_series_id != \'\'
               AND (study_id = ? OR estudio_id = ? OR pacs_study_id = ?)'
        );
        $stmt->execute([$newOrthancId, $newOrthancId, $newOrthancId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sid = trim((string) ($row['pacs_series_id'] ?? ''));
            if ($sid !== '') {
                $out[$sid] = (int) $row['id'];
            }
        }
        return $out;
    }

    private static function seriesBelongsToStudy(OrthancPacsSender $pacs, string $seriesId, string $studyId): bool {
        $resp = $pacs->makeRequestWithRetry('/series/' . rawurlencode($seriesId), 'GET', null, 8);
        if (!$resp['success']) {
            return false;
        }
        $parent = (string) ($resp['data']['ParentStudy'] ?? '');
        return $parent === $studyId;
    }

    /**
     * @param list<array<string, mixed>> $pool
     * @param array<string, int> $claimedInDb
     * @param array<string, int> $assignedThisRun
     * @return array<string, mixed>|null
     */
    private static function pickDocSeriesForInforme(
        array $pool,
        string $accession,
        array $claimedInDb,
        array $assignedThisRun,
        int $informeId
    ): ?array {
        if (empty($pool)) {
            return null;
        }

        $isFree = function (array $series) use ($claimedInDb, $assignedThisRun, $informeId): bool {
            $sid = (string) ($series['series_id'] ?? '');
            if ($sid === '') {
                return false;
            }
            if (isset($assignedThisRun[$sid])) {
                return $assignedThisRun[$sid] === $informeId;
            }
            if (isset($claimedInDb[$sid]) && $claimedInDb[$sid] !== $informeId) {
                return false;
            }
            return true;
        };

        if ($accession !== '') {
            foreach ($pool as $series) {
                if (!$isFree($series)) {
                    continue;
                }
                $seriesAcc = trim((string) ($series['accession_number'] ?? ''));
                if ($seriesAcc !== '' && strcasecmp($seriesAcc, $accession) === 0) {
                    return $series;
                }
            }
        }

        foreach ($pool as $series) {
            if (!$isFree($series)) {
                continue;
            }
            $seriesAcc = trim((string) ($series['accession_number'] ?? ''));
            if ($accession === '' || $seriesAcc === '') {
                return $series;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $match
     */
    private static function updateInformePacsRefs(PDO $db, int $informeId, string $studyId, array $match): bool {
        $fields = ['pacs_series_id = ?', 'pacs_study_id = ?'];
        $values = [(string) $match['series_id'], $studyId];

        if (self::columnExists($db, 'informes', 'pacs_instance_id')) {
            $instanceId = $match['instance_id'] ?? null;
            if ($instanceId) {
                $fields[] = 'pacs_instance_id = ?';
                $values[] = (string) $instanceId;
            } else {
                $fields[] = 'pacs_instance_id = NULL';
            }
        }

        if (self::columnExists($db, 'informes', 'fecha_enviado_pacs')) {
            $fields[] = 'fecha_enviado_pacs = COALESCE(fecha_enviado_pacs, NOW())';
        }

        $values[] = $informeId;
        $sql = 'UPDATE informes SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $stmt = $db->prepare($sql);
        $stmt->execute($values);
        return $stmt->rowCount() > 0;
    }

    private static function describeContentType(?string $sopClassUid): string {
        $sop = trim((string) $sopClassUid);
        if ($sop === OrthancPacsSender::SOPCLASS_PDF) {
            return 'content:encapsulated_pdf';
        }
        if ($sop === OrthancPacsSender::SOPCLASS_SECONDARY_CAPTURE) {
            return 'content:image_pages';
        }
        if ($sop !== '') {
            return 'content:' . $sop;
        }
        return 'content:unknown';
    }

    private static function result(bool $success, string $status, array $summary, array $details, ?string $error): array {
        return [
            'success' => $success,
            'status' => $status,
            'summary' => $summary,
            'details' => $details,
            'error' => $error,
        ];
    }

    private static function columnExists(PDO $db, string $table, string $column): bool {
        try {
            $stmt = $db->prepare(
                'SELECT 1 FROM information_schema.COLUMNS
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
            );
            $stmt->execute([$table, $column]);
            return (bool) $stmt->fetchColumn();
        } catch (Exception $e) {
            return false;
        }
    }
}
