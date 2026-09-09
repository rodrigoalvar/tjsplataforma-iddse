<?php
/**
 * Post-proceso tras job Orthanc Success: nuevos UIDs, reconciliación BD, borrado opcional del original.
 */

require_once __DIR__ . '/PacsStudyModifyLog.php';
require_once __DIR__ . '/StudyUidReconciliation.php';
require_once __DIR__ . '/StudyMetadataReconciliation.php';
require_once __DIR__ . '/StudyPacsDocReconciliation.php';

class PacsModifyPostProcessor {

    /** Reintentos de resolución vía polling antes de marcar failed (job-status cada ~5 s). */
    private const MAX_RESOLUTION_ATTEMPTS = 6;

    /**
     * Reconciliación UID + metadatos + serie DOC (informes).
     *
     * @return array{success:bool, status:string, summary:array, details:array, error:?string, uid_reconcile:array, metadata_reconcile:array, doc_reconcile:array}
     */
    private static function runFullReconciliation(
        PDO $db,
        OrthancPacsSender $pacs,
        array $log,
        string $oldOrthanc,
        string $newOrthanc,
        ?string $oldSuid,
        ?string $newSuid
    ): array {
        $uid = StudyUidReconciliation::reconcile($db, $oldOrthanc, $newOrthanc, $oldSuid, $newSuid);

        $meta = [
            'success' => true,
            'status' => 'skipped',
            'summary' => ['total_rows_updated' => 0, 'tables_touched' => 0, 'errors_count' => 0],
            'details' => [],
            'error' => null,
        ];
        if (($uid['status'] ?? '') === 'success') {
            $meta = StudyMetadataReconciliation::reconcile(
                $db,
                $log,
                $oldOrthanc,
                $newOrthanc,
                $oldSuid,
                $newSuid
            );
        }

        $doc = [
            'success' => true,
            'status' => 'skipped',
            'summary' => ['informes_total' => 0, 'linked' => 0, 'already_valid' => 0, 'skipped_no_series' => 0, 'errors_count' => 0],
            'details' => [],
            'error' => null,
        ];
        if (($uid['status'] ?? '') === 'success') {
            $doc = StudyPacsDocReconciliation::reconcile($db, $pacs, $newOrthanc, $newSuid);
        }

        $combinedDetails = array_merge($uid['details'] ?? [], $meta['details'] ?? [], $doc['details'] ?? []);
        $combinedSummary = [
            'uid' => $uid['summary'] ?? [],
            'metadata' => $meta['summary'] ?? [],
            'doc' => $doc['summary'] ?? [],
            'total_rows_updated' => (int) (($uid['summary']['total_rows_updated'] ?? 0) + ($meta['summary']['total_rows_updated'] ?? 0) + ($doc['summary']['linked'] ?? 0)),
            'tables_touched' => (int) (($uid['summary']['tables_touched'] ?? 0) + ($meta['summary']['tables_touched'] ?? 0)),
            'errors_count' => (int) (($uid['summary']['errors_count'] ?? 0) + ($meta['summary']['errors_count'] ?? 0) + ($doc['summary']['errors_count'] ?? 0)),
        ];

        $errors = array_filter([$uid['error'] ?? null, ($meta['error'] ?? null) ?: null, ($doc['error'] ?? null) ?: null]);
        $uidOk = ($uid['status'] ?? '') === 'success';
        $metaStatus = $meta['status'] ?? 'skipped';
        $docStatus = $doc['status'] ?? 'skipped';

        if (!$uidOk) {
            $status = $uid['status'] ?? 'failed';
            $success = false;
        } elseif (in_array('failed', [$metaStatus, $docStatus], true)) {
            $status = 'partial';
            $success = true;
        } elseif (in_array('partial', [$metaStatus, $docStatus], true)) {
            $status = 'partial';
            $success = true;
        } else {
            $status = 'success';
            $success = true;
        }

        return [
            'success' => $success,
            'status' => $status,
            'summary' => $combinedSummary,
            'details' => $combinedDetails,
            'error' => empty($errors) ? null : implode('; ', $errors),
            'uid_reconcile' => $uid,
            'metadata_reconcile' => $meta,
            'doc_reconcile' => $doc,
        ];
    }

    /** @return string[] UUIDs de estudios referenciados en el job Orthanc */
    public static function extractAllStudyIdsFromJob(array $jobData): array {
        $ids = [];
        $lists = [];
        if (isset($jobData['Content']['Resources']) && is_array($jobData['Content']['Resources'])) {
            $lists[] = $jobData['Content']['Resources'];
        }
        if (isset($jobData['Content']['ModifiedResources']) && is_array($jobData['Content']['ModifiedResources'])) {
            $lists[] = $jobData['Content']['ModifiedResources'];
        }
        if (isset($jobData['Resources']) && is_array($jobData['Resources'])) {
            $lists[] = $jobData['Resources'];
        }
        foreach ($lists as $resources) {
            foreach ($resources as $resource) {
                if (is_string($resource) && preg_match('/\/studies\/([a-f0-9\-]+)/i', $resource, $m)) {
                    $ids[] = $m[1];
                }
            }
        }
        if (!empty($jobData['Content']['Path']) && preg_match('#/studies/([a-f0-9\-]+)#i', $jobData['Content']['Path'], $m)) {
            $ids[] = $m[1];
        }
        return array_values(array_unique($ids));
    }

    /**
     * Extrae UUID de estudio desde respuesta de job Orthanc.
     */
    public static function extractNewStudyIdFromJob(array $jobData): ?string {
        $all = self::extractAllStudyIdsFromJob($jobData);
        return $all[0] ?? null;
    }

    private static function parseTagsFromLog(array $log): array {
        if (empty($log['tags_requested'])) {
            return [];
        }
        $decoded = is_string($log['tags_requested']) ? json_decode($log['tags_requested'], true) : $log['tags_requested'];
        return is_array($decoded) ? $decoded : [];
    }

    /** Estudios del paciente creado/modificado en el job (ámbito seguro, sin buscar por PatientID global). */
    private static function listStudyIdsFromJobPatientScope(OrthancPacsSender $pacs, array $jobData): array {
        $fromJob = self::extractAllStudyIdsFromJob($jobData);
        if (!empty($fromJob)) {
            return $fromJob;
        }
        if (!empty($jobData['Content']['Path']) && preg_match('#/patients/([a-f0-9\-]+)#i', $jobData['Content']['Path'], $pm)) {
            $studiesResp = $pacs->makeRequestWithRetry(
                '/patients/' . urlencode($pm[1]) . '/studies',
                'GET',
                null,
                15
            );
            if ($studiesResp['success'] && is_array($studiesResp['data'])) {
                $ids = [];
                foreach ($studiesResp['data'] as $studyPath) {
                    if (is_string($studyPath) && preg_match('/\/studies\/([a-f0-9\-]+)/i', $studyPath, $sm)) {
                        $ids[] = $sm[1];
                    } elseif (is_string($studyPath)) {
                        $ids[] = str_replace('/studies/', '', $studyPath);
                    }
                }
                return array_values(array_unique($ids));
            }
        }
        return [];
    }

    /**
     * Huella del estudio editado (sin depender de Accession Number — la mayoría no lo tiene).
     */
    private static function getStudyFingerprintFromLog(array $log): array {
        $tags = self::parseTagsFromLog($log);
        $fp = is_array($tags['_study_fingerprint'] ?? null) ? $tags['_study_fingerprint'] : [];
        return [
            'StudyDate' => trim((string) ($fp['StudyDate'] ?? $tags['StudyDate'] ?? '')),
            'StudyDescription' => trim((string) ($fp['StudyDescription'] ?? $tags['StudyDescription'] ?? '')),
            'InstitutionName' => trim((string) ($fp['InstitutionName'] ?? $tags['InstitutionName'] ?? '')),
            'instances' => (int) ($fp['instances'] ?? 0),
            'PatientID' => trim((string) ($tags['PatientID'] ?? $log['patient_id_pacs'] ?? '')),
        ];
    }

    private static function studyMatchesFingerprint(array $main, array $fp, int $instances = 0): bool {
        if ($fp['StudyDate'] !== '' && (string) ($main['StudyDate'] ?? '') !== $fp['StudyDate']) {
            return false;
        }
        if ($fp['StudyDescription'] !== '' && (string) ($main['StudyDescription'] ?? '') !== $fp['StudyDescription']) {
            return false;
        }
        if ($fp['InstitutionName'] !== '' && (string) ($main['InstitutionName'] ?? '') !== $fp['InstitutionName']) {
            return false;
        }
        if ($fp['instances'] > 0 && $instances > 0 && $instances !== $fp['instances']) {
            return false;
        }
        if ($fp['PatientID'] !== '' && (string) ($main['PatientID'] ?? '') !== $fp['PatientID']) {
            return false;
        }
        return true;
    }

    private static function normalizeOrthancStudyId(string $value): string {
        if (preg_match('/\/studies\/([a-f0-9\-]+)/i', $value, $m)) {
            return $m[1];
        }
        return trim($value);
    }

    /** Tags de estudio + PatientID del paciente padre (para huella tras cambio de DNI). */
    private static function studyTagsForFingerprint(array $studyData): array {
        $main = $studyData['MainDicomTags'] ?? [];
        $patient = $studyData['PatientMainDicomTags'] ?? [];
        if (!empty($patient['PatientID'])) {
            $main['PatientID'] = $patient['PatientID'];
        }
        return $main;
    }

    /**
     * Fallback cuando el job no lista /studies/: busca por PatientID destino + fecha/descripción.
     *
     * @return string[]
     */
    private static function findStudyIdsByFingerprintFallback(OrthancPacsSender $pacs, array $log): array {
        $fp = self::getStudyFingerprintFromLog($log);
        $oldId = $log['old_orthanc_study_id'] ?? null;

        if ($fp['PatientID'] === '' && $fp['StudyDate'] === '') {
            error_log('[PACS_MANAGER][RESOLVE] Fallback /tools/find omitido: sin PatientID ni StudyDate en huella');
            return [];
        }

        $query = [];
        if ($fp['PatientID'] !== '') {
            $query['PatientID'] = $fp['PatientID'];
        }
        if ($fp['StudyDate'] !== '') {
            $query['StudyDate'] = $fp['StudyDate'];
        }
        if ($fp['StudyDescription'] !== '') {
            $query['StudyDescription'] = $fp['StudyDescription'];
        }

        $findResponse = $pacs->makeRequestWithRetry(
            '/tools/find',
            'POST',
            ['Level' => 'Study', 'Query' => $query],
            15
        );
        if (!$findResponse['success'] || empty($findResponse['data']) || !is_array($findResponse['data'])) {
            error_log('[PACS_MANAGER][RESOLVE] Fallback /tools/find sin resultados. query=' . json_encode($query));
            return [];
        }

        $ids = [];
        foreach ($findResponse['data'] as $foundStudy) {
            if (!is_string($foundStudy) || $foundStudy === '') {
                continue;
            }
            $sid = self::normalizeOrthancStudyId($foundStudy);
            if ($sid === '' || $sid === $oldId) {
                continue;
            }
            $ids[] = $sid;
        }

        $ids = array_values(array_unique($ids));
        error_log('[PACS_MANAGER][RESOLVE] Fallback /tools/find candidatos=' . count($ids) . ' query=' . json_encode($query));
        return $ids;
    }

    private static function decodeReconcileSummary(array $log): array {
        if (empty($log['reconcile_summary'])) {
            return [];
        }
        $raw = $log['reconcile_summary'];
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        return is_array($decoded) ? $decoded : [];
    }

    /** Identifica la copia de ESTE estudio (fecha/descripción/instancias; no por PatientID global). */
    private static function filterStudiesMatchingThisEdit(OrthancPacsSender $pacs, array $studyIds, array $log): array {
        $fp = self::getStudyFingerprintFromLog($log);
        $oldId = $log['old_orthanc_study_id'] ?? null;
        $matched = [];
        $hasFingerprint = ($fp['StudyDate'] !== '' || $fp['StudyDescription'] !== '');

        foreach ($studyIds as $sid) {
            if ($sid === $oldId) {
                continue;
            }
            $resp = $pacs->makeRequestWithRetry('/studies/' . urlencode($sid), 'GET', null, 10);
            if (!$resp['success']) {
                continue;
            }
            $tagsForFp = self::studyTagsForFingerprint($resp['data'] ?? []);
            $inst = (int) ($resp['data']['Instances'] ?? $resp['data']['NumberOfStudyRelatedInstances'] ?? 0);
            if ($hasFingerprint && !self::studyMatchesFingerprint($tagsForFp, $fp, $inst)) {
                continue;
            }
            $matched[] = $sid;
        }

        // Sin huella usable: solo si hay una única copia candidata en el job (evitar mezclar otros estudios del paciente)
        if (!$hasFingerprint && count($matched) === 0 && count($studyIds) === 2) {
            foreach ($studyIds as $sid) {
                if ($sid !== $oldId) {
                    return [$sid];
                }
            }
        }

        return $matched;
    }

    /**
     * Localiza el estudio nuevo tras modify cuando el job no lista /studies/ (p. ej. modify a nivel paciente).
     */
    public static function resolveNewOrthancStudyId(OrthancPacsSender $pacs, array $jobData, array $log): ?string {
        $oldId = $log['old_orthanc_study_id'] ?? null;
        $scoped = self::listStudyIdsFromJobPatientScope($pacs, $jobData);
        $candidates = self::filterStudiesMatchingThisEdit($pacs, $scoped, $log);

        if (count($candidates) === 1) {
            return $candidates[0];
        }
        if (count($candidates) > 1) {
            error_log('[PACS_MANAGER][RESOLVE] Varias copias en ámbito del job: ' . implode(', ', $candidates));
            return self::pickBestStudyCandidate($pacs, $candidates, $jobData, $oldId);
        }

        // Un solo estudio en el job sin filtro por accession/fecha
        foreach ($scoped as $sid) {
            if ($sid !== $oldId) {
                return $sid;
            }
        }

        // Fallback: job Success pero Content vacío (p. ej. corrección de PatientID).
        $fallbackIds = self::findStudyIdsByFingerprintFallback($pacs, $log);
        if (!empty($fallbackIds)) {
            $fallbackCandidates = self::filterStudiesMatchingThisEdit($pacs, $fallbackIds, $log);
            if (count($fallbackCandidates) === 1) {
                error_log('[PACS_MANAGER][RESOLVE] UUID resuelto vía fallback /tools/find: ' . $fallbackCandidates[0]);
                return $fallbackCandidates[0];
            }
            if (count($fallbackCandidates) > 1) {
                error_log('[PACS_MANAGER][RESOLVE] Varias copias en fallback: ' . implode(', ', $fallbackCandidates));
                $picked = self::pickBestStudyCandidate($pacs, $fallbackCandidates, $jobData, $oldId);
                if ($picked) {
                    error_log('[PACS_MANAGER][RESOLVE] UUID elegido en fallback: ' . $picked);
                    return $picked;
                }
            }
            if (count($fallbackIds) === 1 && $fallbackIds[0] !== $oldId) {
                error_log('[PACS_MANAGER][RESOLVE] UUID único sin filtro huella en fallback: ' . $fallbackIds[0]);
                return $fallbackIds[0];
            }
        }

        error_log('[PACS_MANAGER][RESOLVE] No se pudo resolver estudio (job + fallback). scoped=' . json_encode($scoped));
        return null;
    }

    /**
     * Si hay varias copias con los mismos datos, elige una canónica (evita reconciliar contra la copia equivocada).
     */
    private static function pickBestStudyCandidate(
        OrthancPacsSender $pacs,
        array $candidates,
        array $jobData,
        ?string $excludeId
    ): ?string {
        $fromJob = self::extractNewStudyIdFromJob($jobData);
        if ($fromJob && in_array($fromJob, $candidates, true)) {
            return $fromJob;
        }
        $bestId = null;
        $bestDate = '';
        foreach ($candidates as $sid) {
            if ($sid === $excludeId) {
                continue;
            }
            $resp = $pacs->makeRequestWithRetry('/studies/' . urlencode($sid), 'GET', null, 10);
            if (!$resp['success']) {
                continue;
            }
            $date = $resp['data']['MainDicomTags']['StudyDate'] ?? '';
            $inst = (int) ($resp['data']['Instances'] ?? $resp['data']['NumberOfStudyRelatedInstances'] ?? 0);
            if ($date > $bestDate || ($date === $bestDate && $inst > 0)) {
                $bestDate = $date;
                $bestId = $sid;
            }
        }
        return $bestId ?? $candidates[0];
    }

    /**
     * Desactivado: no borrar copias automáticamente (riesgo alto sin Accession Number en ~99% de estudios).
     * Solo se elimina el estudio original si el usuario lo pidió explícitamente en post-proceso.
     */
    public static function pruneDuplicateOrthancCopies(
        OrthancPacsSender $pacs,
        string $canonicalStudyId,
        array $log,
        array $jobData = []
    ): array {
        error_log('[PACS_MANAGER][PRUNE] Desactivado: no se eliminan copias automáticas (canónico=' . $canonicalStudyId . ')');
        return ['pruned' => [], 'errors' => [], 'skipped' => 'prune_disabled'];
    }

    public static function fetchStudyInstanceUid(OrthancPacsSender $pacs, string $orthancStudyId): ?string {
        $resp = $pacs->makeRequestWithRetry('/studies/' . urlencode($orthancStudyId), 'GET', null, 15);
        if (!$resp['success'] || empty($resp['data']['MainDicomTags']['StudyInstanceUID'])) {
            return null;
        }
        return $resp['data']['MainDicomTags']['StudyInstanceUID'];
    }

    /**
     * @return array Respuesta JSON-friendly para job-status / reconcile
     */
    public static function processAfterOrthancSuccess(
        PDO $db,
        OrthancPacsSender $pacs,
        int $migrationLogId,
        array $jobData,
        ?bool $deleteOriginalOverride = null
    ): array {
        $log = PacsStudyModifyLog::getById($db, $migrationLogId);
        if (!$log) {
            return ['success' => false, 'error' => 'Registro de auditoría no encontrado'];
        }

        // Evitar reprocesar en cada poll (borrados/reconcile duplicados).
        // Excepción: status=failed con new_orthanc_study_id ya asignado manualmente → permitir continuar.
        $terminal = ['success', 'partial', 'failed'];
        if (in_array($log['status'] ?? '', $terminal, true)) {
            $alreadyDone = in_array($log['status'], ['success', 'partial'], true) && !empty($log['new_orthanc_study_id']);
            // Si falló pero el operador ya asignó el estudio nuevo manualmente, retomar el proceso.
            $failedWithNewStudy = ($log['status'] === 'failed') && !empty($log['new_orthanc_study_id']);
            if (!$alreadyDone && !$failedWithNewStudy) {
                return [
                    'success' => false,
                    'job_state' => 'Success',
                    'new_study_id' => $log['new_orthanc_study_id'] ?? null,
                    'new_study_instance_uid' => $log['new_study_instance_uid'] ?? null,
                    'original_study_id' => $log['old_orthanc_study_id'] ?? null,
                    'migration_log_id' => $migrationLogId,
                    'reconcile_status' => $log['reconcile_status'] ?? null,
                    'original_deleted' => ((int) ($log['original_deleted'] ?? 0)) === 1,
                    'note' => $log['error_message'] ?? 'Proceso finalizado con error sin estudio nuevo asignado',
                    'progress' => 100,
                    'already_processed' => true,
                ];
            }
            if ($alreadyDone) {
                return [
                    'success' => true,
                    'job_state' => 'Success',
                    'new_study_id' => $log['new_orthanc_study_id'] ?? null,
                    'new_study_instance_uid' => $log['new_study_instance_uid'] ?? null,
                    'original_study_id' => $log['old_orthanc_study_id'] ?? null,
                    'migration_log_id' => $migrationLogId,
                    'reconcile_status' => $log['reconcile_status'] ?? null,
                    'original_deleted' => ((int) ($log['original_deleted'] ?? 0)) === 1,
                    'note' => 'Proceso ya finalizado (auditoría).',
                    'progress' => 100,
                    'already_processed' => true,
                ];
            }
            // $failedWithNewStudy === true: continuar con la reconciliación
            error_log('[PACS_MANAGER][POST_PROCESS] Retomando log failed con new_orthanc_study_id manual: log=' . $migrationLogId
                . ' new=' . $log['new_orthanc_study_id']);
        }

        $oldOrthanc = $log['old_orthanc_study_id'];
        $oldSuid = $log['old_study_instance_uid'] ?? null;
        $deleteOriginal = $deleteOriginalOverride !== null
            ? $deleteOriginalOverride
            : ((int) ($log['delete_original_requested'] ?? 1) === 1);

        $jobDataForResolve = $jobData;
        $summary = self::decodeReconcileSummary($log);
        $priorAttempts = (int) ($summary['resolution_attempts'] ?? 0);
        if ($priorAttempts > 0 && !empty($log['orthanc_job_id'])) {
            $jobRefresh = $pacs->makeRequestWithRetry(
                '/jobs/' . urlencode($log['orthanc_job_id']),
                'GET',
                null,
                15
            );
            if ($jobRefresh['success'] && is_array($jobRefresh['data'])) {
                $jobDataForResolve = $jobRefresh['data'];
            }
        }

        $newOrthanc = self::resolveNewOrthancStudyId($pacs, $jobDataForResolve, $log);
        if (!$newOrthanc) {
            $newOrthanc = $log['new_orthanc_study_id'] ?? null;
        }

        if (!$newOrthanc) {
            error_log('[PACS_MANAGER][POST_PROCESS] No new study resolved. job=' . json_encode($jobDataForResolve, JSON_UNESCAPED_UNICODE));
            $attempts = $priorAttempts + 1;
            $summary['resolution_attempts'] = $attempts;

            if ($attempts < self::MAX_RESOLUTION_ATTEMPTS) {
                PacsStudyModifyLog::update($db, $migrationLogId, [
                    'status' => 'pending_resolution',
                    'reconcile_summary' => $summary,
                    'error_message' => null,
                ]);
                return [
                    'success' => true,
                    'job_state' => 'PendingResolution',
                    'resolution_pending' => true,
                    'resolution_attempt' => $attempts,
                    'resolution_max_attempts' => self::MAX_RESOLUTION_ATTEMPTS,
                    'migration_log_id' => $migrationLogId,
                    'original_study_id' => $oldOrthanc,
                    'progress' => min(95, 82 + ($attempts * 2)),
                    'note' => 'Job completado; localizando estudio nuevo ('
                        . $attempts . '/' . self::MAX_RESOLUTION_ATTEMPTS . ')…',
                ];
            }

            PacsStudyModifyLog::update($db, $migrationLogId, [
                'status' => 'failed',
                'reconcile_summary' => $summary,
                'error_message' => 'No se pudo determinar el nuevo orthanc_study_id (job ni fallback /tools/find)',
            ]);
            return [
                'success' => false,
                'error' => 'No se encontró el nuevo estudio en el job de Orthanc. Puede asignar el UUID manualmente en auditoría.',
                'migration_log_id' => $migrationLogId,
            ];
        }

        $newSuid = self::fetchStudyInstanceUid($pacs, $newOrthanc);

        PacsStudyModifyLog::update($db, $migrationLogId, [
            'status' => 'orthanc_success',
            'new_orthanc_study_id' => $newOrthanc,
            'new_study_instance_uid' => $newSuid,
            'reconcile_status' => 'pending',
            'error_message' => null,
        ]);

        // Mismo ID Orthanc (inusual): no hay par que migrar
        if ($newOrthanc === $oldOrthanc) {
            PacsStudyModifyLog::update($db, $migrationLogId, [
                'status' => 'success',
                'reconcile_status' => 'skipped',
                'completed_at' => date('Y-m-d H:i:s'),
            ]);
            return [
                'success' => true,
                'job_state' => 'Success',
                'new_study_id' => $newOrthanc,
                'new_study_instance_uid' => $newSuid,
                'migration_log_id' => $migrationLogId,
                'reconcile_status' => 'skipped',
                'note' => 'El ID de Orthanc no cambió; no se requirió reconciliación.',
                'original_deleted' => false,
            ];
        }

        PacsStudyModifyLog::update($db, $migrationLogId, ['status' => 'reconciling']);

        $reconcile = self::runFullReconciliation(
            $db,
            $pacs,
            $log,
            $oldOrthanc,
            $newOrthanc,
            $oldSuid,
            $newSuid
        );

        PacsStudyModifyLog::saveDetails($db, $migrationLogId, $reconcile['details'] ?? []);
        PacsStudyModifyLog::update($db, $migrationLogId, [
            'reconcile_status' => $reconcile['status'],
            'reconcile_summary' => $reconcile['summary'] ?? [],
            'reconcile_error' => $reconcile['error'],
        ]);

        $uidOk = (($reconcile['uid_reconcile']['status'] ?? '') === 'success');
        $reconcileOk = $uidOk;
        $pruneResult = ['pruned' => [], 'errors' => []];
        if ($reconcileOk) {
            $pruneResult = self::pruneDuplicateOrthancCopies($pacs, $newOrthanc, $log, $jobData);
        }
        if (!$reconcileOk) {
            $finalSt = ($reconcile['status'] ?? '') === 'partial' ? 'partial' : 'failed';
            PacsStudyModifyLog::update($db, $migrationLogId, [
                'status' => $finalSt,
                'error_message' => $reconcile['error'] ?? 'Reconciliación fallida o parcial',
            ]);
            return [
                'success' => false,
                'job_state' => 'Success',
                'new_study_id' => $newOrthanc,
                'new_study_instance_uid' => $newSuid,
                'migration_log_id' => $migrationLogId,
                'reconcile_status' => $reconcile['status'],
                'error' => 'Orthanc completó pero la reconciliación en BD no finalizó correctamente. El estudio original no fue eliminado.',
                'reconcile_error' => $reconcile['error'],
                'original_deleted' => false,
                'reconcile_summary' => $reconcile['summary'] ?? [],
            ];
        }

        $originalDeleted = false;
        $originalDeleteError = null;

        if ($deleteOriginal && $oldOrthanc !== $newOrthanc) {
            $del = $pacs->deleteStudy($oldOrthanc);
            if ($del['success'] || !empty($del['already_deleted'])) {
                $originalDeleted = true;
            } else {
                $originalDeleteError = $del['error'] ?? 'Error desconocido al eliminar original';
            }
        }

        $finalStatus = ($reconcile['status'] === 'partial') ? 'partial' : 'success';
        PacsStudyModifyLog::update($db, $migrationLogId, [
            'status' => $finalStatus,
            'original_deleted' => $deleteOriginal ? ($originalDeleted ? 1 : 0) : 0,
            'original_delete_error' => $originalDeleteError,
            'completed_at' => date('Y-m-d H:i:s'),
        ]);

        $note = 'Estudio modificado. Plataforma alineada al nuevo estudio.';
        $metaRows = (int) ($reconcile['metadata_reconcile']['summary']['total_rows_updated'] ?? 0);
        if ($metaRows > 0) {
            $note .= " Metadatos actualizados en BD ($metaRows filas).";
        }
        $docLinked = (int) ($reconcile['doc_reconcile']['summary']['linked'] ?? 0);
        if ($docLinked > 0) {
            $note .= " Serie DOC re-vinculada en $docLinked informe(s).";
        }
        if ($deleteOriginal) {
            $note .= $originalDeleted
                ? ' Copia anterior eliminada en PACS.'
                : ' No se pudo eliminar la copia anterior en PACS.';
        } else {
            $note .= ' Copia anterior conservada en PACS.';
        }
        if (($reconcile['status'] ?? '') === 'partial') {
            $note .= ' Reconciliación parcial: revisar auditoría.';
        }

        return [
            'success' => true,
            'job_state' => 'Success',
            'new_study_id' => $newOrthanc,
            'new_study_instance_uid' => $newSuid,
            'original_study_id' => $oldOrthanc,
            'migration_log_id' => $migrationLogId,
            'reconcile_status' => $reconcile['status'],
            'reconcile_summary' => $reconcile['summary'] ?? [],
            'original_deleted' => $originalDeleted,
            'original_delete_error' => $originalDeleteError,
            'delete_original_requested' => $deleteOriginal,
            'pruned_duplicate_studies' => $pruneResult['pruned'] ?? [],
            'note' => $note,
            'progress' => 100,
        ];
    }

    /**
     * Reintento manual de reconciliación desde auditoría (sin volver a modificar Orthanc).
     *
     * @param string $mode 'full' = UIDs + metadatos + DOC; 'metadata_only' = solo metadatos; 'doc_only' = solo serie DOC
     */
    public static function retryReconcile(
        PDO $db,
        OrthancPacsSender $pacs,
        int $migrationLogId,
        bool $attemptDeleteOriginal = false,
        string $mode = 'full'
    ): array {
        $mode = in_array($mode, ['full', 'metadata_only', 'doc_only'], true) ? $mode : 'full';

        $log = PacsStudyModifyLog::getById($db, $migrationLogId);
        if (!$log) {
            return ['success' => false, 'error' => 'Log no encontrado'];
        }
        $newOrthanc = $log['new_orthanc_study_id'] ?? null;
        if (!$newOrthanc && !empty($log['orthanc_job_id'])) {
            $jobResp = $pacs->makeRequestWithRetry(
                '/jobs/' . urlencode($log['orthanc_job_id']),
                'GET',
                null,
                15
            );
            if ($jobResp['success'] && ($jobResp['data']['State'] ?? '') === 'Success') {
                $newOrthanc = self::resolveNewOrthancStudyId($pacs, $jobResp['data'], $log);
                if ($newOrthanc) {
                    PacsStudyModifyLog::update($db, $migrationLogId, [
                        'new_orthanc_study_id' => $newOrthanc,
                        'new_study_instance_uid' => self::fetchStudyInstanceUid($pacs, $newOrthanc),
                    ]);
                    $log = PacsStudyModifyLog::getById($db, $migrationLogId) ?: $log;
                }
            }
        }
        if (!$newOrthanc) {
            return [
                'success' => false,
                'error' => 'No hay estudio nuevo registrado. Espere a que termine el job en Orthanc o verifique el estudio en PACS.',
            ];
        }
        $newSuid = $log['new_study_instance_uid'] ?? self::fetchStudyInstanceUid($pacs, $newOrthanc);
        if (!$newSuid) {
            $newSuid = self::fetchStudyInstanceUid($pacs, $newOrthanc);
            if ($newSuid) {
                PacsStudyModifyLog::update($db, $migrationLogId, ['new_study_instance_uid' => $newSuid]);
            }
        }

        if ($mode === 'metadata_only') {
            $meta = StudyMetadataReconciliation::reconcile(
                $db,
                $log,
                $log['old_orthanc_study_id'],
                $newOrthanc,
                $log['old_study_instance_uid'] ?? null,
                $newSuid
            );

            PacsStudyModifyLog::replaceMetadataDetails($db, $migrationLogId, $meta['details'] ?? []);

            $existingSummary = [];
            if (!empty($log['reconcile_summary'])) {
                $existingSummary = is_string($log['reconcile_summary'])
                    ? (json_decode($log['reconcile_summary'], true) ?: [])
                    : $log['reconcile_summary'];
            }
            $combinedSummary = array_merge($existingSummary, [
                'metadata' => $meta['summary'] ?? [],
                'metadata_resync_at' => date('Y-m-d H:i:s'),
            ]);

            PacsStudyModifyLog::update($db, $migrationLogId, [
                'reconcile_summary' => $combinedSummary,
                'reconcile_error' => $meta['error'],
            ]);

            $metaOk = in_array($meta['status'] ?? '', ['success', 'skipped'], true);

            return array_merge($meta, [
                'success' => $metaOk || ($meta['status'] ?? '') === 'partial',
                'migration_log_id' => $migrationLogId,
                'mode' => 'metadata_only',
                'note' => $metaOk
                    ? 'Metadatos sincronizados en BD (sin modificar Orthanc).'
                    : ($meta['error'] ?? 'Sincronización de metadatos con incidencias'),
                'original_deleted' => (int) ($log['original_deleted'] ?? 0) === 1,
            ]);
        }

        if ($mode === 'doc_only') {
            $doc = StudyPacsDocReconciliation::reconcile($db, $pacs, $newOrthanc, $newSuid);

            PacsStudyModifyLog::replaceDocDetails($db, $migrationLogId, $doc['details'] ?? []);

            $existingSummary = [];
            if (!empty($log['reconcile_summary'])) {
                $existingSummary = is_string($log['reconcile_summary'])
                    ? (json_decode($log['reconcile_summary'], true) ?: [])
                    : $log['reconcile_summary'];
            }
            $combinedSummary = array_merge($existingSummary, [
                'doc' => $doc['summary'] ?? [],
                'doc_resync_at' => date('Y-m-d H:i:s'),
            ]);

            PacsStudyModifyLog::update($db, $migrationLogId, [
                'reconcile_summary' => $combinedSummary,
                'reconcile_error' => $doc['error'],
            ]);

            $docOk = in_array($doc['status'] ?? '', ['success', 'skipped'], true);

            return array_merge($doc, [
                'success' => $docOk || ($doc['status'] ?? '') === 'partial',
                'migration_log_id' => $migrationLogId,
                'mode' => 'doc_only',
                'note' => $docOk
                    ? 'Serie DOC re-vinculada en BD (sin modificar Orthanc).'
                    : ($doc['error'] ?? 'Re-vinculación DOC con incidencias'),
                'original_deleted' => (int) ($log['original_deleted'] ?? 0) === 1,
            ]);
        }

        $deleteOriginal = $attemptDeleteOriginal && ((int) ($log['delete_original_requested'] ?? 0) === 1);

        $reconcile = self::runFullReconciliation(
            $db,
            $pacs,
            $log,
            $log['old_orthanc_study_id'],
            $newOrthanc,
            $log['old_study_instance_uid'] ?? null,
            $newSuid
        );

        PacsStudyModifyLog::saveDetails($db, $migrationLogId, $reconcile['details'] ?? []);
        PacsStudyModifyLog::update($db, $migrationLogId, [
            'reconcile_status' => $reconcile['status'],
            'reconcile_summary' => $reconcile['summary'] ?? [],
            'reconcile_error' => $reconcile['error'],
        ]);

        $uidOk = (($reconcile['uid_reconcile']['status'] ?? '') === 'success');
        $originalDeleted = (int) ($log['original_deleted'] ?? 0) === 1;
        $originalDeleteError = $log['original_delete_error'] ?? null;

        if ($reconcile['success'] || ($reconcile['status'] ?? '') === 'partial') {
            if ($deleteOriginal && !$originalDeleted && $log['old_orthanc_study_id'] !== $newOrthanc) {
                $del = $pacs->deleteStudy($log['old_orthanc_study_id']);
                $originalDeleted = $del['success'] || !empty($del['already_deleted']);
                if (!$originalDeleted) {
                    $originalDeleteError = $del['error'] ?? 'Error al eliminar';
                }
            }
            if ($uidOk && !empty($log['orthanc_job_id'])) {
                $jobResp = $pacs->makeRequestWithRetry('/jobs/' . urlencode($log['orthanc_job_id']), 'GET', null, 15);
                if ($jobResp['success']) {
                    self::pruneDuplicateOrthancCopies($pacs, $newOrthanc, $log, $jobResp['data'] ?? []);
                }
            }
            PacsStudyModifyLog::update($db, $migrationLogId, [
                'status' => ($reconcile['status'] === 'partial') ? 'partial' : 'success',
                'original_deleted' => $originalDeleted ? 1 : 0,
                'original_delete_error' => $originalDeleteError,
                'completed_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return array_merge($reconcile, [
            'success' => $reconcile['success'] || ($reconcile['status'] ?? '') === 'partial',
            'migration_log_id' => $migrationLogId,
            'mode' => 'full',
            'original_deleted' => $originalDeleted,
        ]);
    }
}
