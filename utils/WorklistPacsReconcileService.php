<?php
/**
 * Coteja turnos de worklist contra un nodo PACS (C-FIND / QIDO / local) por AccessionNumber.
 */

require_once __DIR__ . '/../modules/pacs-nodes-manager/PacsNodeClient.php';

class WorklistPacsReconcileService
{
    public static function ensureWorklistConfigPacsColumns(PDO $db): void
    {
        if (!self::tableExists($db, 'worklist_config')) {
            return;
        }
        self::addColumnIfMissing($db, 'worklist_config', 'pacs_reconcile_node_id',
            'ALTER TABLE `worklist_config` ADD COLUMN `pacs_reconcile_node_id` INT NULL DEFAULT NULL');
        self::addColumnIfMissing($db, 'worklist_config', 'pacs_reconcile_on_list_load',
            'ALTER TABLE `worklist_config` ADD COLUMN `pacs_reconcile_on_list_load` TINYINT(1) NOT NULL DEFAULT 0');
        self::addColumnIfMissing($db, 'worklist_config', 'pacs_reconcile_min_interval_minutes',
            'ALTER TABLE `worklist_config` ADD COLUMN `pacs_reconcile_min_interval_minutes` INT NOT NULL DEFAULT 30');
        self::addColumnIfMissing($db, 'worklist_config', 'pacs_reconcile_max_per_request',
            'ALTER TABLE `worklist_config` ADD COLUMN `pacs_reconcile_max_per_request` INT NOT NULL DEFAULT 40');
        self::addColumnIfMissing($db, 'worklist_config', 'pacs_reconcile_day_span_days',
            'ALTER TABLE `worklist_config` ADD COLUMN `pacs_reconcile_day_span_days` INT NOT NULL DEFAULT 45');
    }

    /**
     * @return array<int, array<string, mixed>> id de fila worklist → campos actualizados para fusionar en la respuesta
     */
    public static function reconcileAfterList(PDO $db, array $wlConfig, array $items): array
    {
        $overlay = [];

        if (empty($wlConfig['pacs_reconcile_on_list_load']) || empty($wlConfig['pacs_reconcile_node_id'])) {
            return $overlay;
        }

        $nodeId = (int)$wlConfig['pacs_reconcile_node_id'];
        if ($nodeId <= 0 || !self::tableExists($db, 'pacs_nodes')) {
            return $overlay;
        }

        $stmt = $db->prepare('SELECT * FROM pacs_nodes WHERE id = ? AND is_active = 1');
        $stmt->execute([$nodeId]);
        $node = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$node) {
            return $overlay;
        }

        $minInterval = max(5, min(1440, (int)($wlConfig['pacs_reconcile_min_interval_minutes'] ?? 30)));
        $maxPerReq = max(1, min(80, (int)($wlConfig['pacs_reconcile_max_per_request'] ?? 40)));
        $daySpan = max(1, min(365, (int)($wlConfig['pacs_reconcile_day_span_days'] ?? 45)));

        $now = new DateTimeImmutable('now');
        $from = $now->modify("-{$daySpan} days")->format('Y-m-d');
        $to = $now->modify('+2 days')->format('Y-m-d');

        $candidates = [];
        foreach ($items as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $acc = self::normalizeAccession($row['accession_number'] ?? '');
            if ($acc === '') {
                continue;
            }

            $sd = $row['scheduled_date'] ?? '';
            if ($sd === '' || $sd < $from || $sd > $to) {
                continue;
            }

            if (!empty($row['pacs_study_instance_uid'])) {
                continue;
            }

            $lastAt = $row['pacs_reconcile_last_at'] ?? null;
            if ($lastAt && self::minutesSince($lastAt) < $minInterval) {
                continue;
            }

            $st = $row['status'] ?? 'pending';
            if ($st === 'cancelled') {
                continue;
            }
            // Incluir completed sin datos PACS (p. ej. completado en portal antes de que llegue el estudio)
            if (!in_array($st, ['pending', 'scheduled', 'in_progress', 'completed'], true)) {
                continue;
            }

            $candidates[] = $row;
        }

        usort($candidates, static function (array $a, array $b): int {
            $ta = $a['pacs_reconcile_last_at'] ?? null;
            $tb = $b['pacs_reconcile_last_at'] ?? null;
            if ($ta === null || $ta === '') {
                if ($tb === null || $tb === '') {
                    return strcmp((string)($b['scheduled_date'] ?? ''), (string)($a['scheduled_date'] ?? ''));
                }
                return -1;
            }
            if ($tb === null || $tb === '') {
                return 1;
            }
            $cmp = strcmp((string)$ta, (string)$tb);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string)($b['scheduled_date'] ?? ''), (string)($a['scheduled_date'] ?? ''));
        });

        $candidates = array_slice($candidates, 0, $maxPerReq);
        if ($candidates === []) {
            return $overlay;
        }

        $client = new PacsNodeClient($db);

        $updateMatch = $db->prepare('
            UPDATE worklist SET
                pacs_study_at = ?,
                pacs_study_instance_uid = ?,
                pacs_reconcile_last_at = NOW(),
                pacs_reconcile_node_id = ?,
                pacs_seen_at = COALESCE(pacs_seen_at, NOW()),
                status = CASE WHEN status IN (\'pending\',\'scheduled\',\'in_progress\') THEN \'completed\' ELSE status END
            WHERE id = ?
        ');

        $touchOnly = $db->prepare('
            UPDATE worklist SET pacs_reconcile_last_at = NOW(), pacs_reconcile_node_id = ?
            WHERE id = ?
        ');

        foreach ($candidates as $row) {
            $id = (int)$row['id'];
            $acc = self::normalizeAccession($row['accession_number'] ?? '');
            $query = [
                'Level' => 'Study',
                'Query' => ['AccessionNumber' => $acc],
            ];

            try {
                $results = $client->executeCFind($node, $query);
            } catch (Throwable $e) {
                error_log('[WORKLIST_PACS_RECONCILE] ' . $e->getMessage());
                $touchOnly->execute([$nodeId, $id]);
                continue;
            }

            if (!is_array($results)) {
                $results = [];
            }

            $match = self::pickStudyMatch($results, $acc);

            if ($match) {
                $studyAt = self::combineStudyDateTime($match['StudyDate'] ?? '', $match['StudyTime'] ?? '');
                $uid = trim((string)($match['StudyInstanceUID'] ?? ''));
                $updateMatch->execute([
                    $studyAt,
                    $uid !== '' ? $uid : null,
                    $nodeId,
                    $id,
                ]);
                $prevSt = $row['status'] ?? 'pending';
                $newStatus = in_array($prevSt, ['pending', 'scheduled', 'in_progress'], true)
                    ? 'completed'
                    : $prevSt;
                $overlay[$id] = [
                    'pacs_study_at' => $studyAt,
                    'pacs_study_instance_uid' => $uid !== '' ? $uid : null,
                    'pacs_reconcile_last_at' => date('Y-m-d H:i:s'),
                    'pacs_reconcile_node_id' => (string)$nodeId,
                    'pacs_seen_at' => $row['pacs_seen_at'] ?? date('Y-m-d H:i:s'),
                    'status' => $newStatus,
                ];
            } else {
                // Throttle solo si el PACS no devolvió ningún estudio (evita bloquear 30 min si falló el matching)
                if ($results === []) {
                    $touchOnly->execute([$nodeId, $id]);
                }
            }
        }

        return $overlay;
    }

    /**
     * Elige el estudio que corresponde al accession de worklist (estricto, numérico relajado, o único resultado).
     *
     * @param array<int, mixed> $results
     */
    private static function pickStudyMatch(array $results, string $worklistAcc): ?array
    {
        $acc = self::normalizeAccession($worklistAcc);
        if ($acc === '') {
            return null;
        }

        $arrays = [];
        foreach ($results as $r) {
            if (is_array($r)) {
                $arrays[] = $r;
            }
        }
        if ($arrays === []) {
            return null;
        }

        foreach ($arrays as $r) {
            $racc = self::normalizeAccession($r['AccessionNumber'] ?? '');
            if ($racc !== '' && self::accessionsEquivalent($acc, $racc)) {
                return $r;
            }
        }

        if (count($arrays) === 1) {
            return $arrays[0];
        }

        return null;
    }

    private static function accessionsEquivalent(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if ($a === '' || $b === '') {
            return false;
        }
        if (ctype_digit($a) && ctype_digit($b)) {
            $ta = ltrim($a, '0');
            $tb = ltrim($b, '0');
            if ($ta === '' && $tb === '') {
                return true;
            }

            return $ta === $tb;
        }

        return strcasecmp($a, $b) === 0;
    }

    public static function normalizeAccession(string $a): string
    {
        $a = str_replace("\0", '', (string)$a);

        return preg_replace('/\s+/', '', trim($a));
    }

    private static function combineStudyDateTime(string $d, string $t): ?string
    {
        $d = trim($d);
        $t = trim($t);
        if ($d === '') {
            return null;
        }
        if (strlen($d) === 8 && ctype_digit($d)) {
            $d = substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2);
        }
        if ($t === '') {
            return $d . ' 00:00:00';
        }
        $digits = preg_replace('/\D/', '', $t);
        if (!is_string($digits) || strlen($digits) < 6) {
            return $d . ' 00:00:00';
        }
        $tm = substr($digits, 0, 2) . ':' . substr($digits, 2, 2) . ':' . substr($digits, 4, 2);

        return $d . ' ' . $tm;
    }

    private static function minutesSince(?string $dt): float
    {
        if ($dt === null || $dt === '') {
            return 99999.0;
        }
        $ts = strtotime($dt);

        return $ts === false ? 99999.0 : (time() - $ts) / 60.0;
    }

    private static function tableExists(PDO $db, string $name): bool
    {
        $s = $db->prepare('
            SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = ?
        ');
        $s->execute([$name]);

        return (int)$s->fetchColumn() > 0;
    }

    private static function addColumnIfMissing(PDO $db, string $table, string $column, string $alterSql): void
    {
        $stmt = $db->prepare('
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        ');
        $stmt->execute([$table, $column]);
        if ((int)$stmt->fetchColumn() === 0) {
            $db->exec($alterSql);
        }
    }
}
