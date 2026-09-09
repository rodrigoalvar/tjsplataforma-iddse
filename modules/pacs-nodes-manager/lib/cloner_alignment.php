<?php
/**
 * Alineación PACS Cloner: decide nivel C-MOVE (Study / Series / Instance) según política.
 * Requiere nodo con C-FIND DIMSE (executeDimseCFindRaw). El caller debe haber cargado PacsNodeClient.
 */

if (!function_exists('pacs_cloner_policy_alignment_strategy')) {
    function pacs_cloner_policy_alignment_strategy(array $policyRow) {
        $s = isset($policyRow['alignment_strategy']) ? (string) $policyRow['alignment_strategy'] : 'study';

        return in_array($s, ['study', 'series', 'instance'], true) ? $s : 'study';
    }
}

if (!function_exists('pacs_cloner_dimse_row_string')) {
    function pacs_cloner_dimse_row_string(array $row, array $keys) {
        foreach ($keys as $k) {
            if (isset($row[$k]) && trim((string) $row[$k]) !== '') {
                return trim((string) $row[$k]);
            }
        }
        $m = $row['MainDicomTags'] ?? [];
        if (is_array($m)) {
            foreach ($keys as $k) {
                if (isset($m[$k]) && trim((string) $m[$k]) !== '') {
                    return trim((string) $m[$k]);
                }
            }
        }

        return '';
    }
}

if (!function_exists('pacs_cloner_build_retrieve_move_options')) {
    /**
     * Opciones extra para pacs_nodes_run_retrieve: c_move_level, c_move_resources.
     * Array vacío = C-MOVE a nivel estudio (comportamiento por defecto).
     *
     * @param array $studyInstanceUIDs al menos un StudyInstanceUID
     *
     * @return array{c_move_level?:string,c_move_resources?:array<int,array<string,string>>}
     */
    function pacs_cloner_build_retrieve_move_options(PacsNodeClient $client, array $node, $strategy, array $studyInstanceUIDs) {
        $strategy = in_array($strategy, ['study', 'series', 'instance'], true) ? $strategy : 'study';
        $studyUid = trim((string) ($studyInstanceUIDs[0] ?? ''));
        if ($studyUid === '' || $strategy === 'study') {
            return [];
        }
        try {
            $backend = $client->resolveFindQueryBackend($node);
        } catch (Throwable $e) {
            return [];
        }
        if ($backend !== 'dimse') {
            return [];
        }

        $localInfo = $client->getLocalStudyInfo($studyUid);
        $localInst = (is_array($localInfo) && !empty($localInfo['found'])) ? (int) ($localInfo['instances'] ?? 0) : 0;
        if ($localInst < 1) {
            return [];
        }

        try {
            if ($strategy === 'series') {
                return pacs_cloner_build_series_move_options($client, $node, $studyUid);
            }
            if ($strategy === 'instance') {
                return pacs_cloner_build_instance_move_options($client, $node, $studyUid);
            }
        } catch (Throwable $e) {
            error_log('[cloner_alignment] ' . $e->getMessage());
        }

        return [];
    }
}

if (!function_exists('pacs_cloner_build_series_move_options')) {
    /**
     * @return array{c_move_level?:string,c_move_resources?:array}
     */
    function pacs_cloner_build_series_move_options(PacsNodeClient $client, array $node, $studyUid) {
        $localSeries = $client->getLocalSeriesInstanceCountsByStudyUid($studyUid);
        $raw = $client->executeDimseCFindRaw($node, 'Series', ['StudyInstanceUID' => $studyUid], [
            'StudyInstanceUID',
            'SeriesInstanceUID',
            'NumberOfSeriesRelatedInstances',
        ]);
        if (!is_array($raw) || count($raw) === 0) {
            error_log('[cloner_alignment] series C-FIND vacío; fallback Study');

            return [];
        }
        $remote = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ser = pacs_cloner_dimse_row_string($row, ['SeriesInstanceUID']);
            if ($ser === '') {
                continue;
            }
            $n = (int) pacs_cloner_dimse_row_string($row, ['NumberOfSeriesRelatedInstances']);
            if ($n < 1) {
                $n = 1;
            }
            $remote[$ser] = $n;
        }
        if (count($remote) === 0) {
            return [];
        }

        $resources = [];
        foreach ($remote as $serUid => $rCount) {
            $lCount = (int) ($localSeries[$serUid] ?? 0);
            if ($rCount > $lCount) {
                $resources[] = [
                    'StudyInstanceUID' => $studyUid,
                    'SeriesInstanceUID' => $serUid,
                ];
            }
        }

        if (count($resources) === 0) {
            return [];
        }
        $maxSeriesPerMove = max(5, min(80, (int) (getenv('PACS_CLONER_SERIES_MOVE_MAX') ?: 40)));
        if (count($resources) > $maxSeriesPerMove) {
            error_log('[cloner_alignment] demasiadas series incompletas (' . count($resources) . '); fallback Study');

            return [];
        }

        return [
            'c_move_level' => 'Series',
            'c_move_resources' => $resources,
        ];
    }
}

if (!function_exists('pacs_cloner_build_instance_move_options')) {
    /**
     * @return array{c_move_level?:string,c_move_resources?:array}
     */
    function pacs_cloner_build_instance_move_options(PacsNodeClient $client, array $node, $studyUid) {
        $maxFind = max(500, min(25000, (int) (getenv('PACS_CLONER_INSTANCE_FIND_MAX') ?: 12000)));
        $maxMove = max(50, min(800, (int) (getenv('PACS_CLONER_INSTANCE_MOVE_MAX') ?: 400)));

        $raw = $client->executeDimseCFindRaw($node, 'Instance', ['StudyInstanceUID' => $studyUid], [
            'StudyInstanceUID',
            'SeriesInstanceUID',
            'SOPInstanceUID',
        ]);
        if (!is_array($raw) || count($raw) === 0) {
            error_log('[cloner_alignment] instance C-FIND vacío; fallback Study');

            return [];
        }
        if (count($raw) > $maxFind) {
            error_log('[cloner_alignment] instance C-FIND demasiado grande (' . count($raw) . '); fallback Study');

            return [];
        }

        $remoteRows = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sop = pacs_cloner_dimse_row_string($row, ['SOPInstanceUID']);
            if ($sop === '') {
                continue;
            }
            $ser = pacs_cloner_dimse_row_string($row, ['SeriesInstanceUID']);
            $stu = pacs_cloner_dimse_row_string($row, ['StudyInstanceUID']) ?: $studyUid;
            $remoteRows[$sop] = ['StudyInstanceUID' => $stu, 'SeriesInstanceUID' => $ser, 'SOPInstanceUID' => $sop];
        }

        $localSet = $client->getLocalSopInstanceUidSetForStudy($studyUid, $maxFind);
        $missing = [];
        foreach ($remoteRows as $sop => $tags) {
            if (empty($localSet[$sop])) {
                $missing[$sop] = $tags;
            }
        }
        if (count($missing) === 0) {
            return [];
        }
        if (count($missing) > $maxMove) {
            error_log('[cloner_alignment] demasiadas instancias faltantes (' . count($missing) . '); fallback Study');

            return [];
        }

        $resources = [];
        foreach ($missing as $tags) {
            $r = ['StudyInstanceUID' => $tags['StudyInstanceUID']];
            if ($tags['SeriesInstanceUID'] !== '') {
                $r['SeriesInstanceUID'] = $tags['SeriesInstanceUID'];
            }
            $r['SOPInstanceUID'] = $tags['SOPInstanceUID'];
            $resources[] = $r;
        }

        return [
            'c_move_level' => 'Instance',
            'c_move_resources' => $resources,
        ];
    }
}
