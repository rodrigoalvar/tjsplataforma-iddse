<?php
/**
 * Inventario DIMSE (C-FIND Study/Series/Instance) → manifest JSON para UDV (manifestUrl).
 */

require_once __DIR__ . '/../../pacs-nodes-manager/PacsNodeClient.php';
require_once __DIR__ . '/StudyHistoryManifestSigning.php';

class StudyHistoryManifestService {
    /**
     * @param PDO   $db
     * @param array $node fila pacs_nodes (con wado_uri_base)
     * @param string $studyInstanceUID
     * @param array|null $urlContext si se pasa, las url del manifest apuntan al proxy HTTPS del portal (evita mixed content)
     *                                claves: use_wado_proxy (bool), public_base, hmac_secret, node_id, wado_instance_exp (unix)
     * @return array estructura manifest (series no vacío)
     */
    public static function buildManifest(PDO $db, array $node, $studyInstanceUID, array $urlContext = null) {
        $studyUid = trim((string) $studyInstanceUID);
        if ($studyUid === '') {
            throw new Exception('StudyInstanceUID vacío');
        }
        $wadoBase = isset($node['wado_uri_base']) ? trim((string) $node['wado_uri_base']) : '';
        if ($wadoBase === '') {
            throw new Exception('Falta wado_uri_base en el nodo para armar URLs WADO por instancia');
        }

        $useProxy = is_array($urlContext)
            && !empty($urlContext['use_wado_proxy'])
            && !empty($urlContext['public_base'])
            && !empty($urlContext['hmac_secret'])
            && (int) ($urlContext['node_id'] ?? 0) > 0;

        $client = new PacsNodeClient($db);

        $studyRows = $client->executeDimseCFindRaw(
            $node,
            'Study',
            ['StudyInstanceUID' => $studyUid],
            ['PatientName', 'PatientID', 'StudyDate', 'StudyTime', 'StudyDescription', 'StudyInstanceUID']
        );
        $meta = self::studyMetaFromRows($studyRows, $studyUid);

        $seriesRows = $client->executeDimseCFindRaw(
            $node,
            'Series',
            ['StudyInstanceUID' => $studyUid],
            ['SeriesInstanceUID', 'SeriesNumber', 'SeriesDescription', 'SeriesDate', 'SeriesTime', 'EchoNumber', 'EchoNumbers']
        );

        $seriesOut = [];
        foreach ($seriesRows as $srow) {
            if (!is_array($srow)) {
                continue;
            }
            $seriesUid = self::firstTag($srow, ['SeriesInstanceUID']);
            if ($seriesUid === '') {
                continue;
            }
            $instRows = $client->executeDimseCFindRaw(
                $node,
                'Instance',
                [
                    'StudyInstanceUID' => $studyUid,
                    'SeriesInstanceUID' => $seriesUid
                ],
                ['SOPInstanceUID', 'InstanceNumber']
            );
            $instances = [];
            foreach ($instRows as $irow) {
                if (!is_array($irow)) {
                    continue;
                }
                $sop = self::firstTag($irow, ['SOPInstanceUID']);
                if ($sop === '') {
                    continue;
                }
                $inum = self::firstTag($irow, ['InstanceNumber']);
                if ($inum === '') {
                    $inum = '0';
                }
                $instances[] = [
                    'instanceNumber' => $inum,
                    'url' => self::instanceBrowserUrl($wadoBase, $studyUid, $seriesUid, $sop, $useProxy ? $urlContext : null)
                ];
            }
            self::sortInstancesByNumber($instances);
            if (count($instances) === 0) {
                continue;
            }
            $seriesOut[] = [
                'seriesNumber' => self::firstTag($srow, ['SeriesNumber']) ?: '0',
                'seriesDescription' => self::firstTag($srow, ['SeriesDescription']),
                'seriesDate' => self::normalizeDicomDate(self::firstTag($srow, ['SeriesDate'])),
                'seriesTime' => self::normalizeDicomTime(self::firstTag($srow, ['SeriesTime'])),
                'echoNumber' => self::firstTag($srow, ['EchoNumber', 'EchoNumbers']) ?: '0',
                'instances' => $instances
            ];
        }

        self::sortSeriesByNumber($seriesOut);

        if (count($seriesOut) === 0) {
            throw new Exception('No se obtuvieron series con instancias vía DIMSE para este estudio');
        }

        return [
            'studyInstanceUID' => $meta['studyInstanceUID'],
            'studyDate' => $meta['studyDate'],
            'studyTime' => $meta['studyTime'],
            'studyDescription' => $meta['studyDescription'],
            'patientName' => $meta['patientName'],
            'patientId' => $meta['patientId'],
            'series' => $seriesOut
        ];
    }

    public static function nodeSupportsDimseManifest(array $node) {
        $t = $node['node_type'] ?? '';
        if ($t === 'dicomweb') {
            return false;
        }
        if ($t === 'local') {
            return true;
        }
        if ($t === 'dimse') {
            return !empty($node['aet']) && !empty($node['host']);
        }
        if ($t === 'hybrid') {
            return !empty($node['orthanc_node_id']) || (!empty($node['aet']) && !empty($node['host']));
        }
        return false;
    }

    private static function studyMetaFromRows(array $studyRows, $fallbackStudyUid) {
        $row = isset($studyRows[0]) && is_array($studyRows[0]) ? $studyRows[0] : [];
        $uid = self::firstTag($row, ['StudyInstanceUID']) ?: $fallbackStudyUid;
        return [
            'studyInstanceUID' => $uid,
            'studyDate' => self::normalizeDicomDate(self::firstTag($row, ['StudyDate'])),
            'studyTime' => self::normalizeDicomTime(self::firstTag($row, ['StudyTime'])),
            'studyDescription' => self::firstTag($row, ['StudyDescription']),
            'patientName' => self::firstTag($row, ['PatientName']),
            'patientId' => self::firstTag($row, ['PatientID'])
        ];
    }

    private static function firstTag(array $row, array $keys) {
        foreach ($keys as $k) {
            if (!array_key_exists($k, $row)) {
                continue;
            }
            $v = $row[$k];
            if ($v === null || $v === '') {
                continue;
            }
            if (is_array($v)) {
                $v = isset($v[0]) ? $v[0] : '';
            }
            $s = trim((string) $v);
            if ($s !== '') {
                return $s;
            }
        }
        return '';
    }

    private static function normalizeDicomDate($d) {
        $d = trim((string) $d);
        if ($d === '') {
            return '';
        }
        $d = str_replace('-', '', $d);
        if (strlen($d) >= 8 && ctype_digit(substr($d, 0, 8))) {
            return substr($d, 0, 8);
        }
        return $d;
    }

    private static function normalizeDicomTime($t) {
        $t = trim((string) $t);
        if ($t === '') {
            return '';
        }
        $t = preg_replace('/[^0-9]/', '', $t);
        if (strlen($t) >= 6 && ctype_digit(substr($t, 0, 6))) {
            return substr($t, 0, 6);
        }
        return $t;
    }

    /**
     * URL WADO hacia el PACS (solo servidor / red interna).
     */
    public static function buildUpstreamWadoInstanceUrl($wadoBase, $studyUid, $seriesUid, $sopUid) {
        $base = rtrim((string) $wadoBase, "?& \t\n\r\0\x0B");
        $sep = strpos($base, '?') !== false ? '&' : '?';
        return $base . $sep
            . 'requestType=WADO'
            . '&studyUID=' . rawurlencode($studyUid)
            . '&seriesUID=' . rawurlencode($seriesUid)
            . '&objectUID=' . rawurlencode($sopUid)
            . '&contentType=' . rawurlencode('application/dicom');
    }

    private static function instanceBrowserUrl($wadoBase, $studyUid, $seriesUid, $sopUid, $ctx) {
        if ($ctx === null) {
            return self::buildUpstreamWadoInstanceUrl($wadoBase, $studyUid, $seriesUid, $sopUid);
        }
        $publicBase = rtrim((string) $ctx['public_base'], '/');
        $secret = (string) $ctx['hmac_secret'];
        $nodeId = (int) $ctx['node_id'];
        $exp = isset($ctx['wado_instance_exp']) ? (int) $ctx['wado_instance_exp'] : (time() + 86400);
        $sig = StudyHistoryManifestSigning::signWadoInstance($nodeId, $studyUid, $seriesUid, $sopUid, $exp, $secret);
        $q = http_build_query([
            'n' => $nodeId,
            's' => $studyUid,
            'ser' => $seriesUid,
            'o' => $sopUid,
            'e' => $exp,
            'sig' => $sig
        ], '', '&', PHP_QUERY_RFC3986);
        return $publicBase . '/modules/study-history-manager/api/wado-instance.php?' . $q;
    }

    private static function sortSeriesByNumber(array &$series) {
        usort($series, function ($a, $b) {
            return self::cmpNumStr($a['seriesNumber'] ?? '', $b['seriesNumber'] ?? '');
        });
    }

    private static function sortInstancesByNumber(array &$instances) {
        usort($instances, function ($a, $b) {
            return self::cmpNumStr($a['instanceNumber'] ?? '', $b['instanceNumber'] ?? '');
        });
    }

    private static function cmpNumStr($x, $y) {
        $nx = is_numeric($x) ? 0 + $x : null;
        $ny = is_numeric($y) ? 0 + $y : null;
        if ($nx !== null && $ny !== null) {
            if ($nx === $ny) {
                return 0;
            }
            return ($nx < $ny) ? -1 : 1;
        }
        return strcmp((string) $x, (string) $y);
    }
}
