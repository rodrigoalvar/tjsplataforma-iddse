<?php
/**
 * Análisis de impacto al eliminar un estudio de Orthanc (informes, audios, flags).
 * No elimina informes ni audios; solo informa y permite limpiar refs PACS huérfanas post-delete.
 */

class StudyDeleteImpact {

    /**
     * @return array{study_ids: string[], impact: array, severity: string, messages: string[]}
     */
    public static function analyze(PDO $db, string $orthancStudyId, ?string $studyInstanceUid = null): array {
        $orthancStudyId = trim($orthancStudyId);
        $studyInstanceUid = $studyInstanceUid ? trim($studyInstanceUid) : null;

        $studyIds = array_values(array_unique(array_filter([$orthancStudyId, $studyInstanceUid])));

        $impact = [
            'informes' => self::fetchInformes($db, $studyIds),
            'audios' => self::fetchAudios($db, $studyIds),
            'study_flags' => self::countStudyFlags($db, $studyIds),
            'study_assignments' => self::countStudyAssignments($db, $studyIds),
        ];

        $severity = self::computeSeverity($impact);
        $messages = self::buildMessages($impact);

        return [
            'study_ids' => $studyIds,
            'orthanc_study_id' => $orthancStudyId,
            'study_instance_uid' => $studyInstanceUid,
            'impact' => $impact,
            'severity' => $severity,
            'messages' => $messages,
        ];
    }

    /**
     * Limpia referencias PACS de informes tras borrar el estudio en Orthanc (serie/instancia ya no existen).
     */
    public static function clearInformesPacsRefs(PDO $db, string $orthancStudyId, ?string $studyInstanceUid = null): int {
        if (!self::tableExists($db, 'informes')) {
            return 0;
        }

        $studyIds = array_values(array_unique(array_filter([trim($orthancStudyId), $studyInstanceUid ? trim($studyInstanceUid) : null])));
        if (empty($studyIds)) {
            return 0;
        }

        $sets = [];
        foreach (['pacs_series_id', 'pacs_instance_id', 'pacs_study_id', 'fecha_enviado_pacs'] as $col) {
            if (self::columnExists($db, 'informes', $col)) {
                $sets[] = "`$col` = NULL";
            }
        }
        if (empty($sets)) {
            return 0;
        }

        $where = self::buildInformesWhere($db, $studyIds);
        if ($where === null) {
            return 0;
        }

        try {
            $sql = 'UPDATE informes SET ' . implode(', ', $sets) . ' WHERE ' . $where['sql'];
            $stmt = $db->prepare($sql);
            $stmt->execute($where['params']);
            return $stmt->rowCount();
        } catch (Exception $e) {
            error_log('[StudyDeleteImpact] clearInformesPacsRefs: ' . $e->getMessage());
            return 0;
        }
    }

  /** @param string[] $studyIds */
    private static function fetchInformes(PDO $db, array $studyIds): array {
        if (!self::tableExists($db, 'informes') || empty($studyIds)) {
            return self::emptyInformesBlock();
        }

        $where = self::buildInformesWhere($db, $studyIds);
        if ($where === null) {
            return self::emptyInformesBlock();
        }

        $cols = ['id', 'estado', 'patient_id', 'patient_name', 'study_description'];
        foreach (['pacs_study_id', 'pacs_series_id', 'pacs_instance_id', 'fecha_enviado_pacs', 'estudio_id', 'study_id', 'study_instance_uid'] as $c) {
            if (self::columnExists($db, 'informes', $c)) {
                $cols[] = $c;
            }
        }

        $sql = 'SELECT ' . implode(', ', $cols) . ' FROM informes WHERE ' . $where['sql'] . ' ORDER BY id DESC LIMIT 50';
        $stmt = $db->prepare($sql);
        $stmt->execute($where['params']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = [];
        $finalizados = 0;
        $borradores = 0;
        $enPacs = 0;

        foreach ($rows as $row) {
            $estado = strtolower(trim((string) ($row['estado'] ?? '')));
            $isFinal = in_array($estado, ['finalizado', 'firmado', 'validado', 'cerrado'], true);
            $isDraft = in_array($estado, ['borrador', 'draft', ''], true) || (!$isFinal && $estado !== '');
            $hasPacs = self::informeEnPacs($row);

            if ($isFinal) {
                $finalizados++;
            }
            if ($isDraft || $estado === 'borrador') {
                $borradores++;
            }
            if ($hasPacs) {
                $enPacs++;
            }

            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'estado' => $row['estado'] ?? null,
                'patient_id' => $row['patient_id'] ?? null,
                'patient_name' => $row['patient_name'] ?? null,
                'study_description' => $row['study_description'] ?? null,
                'en_pacs' => $hasPacs,
                'pacs_series_id' => $row['pacs_series_id'] ?? null,
            ];
        }

        return [
            'count' => count($items),
            'finalizados' => $finalizados,
            'borradores' => $borradores,
            'en_pacs' => $enPacs,
            'items' => $items,
        ];
    }

    /** @param string[] $studyIds */
    private static function fetchAudios(PDO $db, array $studyIds): array {
        if (!self::tableExists($db, 'audios_informe') || empty($studyIds)) {
            return ['count' => 0, 'items' => []];
        }

        $ph = implode(',', array_fill(0, count($studyIds), '?'));
        $sql = "SELECT id, informe_id, estudio_id, estado, nombre_archivo, fecha_creacion
                FROM audios_informe
                WHERE estudio_id IN ($ph) AND (estado IS NULL OR estado != 'eliminado')
                ORDER BY fecha_creacion DESC LIMIT 50";
        $stmt = $db->prepare($sql);
        $stmt->execute($studyIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = array_map(static function ($row) {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'informe_id' => isset($row['informe_id']) ? (int) $row['informe_id'] : null,
                'estudio_id' => $row['estudio_id'] ?? null,
                'estado' => $row['estado'] ?? null,
                'nombre_archivo' => $row['nombre_archivo'] ?? null,
                'fecha_creacion' => $row['fecha_creacion'] ?? null,
            ];
        }, $rows);

        return ['count' => count($items), 'items' => $items];
    }

    /** @param string[] $studyIds */
    private static function countStudyFlags(PDO $db, array $studyIds): array {
        if (!self::tableExists($db, 'study_flags') || empty($studyIds)) {
            return ['count' => 0];
        }
        $conds = [];
        $params = [];
        foreach (['study_id', 'orthanc_id', 'study_instance_uid'] as $col) {
            if (self::columnExists($db, 'study_flags', $col)) {
                $ph = implode(',', array_fill(0, count($studyIds), '?'));
                $conds[] = "`$col` IN ($ph)";
                $params = array_merge($params, $studyIds);
            }
        }
        if (empty($conds)) {
            return ['count' => 0];
        }
        try {
            $sql = 'SELECT COUNT(*) FROM study_flags WHERE (' . implode(' OR ', $conds) . ')';
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return ['count' => (int) $stmt->fetchColumn()];
        } catch (Exception $e) {
            return ['count' => 0];
        }
    }

    /** @param string[] $studyIds */
    private static function countStudyAssignments(PDO $db, array $studyIds): array {
        if (!self::tableExists($db, 'study_assignments') || empty($studyIds)) {
            return ['count' => 0];
        }
        $conds = [];
        $params = [];
        foreach (['study_id', 'orthanc_study_id', 'study_instance_uid'] as $col) {
            if (self::columnExists($db, 'study_assignments', $col)) {
                $ph = implode(',', array_fill(0, count($studyIds), '?'));
                $conds[] = "`$col` IN ($ph)";
                $params = array_merge($params, $studyIds);
            }
        }
        if (empty($conds)) {
            return ['count' => 0];
        }
        try {
            $sql = 'SELECT COUNT(*) FROM study_assignments WHERE (' . implode(' OR ', $conds) . ')';
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return ['count' => (int) $stmt->fetchColumn()];
        } catch (Exception $e) {
            return ['count' => 0];
        }
    }

    private static function computeSeverity(array $impact): string {
        $informes = $impact['informes'] ?? [];
        $audios = $impact['audios'] ?? [];
        $infCount = (int) ($informes['count'] ?? 0);
        $audCount = (int) ($audios['count'] ?? 0);
        $enPacs = (int) ($informes['en_pacs'] ?? 0);
        $finalizados = (int) ($informes['finalizados'] ?? 0);

        if ($infCount === 0 && $audCount === 0) {
            return 'none';
        }
        if ($enPacs > 0 || $finalizados > 0 || $audCount > 0) {
            return 'high';
        }
        if ($infCount > 0) {
            return 'medium';
        }
        return 'low';
    }

    private static function buildMessages(array $impact): array {
        $messages = [];
        $informes = $impact['informes'] ?? [];
        $audios = $impact['audios'] ?? [];
        $flags = (int) ($impact['study_flags']['count'] ?? 0);
        $assign = (int) ($impact['study_assignments']['count'] ?? 0);

        $infCount = (int) ($informes['count'] ?? 0);
        if ($infCount > 0) {
            $parts = ["$infCount informe(s) en la plataforma"];
            if (($informes['finalizados'] ?? 0) > 0) {
                $parts[] = (int) $informes['finalizados'] . ' finalizado(s)';
            }
            if (($informes['en_pacs'] ?? 0) > 0) {
                $parts[] = (int) $informes['en_pacs'] . ' con PDF/serie en PACS';
            }
            $messages[] = implode(' — ', $parts);
        }

        $audCount = (int) ($audios['count'] ?? 0);
        if ($audCount > 0) {
            $messages[] = "$audCount audio(s) de informe vinculado(s) al estudio";
        }

        if ($flags > 0) {
            $messages[] = "$flags flag(s) de estudio (prioridad / incompletos)";
        }
        if ($assign > 0) {
            $messages[] = "$assign asignación(es) de estudio";
        }

        if ($infCount > 0 || $audCount > 0) {
            $messages[] = 'Eliminar del PACS no borra informes ni audios en la plataforma; las referencias a imágenes quedarán desvinculadas del PACS.';
        }

        return $messages;
    }

    /** @param string[] $studyIds */
    private static function buildInformesWhere(PDO $db, array $studyIds): ?array {
        $studyIds = array_values(array_unique(array_filter($studyIds)));
        if (empty($studyIds)) {
            return null;
        }
        $conds = [];
        $params = [];
        foreach (['study_id', 'estudio_id', 'study_instance_uid'] as $col) {
            if (self::columnExists($db, 'informes', $col)) {
                $ph = implode(',', array_fill(0, count($studyIds), '?'));
                $conds[] = "`$col` IN ($ph)";
                $params = array_merge($params, $studyIds);
            }
        }
        if (empty($conds)) {
            return null;
        }
        return ['sql' => '(' . implode(' OR ', $conds) . ')', 'params' => $params];
    }

    private static function informeEnPacs(array $row): bool {
        if (!empty($row['pacs_series_id']) && trim((string) $row['pacs_series_id']) !== '') {
            return true;
        }
        if (!empty($row['pacs_instance_id']) && trim((string) $row['pacs_instance_id']) !== '') {
            return true;
        }
        if (!empty($row['fecha_enviado_pacs'])) {
            return true;
        }
        return false;
    }

    private static function emptyInformesBlock(): array {
        return [
            'count' => 0,
            'finalizados' => 0,
            'borradores' => 0,
            'en_pacs' => 0,
            'items' => [],
        ];
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
