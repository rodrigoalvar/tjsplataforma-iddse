<?php
/**
 * Helpers compartidos para estado de transcripción de audios por informe.
 */

if (!defined('INFORMES_TX_STUCK_MINUTES')) {
    define('INFORMES_TX_STUCK_MINUTES', 3);
}
if (!defined('INFORMES_TX_PENDING_MINUTES')) {
    define('INFORMES_TX_PENDING_MINUTES', 5);
}

if (!function_exists('informesTxTablesExist')) {
    function informesTxTablesExist(PDO $db): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        try {
            $hasQueue = $db->query("SHOW TABLES LIKE 'ai_transcription_queue'")->rowCount() > 0;
            $hasTrans = $db->query("SHOW TABLES LIKE 'ai_transcriptions'")->rowCount() > 0;
            $cache = $hasQueue && $hasTrans;
        } catch (Exception $e) {
            $cache = false;
        }
        return $cache;
    }
}

if (!function_exists('informesAudioHasEstadoColumn')) {
    function informesAudioHasEstadoColumn(PDO $db): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        try {
            $cache = $db->query("SHOW COLUMNS FROM audios_informe LIKE 'estado'")->rowCount() > 0;
        } catch (Exception $e) {
            $cache = false;
        }
        return $cache;
    }
}

if (!function_exists('informesAudioEligibilitySql')) {
    /**
     * Condición SQL: audio activo, vinculado a informe y que espera transcripción.
     */
    function informesAudioEligibilitySql(bool $hasAudioActivo, bool $hasEstado): string
    {
        $parts = ['ai.informe_id IS NOT NULL'];
        if ($hasAudioActivo) {
            $parts[] = 'ai.activo = 1';
        }
        if ($hasEstado) {
            $parts[] = "(ai.estado IS NULL OR ai.estado NOT IN ('en_papelera', 'listo_workspace'))";
        }
        $parts[] = "NOT (
            (ai.transcripcion_texto IS NOT NULL AND TRIM(ai.transcripcion_texto) <> '')
            OR EXISTS (
                SELECT 1 FROM ai_transcriptions at_done
                WHERE at_done.audio_id = ai.id AND at_done.status = 'completed'
                LIMIT 1
            )
        )";
        return implode(' AND ', $parts);
    }
}

if (!function_exists('informesAudioHasTranscriptionSql')) {
    function informesAudioHasTranscriptionSql(): string
    {
        return "(
            (ai.transcripcion_texto IS NOT NULL AND TRIM(ai.transcripcion_texto) <> '')
            OR EXISTS (
                SELECT 1 FROM ai_transcriptions at_done
                WHERE at_done.audio_id = ai.id AND at_done.status = 'completed'
                LIMIT 1
            )
        )";
    }
}

if (!function_exists('buildInformeTranscriptionStatusSubquery')) {
    /**
     * Subquery agregada por informe_id con contadores de estado TX.
     */
    function buildInformeTranscriptionStatusSubquery(bool $hasAudioActivo, bool $hasEstado): string
    {
        $eligible = informesAudioEligibilitySql($hasAudioActivo, $hasEstado);
        $hasTx = informesAudioHasTranscriptionSql();
        $stuckMinutes = (int) INFORMES_TX_STUCK_MINUTES;
        $pendingMinutes = (int) INFORMES_TX_PENDING_MINUTES;

        $activeFilter = $hasAudioActivo ? ' AND ai.activo = 1' : '';

        return "
            SELECT
                ai.informe_id,
                SUM(CASE WHEN {$hasTx} THEN 1 ELSE 0 END) AS audios_transcribed,
                SUM(CASE
                    WHEN ({$eligible})
                         AND (
                            (
                                (SELECT q.status FROM ai_transcription_queue q WHERE q.audio_id = ai.id ORDER BY q.id DESC LIMIT 1) IN ('pending', 'processing')
                                AND NOT (
                                    (
                                        (SELECT q2.status FROM ai_transcription_queue q2 WHERE q2.audio_id = ai.id ORDER BY q2.id DESC LIMIT 1) = 'processing'
                                        AND (SELECT q2.started_at FROM ai_transcription_queue q2 WHERE q2.audio_id = ai.id ORDER BY q2.id DESC LIMIT 1) IS NOT NULL
                                        AND TIMESTAMPDIFF(MINUTE, (SELECT q2.started_at FROM ai_transcription_queue q2 WHERE q2.audio_id = ai.id ORDER BY q2.id DESC LIMIT 1), NOW()) >= {$stuckMinutes}
                                    )
                                    OR (
                                        (SELECT q3.status FROM ai_transcription_queue q3 WHERE q3.audio_id = ai.id ORDER BY q3.id DESC LIMIT 1) = 'pending'
                                        AND TIMESTAMPDIFF(MINUTE, (SELECT q3.created_at FROM ai_transcription_queue q3 WHERE q3.audio_id = ai.id ORDER BY q3.id DESC LIMIT 1), NOW()) >= {$pendingMinutes}
                                    )
                                    OR (
                                        EXISTS (
                                            SELECT 1 FROM ai_transcriptions atp
                                            WHERE atp.audio_id = ai.id AND atp.status = 'processing'
                                              AND TIMESTAMPDIFF(MINUTE, atp.updated_at, NOW()) >= {$stuckMinutes}
                                            LIMIT 1
                                        )
                                    )
                                )
                            )
                         )
                    THEN 1 ELSE 0 END) AS audios_tx_pending,
                SUM(CASE
                    WHEN ({$eligible})
                         AND (
                            (
                                (SELECT q.status FROM ai_transcription_queue q WHERE q.audio_id = ai.id ORDER BY q.id DESC LIMIT 1) = 'processing'
                                AND (SELECT q.started_at FROM ai_transcription_queue q WHERE q.audio_id = ai.id ORDER BY q.id DESC LIMIT 1) IS NOT NULL
                                AND TIMESTAMPDIFF(MINUTE, (SELECT q.started_at FROM ai_transcription_queue q WHERE q.audio_id = ai.id ORDER BY q.id DESC LIMIT 1), NOW()) >= {$stuckMinutes}
                            )
                            OR (
                                (SELECT q.status FROM ai_transcription_queue q WHERE q.audio_id = ai.id ORDER BY q.id DESC LIMIT 1) = 'pending'
                                AND TIMESTAMPDIFF(MINUTE, (SELECT q.created_at FROM ai_transcription_queue q WHERE q.audio_id = ai.id ORDER BY q.id DESC LIMIT 1), NOW()) >= {$pendingMinutes}
                            )
                            OR EXISTS (
                                SELECT 1 FROM ai_transcriptions atp
                                WHERE atp.audio_id = ai.id AND atp.status = 'processing'
                                  AND TIMESTAMPDIFF(MINUTE, atp.updated_at, NOW()) >= {$stuckMinutes}
                                LIMIT 1
                            )
                         )
                    THEN 1 ELSE 0 END) AS audios_tx_stuck,
                SUM(CASE
                    WHEN ({$eligible})
                         AND (SELECT q.status FROM ai_transcription_queue q WHERE q.audio_id = ai.id ORDER BY q.id DESC LIMIT 1) = 'failed'
                    THEN 1 ELSE 0 END) AS audios_tx_failed
            FROM audios_informe ai
            WHERE ai.informe_id IS NOT NULL{$activeFilter}
            GROUP BY ai.informe_id
        ";
    }
}

if (!function_exists('deriveInformeTxStatus')) {
    function deriveInformeTxStatus(array $row): string
    {
        $stuck = (int) ($row['audios_tx_stuck'] ?? 0);
        $failed = (int) ($row['audios_tx_failed'] ?? 0);
        $pending = (int) ($row['audios_tx_pending'] ?? 0);
        $transcribed = (int) ($row['audios_transcribed'] ?? 0);
        $total = (int) ($row['total_audios'] ?? 0);

        if ($stuck > 0) {
            return 'stuck';
        }
        if ($failed > 0) {
            return 'failed';
        }
        if ($pending > 0) {
            return 'pending';
        }
        if ($total > 0 && $transcribed > 0 && $transcribed < $total) {
            return 'partial';
        }
        if ($total > 0 && $transcribed >= $total) {
            return 'ok';
        }
        return 'ok';
    }
}

if (!function_exists('normalizeInformeTxFields')) {
    function normalizeInformeTxFields(array &$informe): void
    {
        $informe['audios_transcribed'] = (int) ($informe['audios_transcribed'] ?? 0);
        $informe['audios_tx_pending'] = (int) ($informe['audios_tx_pending'] ?? 0);
        $informe['audios_tx_stuck'] = (int) ($informe['audios_tx_stuck'] ?? 0);
        $informe['audios_tx_failed'] = (int) ($informe['audios_tx_failed'] ?? 0);
        $informe['audios_tx_status'] = deriveInformeTxStatus($informe);
    }
}

if (!function_exists('applyDefaultInformeTxFields')) {
    function applyDefaultInformeTxFields(array &$informe): void
    {
        $informe['audios_transcribed'] = 0;
        $informe['audios_tx_pending'] = 0;
        $informe['audios_tx_stuck'] = 0;
        $informe['audios_tx_failed'] = 0;
        $informe['audios_tx_status'] = 'unknown';
    }
}

if (!function_exists('getInformeTranscriptionStatusByIds')) {
    /**
     * Obtiene resumen TX para un conjunto de informe IDs (polling).
     *
     * @return array<int, array<string, mixed>>
     */
    function getInformeTranscriptionStatusByIds(PDO $db, array $informeIds, bool $hasAudioActivo): array
    {
        $informeIds = array_values(array_unique(array_filter(array_map('intval', $informeIds))));
        if (empty($informeIds)) {
            return [];
        }

        if (!informesTxTablesExist($db)) {
            $result = [];
            foreach ($informeIds as $id) {
                $result[$id] = [
                    'audios_transcribed' => 0,
                    'audios_tx_pending' => 0,
                    'audios_tx_stuck' => 0,
                    'audios_tx_failed' => 0,
                    'audios_tx_status' => 'unknown',
                    'total_audios' => 0,
                ];
            }
            return $result;
        }

        $hasEstado = informesAudioHasEstadoColumn($db);
        $subquery = buildInformeTranscriptionStatusSubquery($hasAudioActivo, $hasEstado);
        $placeholders = implode(',', array_fill(0, count($informeIds), '?'));
        $activeFilter = $hasAudioActivo ? ' AND ai.activo = 1' : '';

        $sql = "
            SELECT
                i.id AS informe_id,
                COALESCE(ac.total_audios, 0) AS total_audios,
                COALESCE(tx.audios_transcribed, 0) AS audios_transcribed,
                COALESCE(tx.audios_tx_pending, 0) AS audios_tx_pending,
                COALESCE(tx.audios_tx_stuck, 0) AS audios_tx_stuck,
                COALESCE(tx.audios_tx_failed, 0) AS audios_tx_failed
            FROM informes i
            LEFT JOIN (
                SELECT ai.informe_id, COUNT(ai.id) AS total_audios
                FROM audios_informe ai
                WHERE ai.informe_id IN ({$placeholders}){$activeFilter}
                GROUP BY ai.informe_id
            ) ac ON ac.informe_id = i.id
            LEFT JOIN ({$subquery}) tx ON tx.informe_id = i.id
            WHERE i.id IN ({$placeholders})
        ";

        $params = array_merge($informeIds, $informeIds);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($informeIds as $id) {
            $result[$id] = [
                'audios_transcribed' => 0,
                'audios_tx_pending' => 0,
                'audios_tx_stuck' => 0,
                'audios_tx_failed' => 0,
                'audios_tx_status' => 'ok',
                'total_audios' => 0,
            ];
        }

        foreach ($rows as $row) {
            $id = (int) $row['informe_id'];
            normalizeInformeTxFields($row);
            $result[$id] = [
                'audios_transcribed' => (int) $row['audios_transcribed'],
                'audios_tx_pending' => (int) $row['audios_tx_pending'],
                'audios_tx_stuck' => (int) $row['audios_tx_stuck'],
                'audios_tx_failed' => (int) $row['audios_tx_failed'],
                'audios_tx_status' => $row['audios_tx_status'],
                'total_audios' => (int) $row['total_audios'],
            ];
        }

        return $result;
    }
}

if (!function_exists('computeAudioTxStatus')) {
    /**
     * Calcula estado TX detallado para un audio (modal de edición).
     *
     * @return array<string, mixed>
     */
    function computeAudioTxStatus(PDO $db, array $audio, bool $hasEstado = true): array
    {
        $defaults = [
            'tx_has_transcription' => false,
            'tx_queue_status' => null,
            'tx_minutes_waiting' => 0,
            'tx_is_stuck' => false,
            'tx_is_failed' => false,
            'tx_is_pending' => false,
        ];

        if (!informesTxTablesExist($db)) {
            $text = trim((string) ($audio['transcripcion_texto'] ?? ''));
            $defaults['tx_has_transcription'] = $text !== '';
            return $defaults;
        }

        $audioId = (int) ($audio['id'] ?? 0);
        if ($audioId <= 0) {
            return $defaults;
        }

        $transText = trim((string) ($audio['transcripcion_texto'] ?? ''));
        $transFromAi = trim((string) ($audio['transcription_from_ai'] ?? ''));
        $hasTx = $transText !== '' || $transFromAi !== '';

        if (!$hasTx) {
            $doneStmt = $db->prepare("SELECT id FROM ai_transcriptions WHERE audio_id = ? AND status = 'completed' LIMIT 1");
            $doneStmt->execute([$audioId]);
            $hasTx = (bool) $doneStmt->fetch(PDO::FETCH_ASSOC);
        }

        $estado = (string) ($audio['estado'] ?? '');
        $eligible = true;
        if ($hasEstado && in_array($estado, ['en_papelera', 'listo_workspace'], true)) {
            $eligible = false;
        }

        $queueStmt = $db->prepare("
            SELECT status, started_at, created_at
            FROM ai_transcription_queue
            WHERE audio_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $queueStmt->execute([$audioId]);
        $queue = $queueStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $txStmt = $db->prepare("
            SELECT status, updated_at
            FROM ai_transcriptions
            WHERE audio_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $txStmt->execute([$audioId]);
        $txRow = $txStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $stuckMinutes = (int) INFORMES_TX_STUCK_MINUTES;
        $pendingMinutes = (int) INFORMES_TX_PENDING_MINUTES;
        $minutesWaiting = 0;
        $isStuck = false;
        $isFailed = false;
        $isPending = false;
        $queueStatus = $queue['status'] ?? null;

        if (!$hasTx && $eligible) {
            if ($queue) {
                if ($queue['status'] === 'processing' && !empty($queue['started_at'])) {
                    $minutesWaiting = (int) floor((time() - strtotime($queue['started_at'])) / 60);
                    if ($minutesWaiting >= $stuckMinutes) {
                        $isStuck = true;
                    } else {
                        $isPending = true;
                    }
                } elseif ($queue['status'] === 'pending') {
                    $minutesWaiting = (int) floor((time() - strtotime($queue['created_at'])) / 60);
                    if ($minutesWaiting >= $pendingMinutes) {
                        $isStuck = true;
                    } else {
                        $isPending = true;
                    }
                } elseif ($queue['status'] === 'failed') {
                    $isFailed = true;
                }
            }

            if ($txRow && $txRow['status'] === 'processing' && !empty($txRow['updated_at'])) {
                $txMinutes = (int) floor((time() - strtotime($txRow['updated_at'])) / 60);
                $minutesWaiting = max($minutesWaiting, $txMinutes);
                if ($txMinutes >= $stuckMinutes) {
                    $isStuck = true;
                    $isPending = false;
                } elseif (!$isStuck && !$isFailed) {
                    $isPending = true;
                }
            }
        }

        return [
            'tx_has_transcription' => $hasTx,
            'tx_queue_status' => $queueStatus,
            'tx_minutes_waiting' => $minutesWaiting,
            'tx_is_stuck' => $isStuck,
            'tx_is_failed' => $isFailed,
            'tx_is_pending' => $isPending && !$isStuck,
        ];
    }
}

if (!function_exists('enrichAudioWithTxStatus')) {
    function enrichAudioWithTxStatus(PDO $db, array &$audio): void
    {
        $hasEstado = informesAudioHasEstadoColumn($db);
        $tx = computeAudioTxStatus($db, $audio, $hasEstado);
        foreach ($tx as $key => $value) {
            $audio[$key] = $value;
        }
    }
}
