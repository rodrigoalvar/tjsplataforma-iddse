<?php
/**
 * C-FIND en nodo remoto y fusión con lista local (estudios-manager modo mixto).
 */

if (!function_exists('estudios_mixed_dicom_date_to_iso')) {
    function estudios_mixed_dicom_date_to_iso($raw) {
        $d = preg_replace('/\D/', '', (string) $raw);
        if (strlen($d) < 8) {
            return '';
        }

        return substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2);
    }
}

if (!function_exists('estudios_mixed_dicom_time_to_hm')) {
    function estudios_mixed_dicom_time_to_hm($raw) {
        $t = preg_replace('/\D/', '', (string) $raw);
        if (strlen($t) < 4) {
            return '';
        }
        if (strlen($t) >= 6) {
            return substr($t, 0, 2) . ':' . substr($t, 2, 2) . ':' . substr($t, 4, 2);
        }

        return substr($t, 0, 2) . ':' . substr($t, 2, 2);
    }
}

if (!function_exists('estudios_mixed_merge_remote_into_local')) {
    /**
     * Añade estudios que solo existen en remoto (clave StudyInstanceUID).
     *
     * @param array      $localStudies filas como getAllStudiesEfficient
     * @param PDO|null   $db
     * @param int        $remoteNodeId pacs_nodes.id
     * @param string|null $dateFrom YYYY-MM-DD
     * @param string|null $dateTo   YYYY-MM-DD
     * @param string|null $patientId
     * @param int        $limit max filas C-FIND
     * @return array
     */
    function estudios_mixed_merge_remote_into_local(array $localStudies, $db, $remoteNodeId, $dateFrom, $dateTo, $patientId, $limit = 200) {
        $remoteNodeId = (int) $remoteNodeId;
        if ($remoteNodeId < 1 || !$db) {
            return $localStudies;
        }

        $localUids = [];
        foreach ($localStudies as $s) {
            $u = trim((string) ($s['study_instance_uid'] ?? ''));
            if ($u !== '') {
                $localUids[$u] = true;
            }
        }

        try {
            $st = $db->prepare('SELECT * FROM pacs_nodes WHERE id = ? AND is_active = 1');
            $st->execute([$remoteNodeId]);
            $node = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('[estudios_mixed] node: ' . $e->getMessage());

            return $localStudies;
        }

        if (!$node || ($node['node_type'] ?? '') === 'local') {
            return $localStudies;
        }

        $d0 = $dateFrom ? preg_replace('/\D/', '', substr((string) $dateFrom, 0, 10)) : '';
        $d1 = $dateTo ? preg_replace('/\D/', '', substr((string) $dateTo, 0, 10)) : '';
        if (strlen($d0) !== 8) {
            $d0 = gmdate('Ymd', strtotime('-7 days'));
        }
        if (strlen($d1) !== 8) {
            $d1 = gmdate('Ymd');
        }
        if ($d0 > $d1) {
            $t = $d0;
            $d0 = $d1;
            $d1 = $t;
        }
        $studyDateRange = $d0 . '-' . $d1;

        $findQuery = [
            'Level' => 'Study',
            'Query' => [
                'StudyDate' => $studyDateRange,
            ],
            'limit' => max(50, min(500, (int) $limit)),
        ];
        if ($patientId !== null && trim((string) $patientId) !== '') {
            $findQuery['Query']['PatientID'] = '*' . trim((string) $patientId) . '*';
        }

        require_once __DIR__ . '/../../modules/pacs-nodes-manager/PacsNodeClient.php';
        try {
            $client = new PacsNodeClient($db);
            $results = $client->executeCFind($node, $findQuery);
        } catch (Throwable $e) {
            error_log('[estudios_mixed] C-FIND: ' . $e->getMessage());

            return $localStudies;
        }

        if (!is_array($results)) {
            return $localStudies;
        }

        $extra = [];
        foreach ($results as $row) {
            if (!is_array($row)) {
                continue;
            }
            $suid = trim((string) ($row['StudyInstanceUID'] ?? ''));
            if ($suid === '' || isset($localUids[$suid])) {
                continue;
            }

            $pname = (string) ($row['PatientName'] ?? '');
            $pname = str_replace('^', ' ', $pname);
            $studyDateIso = estudios_mixed_dicom_date_to_iso($row['StudyDate'] ?? '');
            $studyTimeHm = estudios_mixed_dicom_time_to_hm($row['StudyTime'] ?? '');

            $extra[] = [
                'study_id' => $suid,
                'orthanc_id' => '',
                'patient_id' => (string) ($row['PatientID'] ?? ''),
                'patient_name' => trim($pname),
                'study_date' => $studyDateIso,
                'study_time' => $studyTimeHm,
                'study_description' => (string) ($row['StudyDescription'] ?? ''),
                'study_instance_uid' => $suid,
                'modality' => (string) ($row['ModalitiesInStudy'] ?? ''),
                'accession_number' => (string) ($row['AccessionNumber'] ?? ''),
                'referring_physician' => (string) ($row['ReferringPhysicianName'] ?? ''),
                'institution_name' => (string) ($row['InstitutionName'] ?? ''),
                'series_count' => (int) ($row['NumberOfStudyRelatedSeries'] ?? 0),
                'instances_count' => (int) ($row['NumberOfStudyRelatedInstances'] ?? 0),
                'em_remote_only' => true,
                'em_remote_node_id' => $remoteNodeId,
            ];
            $localUids[$suid] = true;
        }

        if ($extra === []) {
            return $localStudies;
        }

        return array_merge($localStudies, $extra);
    }
}
