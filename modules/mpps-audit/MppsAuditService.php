<?php
/**
 * Servicio de auditoría MPPS (scaffold entrega 1).
 * diagnose() / listEvents() / getStats() / getMockEvents().
 */
declare(strict_types=1);

require_once __DIR__ . '/../../api/OrthancClient.php';

class MppsAuditService
{
    public const MODULE_VERSION = '1.0.0';

    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public static function tableExists(PDO $db, string $table): bool
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Clasifica el evento según matriz Worklist × MPPS × PACS.
     *
     * @param array{worklist_match?:string,state?:string,pacs_study_found?:int|bool} $row
     */
    public static function diagnose(array $row): string
    {
        $match = $row['worklist_match'] ?? 'unknown';
        $state = strtoupper(str_replace([' ', '-'], '_', (string) ($row['state'] ?? '')));
        $pacs = !empty($row['pacs_study_found']);

        $hasWl = ($match === 'matched');
        $noAccession = ($match === 'no_accession' || $match === 'orphan');
        $mppsDone = in_array($state, ['COMPLETED', 'DISCONTINUED'], true);
        $mppsActive = in_array($state, ['IN_PROGRESS', 'IN PROGRESS'], true) || $mppsDone;

        if ($hasWl && $mppsActive && $pacs) {
            return 'normal';
        }
        if ($hasWl && !$mppsActive && !$pacs) {
            return 'no_show';
        }
        if ($noAccession && $mppsActive && $pacs) {
            return 'orphan';
        }
        if ($mppsDone && !$pacs) {
            return 'ghost';
        }
        if ($mppsActive && !$pacs) {
            return 'pending_images';
        }
        return 'unknown';
    }

    public function countEvents(): int
    {
        if (!self::tableExists($this->db, 'mpps_events')) {
            return 0;
        }
        return (int) $this->db->query('SELECT COUNT(*) FROM mpps_events')->fetchColumn();
    }

    public function useMockWhenEmpty(): bool
    {
        if (!self::tableExists($this->db, 'mpps_audit_config')) {
            return true;
        }
        $v = $this->db->query('SELECT use_mock_when_empty FROM mpps_audit_config WHERE id = 1')->fetchColumn();
        return $v === false ? true : (bool) $v;
    }

    /**
     * Dataset de demostración (4 escenarios de la matriz).
     *
     * @return list<array<string,mixed>>
     */
    public static function getMockEvents(): array
    {
        $today = date('Y-m-d');
        return [
            [
                'id' => 0,
                'orthanc_mpps_id' => 'mock-normal-001',
                'state' => 'COMPLETED',
                'accession_number' => 'ORD-2026-1001',
                'patient_id' => '34901283',
                'patient_name' => 'PEREZ^JUAN',
                'modality' => 'CT',
                'station_name' => 'CT_SALA1',
                'study_instance_uid' => '1.2.840.113619.2.55.3.28311512.421.1001',
                'started_at' => $today . ' 09:10:00',
                'ended_at' => $today . ' 09:28:00',
                'worklist_id' => null,
                'worklist_match' => 'matched',
                'pacs_study_found' => 1,
                'audit_status' => 'normal',
                'is_mock' => true,
            ],
            [
                'id' => 0,
                'orthanc_mpps_id' => 'mock-orphan-002',
                'state' => 'COMPLETED',
                'accession_number' => 'EMERGENCIA',
                'patient_id' => '99887766',
                'patient_name' => 'GARCIA^ANA',
                'modality' => 'CR',
                'station_name' => 'RX_GUARDIA',
                'study_instance_uid' => '1.2.840.113619.2.55.3.28311512.421.1002',
                'started_at' => $today . ' 02:15:00',
                'ended_at' => $today . ' 02:22:00',
                'worklist_id' => null,
                'worklist_match' => 'orphan',
                'pacs_study_found' => 1,
                'audit_status' => 'orphan',
                'is_mock' => true,
            ],
            [
                'id' => 0,
                'orthanc_mpps_id' => 'mock-ghost-003',
                'state' => 'COMPLETED',
                'accession_number' => 'ORD-2026-0888',
                'patient_id' => '11223344',
                'patient_name' => 'LOPEZ^MARIA',
                'modality' => 'MR',
                'station_name' => 'MR_SALA2',
                'study_instance_uid' => '1.2.840.113619.2.55.3.28311512.421.1003',
                'started_at' => $today . ' 11:00:00',
                'ended_at' => $today . ' 11:45:00',
                'worklist_id' => null,
                'worklist_match' => 'matched',
                'pacs_study_found' => 0,
                'audit_status' => 'ghost',
                'is_mock' => true,
            ],
            [
                'id' => 0,
                'orthanc_mpps_id' => 'mock-noshow-004',
                'state' => 'UNKNOWN',
                'accession_number' => 'ORD-2026-0777',
                'patient_id' => '55667788',
                'patient_name' => 'MARTINEZ^PEDRO',
                'modality' => 'DX',
                'station_name' => null,
                'study_instance_uid' => null,
                'started_at' => null,
                'ended_at' => null,
                'worklist_id' => null,
                'worklist_match' => 'matched',
                'pacs_study_found' => 0,
                'audit_status' => 'no_show',
                'is_mock' => true,
                'note' => 'Orden en worklist sin evento MPPS (demo ausente)',
            ],
        ];
    }

    /**
     * @return array{rows:list<array>,total:int,is_mock:bool}
     */
    public function listEvents(int $limit = 50, int $offset = 0, ?string $auditStatus = null): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $count = $this->countEvents();

        if ($count === 0 && $this->useMockWhenEmpty()) {
            $rows = self::getMockEvents();
            if ($auditStatus !== null && $auditStatus !== '') {
                $rows = array_values(array_filter($rows, static function ($r) use ($auditStatus) {
                    return ($r['audit_status'] ?? '') === $auditStatus;
                }));
            }
            $total = count($rows);
            $rows = array_slice($rows, $offset, $limit);
            return ['rows' => $rows, 'total' => $total, 'is_mock' => true];
        }

        if (!self::tableExists($this->db, 'mpps_events')) {
            return ['rows' => [], 'total' => 0, 'is_mock' => false];
        }

        $where = '1=1';
        $params = [];
        if ($auditStatus !== null && $auditStatus !== '') {
            $where .= ' AND audit_status = ?';
            $params[] = $auditStatus;
        }

        $stmtCount = $this->db->prepare("SELECT COUNT(*) FROM mpps_events WHERE {$where}");
        $stmtCount->execute($params);
        $total = (int) $stmtCount->fetchColumn();

        $sql = "SELECT id, orthanc_mpps_id, state, accession_number, patient_id, patient_name,
                       modality, station_name, study_instance_uid, started_at, ended_at,
                       worklist_id, worklist_match, pacs_study_found, audit_status, created_at, updated_at
                FROM mpps_events
                WHERE {$where}
                ORDER BY COALESCE(started_at, created_at) DESC
                LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['is_mock'] = false;
            $r['pacs_study_found'] = (int) $r['pacs_study_found'];
        }
        unset($r);

        return ['rows' => $rows, 'total' => $total, 'is_mock' => false];
    }

    /**
     * @return array<string,int|bool>
     */
    public function getStats(): array
    {
        $list = $this->listEvents(200, 0, null);
        $rows = $list['rows'];
        $stats = [
            'total' => $list['total'],
            'in_progress' => 0,
            'orphan' => 0,
            'ghost' => 0,
            'normal' => 0,
            'no_show' => 0,
            'pending_images' => 0,
            'is_mock' => $list['is_mock'],
        ];
        foreach ($rows as $r) {
            $st = strtoupper(str_replace([' ', '-'], '_', (string) ($r['state'] ?? '')));
            if ($st === 'IN_PROGRESS') {
                $stats['in_progress']++;
            }
            $as = $r['audit_status'] ?? 'unknown';
            if (isset($stats[$as])) {
                $stats[$as]++;
            }
        }
        return $stats;
    }

    /**
     * Consulta en vivo a Orthanc GET /mpps (sin persistir).
     *
     * @return array{rows:list<array>,total:int,is_mock:bool,source:string,orthanc_error:?string}
     */
    public function listFromOrthanc(int $limit = 50, int $offset = 0, ?string $auditStatus = null): array
    {
        $limit = max(1, min(80, $limit));
        $offset = max(0, $offset);

        $client = new OrthancClient();

        try {
            $ids = $client->getMppsIds();
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Orthanc no responde MPPS (/mpps). ¿Versión ≥ 1.12.5 o plugin orthanc-mpps? ' . $e->getMessage(),
                0,
                $e
            );
        }

        $ids = array_reverse($ids);
        $slice = array_slice($ids, $offset, $limit);
        $wlCache = [];
        $pacsCache = [];
        $rows = [];

        foreach ($slice as $mppsId) {
            try {
                $raw = $client->getMppsById($mppsId);
            } catch (Throwable $e) {
                error_log('[mpps-audit] GET /mpps/' . $mppsId . ': ' . $e->getMessage());
                continue;
            }
            $row = $this->mapOrthancMppsToRow($mppsId, $raw);
            $acc = trim((string) ($row['accession_number'] ?? ''));
            $row['worklist_match'] = $this->matchWorklist($acc, $wlCache);
            $row['worklist_id'] = $wlCache['_id'][$acc] ?? null;

            $uid = trim((string) ($row['study_instance_uid'] ?? ''));
            $row['pacs_study_found'] = $this->studyExistsInOrthanc($client, $uid, $pacsCache) ? 1 : 0;
            $row['audit_status'] = self::diagnose($row);
            $row['is_mock'] = false;
            $row['source'] = 'orthanc';
            $rows[] = $row;
        }

        if ($auditStatus !== null && $auditStatus !== '') {
            $rows = array_values(array_filter($rows, static function ($r) use ($auditStatus) {
                return ($r['audit_status'] ?? '') === $auditStatus;
            }));
        }

        return [
            'rows' => $rows,
            'total' => count($ids),
            'is_mock' => false,
            'source' => 'orthanc',
            'orthanc_error' => null,
        ];
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public function mapOrthancMppsToRow(string $mppsId, array $raw): array
    {
        $tags = [];
        if (isset($raw['MainDicomTags']) && is_array($raw['MainDicomTags'])) {
            $tags = $raw['MainDicomTags'];
        } elseif (isset($raw['RequestedTags']) && is_array($raw['RequestedTags'])) {
            $tags = $raw['RequestedTags'];
        } else {
            $tags = $raw;
        }

        $state = (string) ($raw['State'] ?? $raw['Status'] ?? $tags['PerformedProcedureStepStatus'] ?? 'UNKNOWN');
        $state = strtoupper(str_replace([' ', '-'], '_', $state));

        $studyUid = self::pickTag($tags, ['StudyInstanceUID', '0020,000d', '0020,000D']);
        if ($studyUid === '') {
            $seq = $tags['ScheduledStepAttributesSequence'] ?? $raw['ScheduledStepAttributesSequence'] ?? null;
            if (is_array($seq)) {
                $first = isset($seq[0]) && is_array($seq[0]) ? $seq[0] : $seq;
                $studyUid = self::pickTag($first, ['StudyInstanceUID', '0020,000d', '0020,000D']);
            }
        }

        $station = self::pickTag($tags, ['StationName', '0008,1010', 'PerformedStationAETitle', '0040,0241']);
        if ($station === '') {
            $station = self::pickTag($raw, ['StationName']);
        }

        return [
            'id' => 0,
            'orthanc_mpps_id' => $mppsId,
            'state' => $state !== '' ? $state : 'UNKNOWN',
            'accession_number' => self::pickTag($tags, ['AccessionNumber', '0008,0050']) ?: null,
            'patient_id' => self::pickTag($tags, ['PatientID', '0010,0020']) ?: null,
            'patient_name' => self::pickTag($tags, ['PatientName', '0010,0010']) ?: null,
            'modality' => self::pickTag($tags, ['Modality', '0008,0060']) ?: null,
            'station_name' => $station !== '' ? $station : null,
            'study_instance_uid' => $studyUid !== '' ? $studyUid : null,
            'started_at' => self::dicomDateTime(
                self::pickTag($tags, ['PerformedProcedureStepStartDate', '0040,0244']),
                self::pickTag($tags, ['PerformedProcedureStepStartTime', '0040,0245'])
            ),
            'ended_at' => self::dicomDateTime(
                self::pickTag($tags, ['PerformedProcedureStepEndDate', '0040,0250']),
                self::pickTag($tags, ['PerformedProcedureStepEndTime', '0040,0251'])
            ),
            'worklist_id' => null,
            'worklist_match' => 'unknown',
            'pacs_study_found' => 0,
            'audit_status' => 'unknown',
            'is_mock' => false,
        ];
    }

    /**
     * @param list<string> $keys
     */
    public static function pickTag(array $src, array $keys): string
    {
        foreach ($keys as $k) {
            if (isset($src[$k]) && $src[$k] !== '' && $src[$k] !== null && !is_array($src[$k])) {
                return trim((string) $src[$k]);
            }
        }
        return '';
    }

    public static function dicomDateTime(string $date, string $time): ?string
    {
        $d = preg_replace('/\D/', '', $date) ?? '';
        if (strlen($d) < 8) {
            return null;
        }
        $ymd = substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2);
        $t = preg_replace('/\D/', '', $time) ?? '';
        if ($t === '') {
            return $ymd . ' 00:00:00';
        }
        $t = str_pad(substr($t, 0, 6), 6, '0');
        return $ymd . ' ' . substr($t, 0, 2) . ':' . substr($t, 2, 2) . ':' . substr($t, 4, 2);
    }

    /**
     * @param array<string, mixed> $cache
     */
    private function matchWorklist(string $accession, array &$cache): string
    {
        if ($accession === '') {
            return 'no_accession';
        }
        if (isset($cache[$accession])) {
            return $cache[$accession];
        }
        if (!self::tableExists($this->db, 'worklist')) {
            $cache[$accession] = 'unknown';
            return 'unknown';
        }
        $stmt = $this->db->prepare('SELECT id FROM worklist WHERE accession_number = ? LIMIT 1');
        $stmt->execute([$accession]);
        $id = $stmt->fetchColumn();
        if ($id) {
            $cache[$accession] = 'matched';
            $cache['_id'][$accession] = (int) $id;
        } else {
            $cache[$accession] = 'orphan';
            $cache['_id'][$accession] = null;
        }
        return $cache[$accession];
    }

    /**
     * @param array<string,bool> $cache
     */
    private function studyExistsInOrthanc(OrthancClient $client, string $uid, array &$cache): bool
    {
        if ($uid === '') {
            return false;
        }
        if (array_key_exists($uid, $cache)) {
            return $cache[$uid];
        }
        $found = $client->findStudyIdsByStudyInstanceUid($uid);
        $cache[$uid] = !empty($found);
        return $cache[$uid];
    }

    /**
     * @return array<string,int|bool|string>
     */
    public function getStatsFromList(array $list): array
    {
        $rows = $list['rows'] ?? [];
        $stats = [
            'total' => (int) ($list['total'] ?? count($rows)),
            'in_progress' => 0,
            'orphan' => 0,
            'ghost' => 0,
            'normal' => 0,
            'no_show' => 0,
            'pending_images' => 0,
            'is_mock' => !empty($list['is_mock']),
            'source' => $list['source'] ?? 'db',
        ];
        foreach ($rows as $r) {
            $st = strtoupper(str_replace([' ', '-'], '_', (string) ($r['state'] ?? '')));
            if ($st === 'IN_PROGRESS') {
                $stats['in_progress']++;
            }
            $as = $r['audit_status'] ?? 'unknown';
            if (isset($stats[$as]) && is_int($stats[$as])) {
                $stats[$as]++;
            }
        }
        return $stats;
    }

    /**
     * Stub entrega 2: upsert por orthanc_mpps_id.
     *
     * @param array<string,mixed> $payload
     */
    public function upsertFromPayload(array $payload): array
    {
        return [
            'success' => false,
            'message' => 'Ingesta real pendiente (entrega 2). Payload recibido.',
            'payload_keys' => array_keys($payload),
        ];
    }
}
