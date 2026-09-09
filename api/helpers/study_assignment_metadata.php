<?php
/**
 * Completa metadata DICOM en asignaciones cuando faltan datos (front incompleto o caché).
 */

if (!function_exists('orthancStudyDetailsForAssignment')) {
    /**
     * Detalles de estudio desde Orthanc con caché estática por request.
     */
    function orthancStudyDetailsForAssignment(string $orthancStudyId): ?array {
        static $cache = [];
        if ($orthancStudyId === '') {
            return null;
        }
        if (array_key_exists($orthancStudyId, $cache)) {
            return $cache[$orthancStudyId];
        }
        require_once __DIR__ . '/../OrthancClient.php';
        try {
            $client = new OrthancClient();
            $st = $client->getServerStatus();
            if (($st['status'] ?? '') !== 'connected') {
                $cache[$orthancStudyId] = null;
                return null;
            }
            $details = $client->getStudyDetails($orthancStudyId);
            $cache[$orthancStudyId] = $details;
            return $details;
        } catch (Throwable $e) {
            error_log('[orthancStudyDetailsForAssignment] ' . $e->getMessage());
            $cache[$orthancStudyId] = null;
            return null;
        }
    }
}

if (!function_exists('studyAssignmentMetadataNeedsOrthancEnrich')) {
    function studyAssignmentMetadataNeedsOrthancEnrich(array $studyData): bool {
        $pn = trim((string)($studyData['patient_name'] ?? ''));
        $pid = trim((string)($studyData['patient_id'] ?? ''));
        $sd = trim((string)($studyData['study_description'] ?? ''));
        return $pn === '' || $pid === '' || $sd === '';
    }
}

if (!function_exists('enrichStudyDataFromOrthanc')) {
    /**
     * Fusiona campos vacíos de $studyData con respuesta de Orthanc.
     *
     * @param array $studyData datos parciales (ej. desde el front o una fila de BD)
     */
    function enrichStudyDataFromOrthanc(array $studyData, string $orthancStudyId): array {
        if ($orthancStudyId === '' || !studyAssignmentMetadataNeedsOrthancEnrich($studyData)) {
            return $studyData;
        }
        $details = orthancStudyDetailsForAssignment($orthancStudyId);
        if (!$details || !is_array($details)) {
            return $studyData;
        }

        $map = [
            'patient_name' => 'patient_name',
            'patient_id' => 'patient_id',
            'study_date' => 'study_date',
            'study_time' => 'study_time',
            'modality' => 'modality',
            'study_description' => 'study_description',
            'accession_number' => 'accession_number',
            'referring_physician' => 'referring_physician',
            'study_instance_uid' => 'study_instance_uid',
            'viewer_url' => 'viewer_url',
            'patient_birth_date' => 'patient_birth_date',
            'institution_name' => 'institution_name',
        ];
        foreach ($map as $from => $to) {
            $cur = trim((string)($studyData[$to] ?? ''));
            $val = $details[$from] ?? null;
            if ($cur === '' && $val !== null && $val !== '') {
                $studyData[$to] = $val;
            }
        }

        $scur = $studyData['series_count'] ?? null;
        if (($scur === null || $scur === '' || (int) $scur === 0) && isset($details['series_count'])) {
            $studyData['series_count'] = (int) $details['series_count'];
        }

        $oid = trim((string)($studyData['orthanc_study_id'] ?? ''));
        if ($oid === '' && !empty($details['orthanc_id'])) {
            $studyData['orthanc_study_id'] = $details['orthanc_id'];
        }

        return $studyData;
    }
}

if (!function_exists('studyAssignmentFallbackLooksLikeInstanceUid')) {
    /**
     * Evita usar StudyInstanceUID como ID interno de Orthanc en enriquecimiento.
     */
    function studyAssignmentFallbackLooksLikeInstanceUid(string $s): bool {
        $s = trim($s);
        if ($s === '' || strlen($s) < 10) {
            return false;
        }
        return (bool) preg_match('/^1\\.2\\.\\d/', $s);
    }
}

if (!function_exists('resolveOrthancIdForEnrich')) {
    function resolveOrthancIdForEnrich(array $studyData, string $fallbackStudyId): string {
        if (!empty($studyData['em_remote_only']) || !empty($studyData['em_pending_orthanc'])) {
            return '';
        }
        $oid = trim((string)($studyData['orthanc_study_id'] ?? ''));
        if ($oid !== '') {
            return $oid;
        }
        $alt = trim((string)($studyData['orthanc_id'] ?? ''));
        if ($alt !== '') {
            return $alt;
        }
        $fb = trim((string) $fallbackStudyId);
        if ($fb !== '' && studyAssignmentFallbackLooksLikeInstanceUid($fb)) {
            return '';
        }
        return $fb;
    }
}

if (!function_exists('persistSparseAssignmentMetadata')) {
    /**
     * Rellena filas activas que aún tienen metadata esencial vacía.
     */
    function persistSparseAssignmentMetadata(PDO $pdo, string $studyId, array $e): void {
        $pn = trim((string)($e['patient_name'] ?? ''));
        $sd = trim((string)($e['study_description'] ?? ''));
        if ($pn === '' && $sd === '') {
            return;
        }
        $sql = "UPDATE study_assignments SET
            patient_name = ?,
            patient_id = ?,
            study_date = ?,
            study_time = ?,
            modality = ?,
            study_description = ?,
            accession_number = ?,
            referring_physician = ?,
            study_instance_uid = ?,
            series_count = ?,
            viewer_url = ?,
            orthanc_study_id = ?,
            patient_birth_date = ?,
            institution_name = ?
            WHERE study_id = ? AND status = 'active'
            AND (
                patient_name IS NULL OR TRIM(patient_name) = ''
                OR study_description IS NULL OR TRIM(study_description) = ''
                OR patient_id IS NULL OR TRIM(patient_id) = ''
            )";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $e['patient_name'] ?? null,
            $e['patient_id'] ?? null,
            $e['study_date'] ?? null,
            $e['study_time'] ?? null,
            $e['modality'] ?? null,
            $e['study_description'] ?? null,
            $e['accession_number'] ?? null,
            $e['referring_physician'] ?? null,
            $e['study_instance_uid'] ?? null,
            (int)($e['series_count'] ?? 0),
            $e['viewer_url'] ?? null,
            $e['orthanc_study_id'] ?? null,
            $e['patient_birth_date'] ?? null,
            $e['institution_name'] ?? null,
            $studyId,
        ]);
    }
}
