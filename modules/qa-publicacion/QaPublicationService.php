<?php
/**
 * Servicio central de Control de Calidad del portal.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/DbSchema.php';

final class QaPublicationService
{
    public const MODE_LISTA_NEGRA = 'lista_negra';
    public const MODE_LISTA_BLANCA = 'lista_blanca';
    public const MODE_HIBRIDO = 'hibrido';

    public const ESTADO_PENDIENTE = 'pendiente';
    public const ESTADO_PUBLICADO = 'publicado';
    public const ESTADO_BLOQUEADO = 'bloqueado';

    public static function isInstalled(PDO $db): bool
    {
        return QaDbSchema::tableExists($db, 'qa_config');
    }

    public static function isEnabled(PDO $db): bool
    {
        if (!self::isInstalled($db)) {
            return false;
        }
        $cfg = self::getConfig($db);
        return ($cfg['qa_enabled'] ?? '0') === '1';
    }

    /** @return array<string, string> */
    public static function getConfig(PDO $db): array
    {
        if (!self::isInstalled($db)) {
            return [];
        }
        $stmt = $db->query('SELECT config_key, config_value FROM qa_config');
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[$row['config_key']] = (string) ($row['config_value'] ?? '');
        }
        return $out;
    }

    public static function setConfigValue(PDO $db, string $key, string $value): void
    {
        $stmt = $db->prepare(
            'INSERT INTO qa_config (config_key, config_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
        );
        $stmt->execute([$key, $value]);
    }

    /**
     * Filtra estudios para el portal del paciente.
     *
     * @param array<int, array<string, mixed>> $studies
     * @return array<string, mixed>
     */
    public static function applyToPortal(PDO $db, string $patientId, array &$studies): array
    {
        if (!self::isInstalled($db)) {
            return ['enabled' => false, 'pendientes' => 0, 'ocultos' => 0, 'publicados' => count($studies)];
        }

        $cfg = self::getConfig($db);
        $enabled = self::isEnabled($db);
        $mode = $enabled ? ($cfg['qa_mode'] ?? self::MODE_LISTA_NEGRA) : self::MODE_LISTA_NEGRA;

        $studyUids = [];
        $informeIds = [];
        foreach ($studies as $study) {
            $uid = trim((string) ($study['study_instance_uid'] ?? ''));
            if ($uid !== '') {
                $studyUids[] = $uid;
            }
            if (!empty($study['informe_id'])) {
                $informeIds[] = (int) $study['informe_id'];
            }
            if (!empty($study['informes']) && is_array($study['informes'])) {
                foreach ($study['informes'] as $inf) {
                    if (!empty($inf['informe_id'])) {
                        $informeIds[] = (int) $inf['informe_id'];
                    }
                }
            }
        }

        $studyStatuses = self::loadStudyStatuses($db, array_unique($studyUids));
        $informeStatuses = self::loadInformeStatuses($db, array_unique($informeIds));

        $pendientes = 0;
        $ocultos = 0;
        $publicados = 0;
        $filtered = [];

        foreach ($studies as $study) {
            $uid = trim((string) ($study['study_instance_uid'] ?? ''));
            $isPacsPdfInforme = !empty($study['is_pacs_pdf_informe']);
            $isOrphanInforme = empty($uid) && !empty($study['informe_id']);

            if (!$enabled) {
                $filtered[] = $study;
                $publicados++;
                continue;
            }

            if ($uid !== '' && ($studyStatuses[$uid]['estado'] ?? '') === self::ESTADO_BLOQUEADO) {
                $ocultos++;
                continue;
            }

            if ($isOrphanInforme) {
                $informeId = (int) $study['informe_id'];
                $infState = self::resolveInformeEffectiveState(
                    $mode,
                    $informeStatuses[$informeId] ?? null,
                    $study,
                    true,
                    $cfg
                );
                if ($infState === self::ESTADO_PUBLICADO) {
                    $filtered[] = $study;
                    $publicados++;
                } elseif ($infState === self::ESTADO_PENDIENTE) {
                    $pendientes++;
                } else {
                    $ocultos++;
                }
                continue;
            }

            if ($isPacsPdfInforme) {
                $infState = self::resolveStudyAsInformeEffectiveState($mode, $uid, $studyStatuses, $study, $cfg);
                if ($infState === self::ESTADO_PUBLICADO) {
                    $filtered[] = $study;
                    $publicados++;
                } elseif ($infState === self::ESTADO_PENDIENTE) {
                    $pendientes++;
                } else {
                    $ocultos++;
                }
                continue;
            }

            $imgState = self::resolveStudyEffectiveState($mode, $uid, $studyStatuses, $study, $cfg);

            if ($imgState === self::ESTADO_BLOQUEADO) {
                $ocultos++;
                continue;
            }

            $study = self::filterInformesInStudy($mode, $study, $informeStatuses, $cfg);

            $hasVisibleInforme = !empty($study['has_informe']);
            $showImages = $imgState === self::ESTADO_PUBLICADO;
            $showInformeOnly = !$showImages && $hasVisibleInforme;

            if ($imgState === self::ESTADO_PENDIENTE && !$hasVisibleInforme) {
                $pendientes++;
                continue;
            }

            if ($showImages || $showInformeOnly) {
                if (!$showImages) {
                    unset($study['viewer_url'], $study['viewer_url_mobile'], $study['viewer_url_desktop']);
                    $study['qa_images_hidden'] = true;
                }
                $filtered[] = $study;
                $publicados++;
            } elseif ($imgState === self::ESTADO_PENDIENTE) {
                $pendientes++;
            } else {
                $ocultos++;
            }
        }

        $studies = array_values($filtered);

        return [
            'enabled' => $enabled,
            'mode' => $mode,
            'pendientes' => $pendientes,
            'ocultos' => $ocultos,
            'publicados' => $publicados,
            'message_pendiente' => 'Su estudio está pendiente de validación y publicación. Por favor, consulte más tarde o comuníquese con la institución.',
        ];
    }

    /** @param array<string, array<string, mixed>> $statuses */
    private static function resolveStudyEffectiveState(
        string $mode,
        string $studyUid,
        array $statuses,
        array $study,
        array $cfg
    ): string {
        $row = $statuses[$studyUid] ?? null;
        if ($row && ($row['estado'] ?? '') === self::ESTADO_BLOQUEADO) {
            return self::ESTADO_BLOQUEADO;
        }
        if ($row && ($row['estado'] ?? '') === self::ESTADO_PUBLICADO) {
            return self::ESTADO_PUBLICADO;
        }

        if ($mode === self::MODE_LISTA_NEGRA) {
            return self::ESTADO_PUBLICADO;
        }
        if ($mode === self::MODE_LISTA_BLANCA) {
            return self::ESTADO_PENDIENTE;
        }

        return self::hybridAutoStateForStudy($study, $cfg);
    }

    /** @param array<string, array<string, mixed>> $statuses */
    private static function resolveStudyAsInformeEffectiveState(
        string $mode,
        string $studyUid,
        array $statuses,
        array $study,
        array $cfg
    ): string {
        return self::resolveStudyEffectiveState($mode, $studyUid, $statuses, $study, $cfg);
    }

    /** @param array<string, mixed>|null $row */
    private static function resolveInformeEffectiveState(
        string $mode,
        ?array $row,
        array $study,
        bool $orphan,
        array $cfg
    ): string {
        if ($row && ($row['estado'] ?? '') === self::ESTADO_BLOQUEADO) {
            return self::ESTADO_BLOQUEADO;
        }
        if ($row && ($row['estado'] ?? '') === self::ESTADO_PUBLICADO) {
            return self::ESTADO_PUBLICADO;
        }

        if ($mode === self::MODE_LISTA_NEGRA) {
            return self::ESTADO_PUBLICADO;
        }
        if ($mode === self::MODE_LISTA_BLANCA) {
            return self::ESTADO_PENDIENTE;
        }

        if (($cfg['qa_hybrid_require_informe'] ?? '0') === '1' && $orphan) {
            return self::ESTADO_PUBLICADO;
        }

        return self::ESTADO_PENDIENTE;
    }

  private static function hybridAutoStateForStudy(array $study, array $cfg): string
    {
        if (($cfg['qa_hybrid_block_mixed'] ?? '1') === '1' && !empty($study['qa_mixed_series'])) {
            return self::ESTADO_PENDIENTE;
        }
        if (!empty($study['has_informe']) || ($cfg['qa_hybrid_require_informe'] ?? '0') !== '1') {
            return self::ESTADO_PUBLICADO;
        }
        return self::ESTADO_PENDIENTE;
    }

    /**
     * @param array<string, array<string, mixed>> $informeStatuses
     * @return array<string, mixed>
     */
    private static function filterInformesInStudy(
        string $mode,
        array $study,
        array $informeStatuses,
        array $cfg
    ): array {
        if (empty($study['informes']) || !is_array($study['informes'])) {
            if (!empty($study['informe_id'])) {
                $id = (int) $study['informe_id'];
                $state = self::resolveInformeEffectiveState(
                    $mode,
                    $informeStatuses[$id] ?? null,
                    $study,
                    false,
                    $cfg
                );
                if ($state !== self::ESTADO_PUBLICADO) {
                    $study['has_informe'] = false;
                    unset(
                        $study['informe_pdf_path'],
                        $study['informe_titulo'],
                        $study['informe_id'],
                        $study['pacs_series_id'],
                        $study['pacs_instance_id']
                    );
                }
            }
            return $study;
        }

        $visible = [];
        foreach ($study['informes'] as $inf) {
            $id = (int) ($inf['informe_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $state = self::resolveInformeEffectiveState(
                $mode,
                $informeStatuses[$id] ?? null,
                $study,
                false,
                $cfg
            );
            if ($state === self::ESTADO_PUBLICADO) {
                $visible[] = $inf;
            }
        }

        $study['informes'] = $visible;
        $study['has_informe'] = count($visible) > 0;
        if ($study['has_informe']) {
            $first = $visible[0];
            $study['informe_pdf_path'] = $first['pdf_path'] ?? '';
            $study['informe_titulo'] = $first['titulo'] ?? 'Informe Médico';
            $study['informe_id'] = $first['informe_id'] ?? null;
            $study['pacs_series_id'] = $first['pacs_series_id'] ?? null;
            $study['pacs_instance_id'] = $first['pacs_instance_id'] ?? null;
        } else {
            unset(
                $study['informe_pdf_path'],
                $study['informe_titulo'],
                $study['informe_id'],
                $study['pacs_series_id'],
                $study['pacs_instance_id']
            );
        }

        return $study;
    }

    /** @return array<string, array<string, mixed>> */
    public static function loadStudyStatuses(PDO $db, array $studyUids): array
    {
        if (empty($studyUids) || !self::isInstalled($db)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($studyUids), '?'));
        $stmt = $db->prepare(
            "SELECT * FROM qa_study_status WHERE study_instance_uid IN ({$placeholders})"
        );
        $stmt->execute(array_values($studyUids));
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[$row['study_instance_uid']] = $row;
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public static function loadInformeStatuses(PDO $db, array $informeIds): array
    {
        if (empty($informeIds) || !self::isInstalled($db)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($informeIds), '?'));
        $stmt = $db->prepare(
            "SELECT * FROM qa_informe_status WHERE informe_id IN ({$placeholders})"
        );
        $stmt->execute(array_values($informeIds));
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[(int) $row['informe_id']] = $row;
        }
        return $out;
    }

    public static function upsertStudyStatus(
        PDO $db,
        array $data,
        int $userId
    ): void {
        $stmt = $db->prepare(
            'INSERT INTO qa_study_status
                (orthanc_id, study_instance_uid, patient_id_pacs, accession_number, estado, motivo, motivo_detalle, revisado_por, revisado_en)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                orthanc_id = VALUES(orthanc_id),
                patient_id_pacs = VALUES(patient_id_pacs),
                accession_number = VALUES(accession_number),
                estado = VALUES(estado),
                motivo = VALUES(motivo),
                motivo_detalle = VALUES(motivo_detalle),
                revisado_por = VALUES(revisado_por),
                revisado_en = NOW()'
        );
        $stmt->execute([
            $data['orthanc_id'] ?? null,
            $data['study_instance_uid'],
            $data['patient_id_pacs'] ?? null,
            $data['accession_number'] ?? null,
            $data['estado'],
            $data['motivo'] ?? null,
            $data['motivo_detalle'] ?? null,
            $userId,
        ]);
    }

    public static function upsertInformeStatus(
        PDO $db,
        int $informeId,
        string $estado,
        ?string $motivo,
        ?string $motivoDetalle,
        int $userId,
        ?string $studyInstanceUid = null,
        bool $bajadoDePacs = false
    ): void {
        $stmt = $db->prepare(
            'INSERT INTO qa_informe_status
                (informe_id, study_instance_uid, estado, bajado_de_pacs, motivo, motivo_detalle, revisado_por, revisado_en)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                study_instance_uid = COALESCE(VALUES(study_instance_uid), study_instance_uid),
                estado = VALUES(estado),
                bajado_de_pacs = GREATEST(bajado_de_pacs, VALUES(bajado_de_pacs)),
                motivo = VALUES(motivo),
                motivo_detalle = VALUES(motivo_detalle),
                revisado_por = VALUES(revisado_por),
                revisado_en = NOW()'
        );
        $stmt->execute([
            $informeId,
            $studyInstanceUid,
            $estado,
            $bajadoDePacs ? 1 : 0,
            $motivo,
            $motivoDetalle,
            $userId,
        ]);
    }

    public static function logAction(PDO $db, array $data): void
    {
        if (!self::isInstalled($db)) {
            return;
        }
        $stmt = $db->prepare(
            'INSERT INTO qa_action_log
                (accion, target_type, orthanc_id, study_instance_uid, informe_id, pacs_series_id, pacs_instance_id, usuario_id, motivo, resultado, detalle)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $detalle = $data['detalle'] ?? null;
        if (is_array($detalle) || !empty($data['study_instance_uid']) || !empty($data['informe_id']) || !empty($data['orthanc_id'])) {
            $built = self::buildLogDetalle($db, $data);
            if ($built !== null) {
                $detalle = $built;
            } elseif (is_array($detalle)) {
                $detalle = json_encode($detalle, JSON_UNESCAPED_UNICODE);
            }
        }
        $stmt->execute([
            $data['accion'],
            $data['target_type'],
            $data['orthanc_id'] ?? null,
            $data['study_instance_uid'] ?? null,
            $data['informe_id'] ?? null,
            $data['pacs_series_id'] ?? null,
            $data['pacs_instance_id'] ?? null,
            $data['usuario_id'] ?? null,
            $data['motivo'] ?? null,
            $data['resultado'] ?? null,
            $detalle,
        ]);
    }

    /**
     * Estado efectivo del informe en UI/portal cuando el estudio está bloqueado.
     */
    public static function effectiveInformeState(?string $studyState, ?string $informeState): ?string
    {
        if ($studyState === self::ESTADO_BLOQUEADO) {
            return self::ESTADO_BLOQUEADO;
        }
        return $informeState;
    }

    /**
     * Construye la cola de revisión QA: estudios PACS (con o sin informe) + informes huérfanos.
     *
     * @param array<string, mixed> $opts
     * @return array<int, array<string, mixed>>
     */
    public static function buildQueueList(PDO $db, array $opts): array
    {
        $dateFrom = (string) ($opts['date_from'] ?? '');
        $dateTo = (string) ($opts['date_to'] ?? '');
        $search = trim((string) ($opts['q'] ?? ''));
        $estadoFilter = trim((string) ($opts['estado'] ?? ''));
        $tieneInforme = trim((string) ($opts['tiene_informe'] ?? ''));
        $checkMixed = !empty($opts['check_mixed']);
        $limit = min(500, max(1, (int) ($opts['limit'] ?? 200)));

        if ($dateFrom === '' || $dateTo === '') {
            $dateTo = date('Y-m-d');
            $dateFrom = date('Y-m-d', strtotime('-7 days'));
        }

        $cfg = self::getConfig($db);
        $mode = $cfg['qa_mode'] ?? self::MODE_LISTA_NEGRA;

        require_once __DIR__ . '/../../api/OrthancClient.php';
        require_once __DIR__ . '/../../api/informes/InformePacsRemover.php';

        $orthancStudies = [];
        try {
            $client = new OrthancClient();
            $orthancStudies = $client->getAllStudiesEfficient($dateFrom, $dateTo, null, null, false);
            if (!is_array($orthancStudies)) {
                $orthancStudies = [];
            }
        } catch (Throwable $e) {
            error_log('[QA] buildQueueList Orthanc: ' . $e->getMessage());
        }

        $byUid = [];
        foreach ($orthancStudies as $study) {
            $uid = trim((string) ($study['study_instance_uid'] ?? ''));
            if ($uid === '') {
                continue;
            }
            $byUid[$uid] = $study;
        }

        $informesByUid = self::loadLatestInformesForQueue($db, $dateFrom, $dateTo, array_keys($byUid));
        $orphanInformes = self::loadOrphanInformesForQueue($db, $dateFrom, $dateTo, array_keys($byUid));

        $studyUids = array_unique(array_merge(array_keys($byUid), array_keys($informesByUid)));
        $studyStatuses = self::loadStudyStatuses($db, $studyUids);

        $informeIds = [];
        foreach ($informesByUid as $inf) {
            $informeIds[] = (int) $inf['informe_id'];
        }
        foreach ($orphanInformes as $inf) {
            $informeIds[] = (int) $inf['informe_id'];
        }
        $informeStatuses = self::loadInformeStatuses($db, array_unique($informeIds));

        $items = [];
        $seenUids = [];

        foreach ($byUid as $uid => $study) {
            $seenUids[$uid] = true;
            $inf = $informesByUid[$uid] ?? null;
            $item = self::composeQueueItem(
                $db,
                $mode,
                $studyStatuses,
                $informeStatuses,
                $study,
                $inf,
                $checkMixed
            );
            if (self::queueItemMatchesFilters($item, $search, $estadoFilter, $tieneInforme)) {
                $items[] = $item;
            }
        }

        foreach ($informesByUid as $uid => $inf) {
            if (isset($seenUids[$uid])) {
                continue;
            }
            $item = self::composeQueueItemFromInforme(
                $db,
                $mode,
                $studyStatuses,
                $informeStatuses,
                $inf,
                false
            );
            if (self::queueItemMatchesFilters($item, $search, $estadoFilter, $tieneInforme)) {
                $items[] = $item;
            }
        }

        foreach ($orphanInformes as $inf) {
            $item = self::composeQueueItemFromInforme(
                $db,
                $mode,
                $studyStatuses,
                $informeStatuses,
                $inf,
                true
            );
            if (self::queueItemMatchesFilters($item, $search, $estadoFilter, $tieneInforme)) {
                $items[] = $item;
            }
        }

        usort($items, static function (array $a, array $b): int {
            return strcmp((string) ($b['sort_date'] ?? ''), (string) ($a['sort_date'] ?? ''));
        });

        return array_slice($items, 0, $limit);
    }

    /**
     * @param array<string, array<string, mixed>> $studyStatuses
     * @param array<int, array<string, mixed>> $informeStatuses
     * @param array<string, mixed> $study
     * @param array<string, mixed>|null $informe
     * @return array<string, mixed>
     */
    private static function composeQueueItem(
        PDO $db,
        string $mode,
        array $studyStatuses,
        array $informeStatuses,
        array $study,
        ?array $informe,
        bool $checkMixed
    ): array {
        $uid = trim((string) ($study['study_instance_uid'] ?? ''));
        $orthancId = (string) ($study['orthanc_id'] ?? $study['orthanc_study_id'] ?? $study['study_id'] ?? '');
        $studyDate = self::dicomDateToIso($study['study_date'] ?? '');

        $effectiveStudy = self::resolveQueueStudyState($mode, $uid, $studyStatuses);
        $informeId = $informe ? (int) ($informe['informe_id'] ?? 0) : 0;
        $rawInformeState = $informeId > 0
            ? self::resolveQueueInformeState($mode, $informeStatuses[$informeId] ?? null)
            : null;
        $effectiveInforme = self::effectiveInformeState($effectiveStudy, $rawInformeState);

        $mixed = null;
        if ($checkMixed && $orthancId !== '') {
            $mixed = self::detectMixedSeries($orthancId);
        }

        $enPacs = $informeId > 0
            ? InformePacsRemover::informeHasPacsInOrthanc($db, $informeId)
            : ($orthancId !== '');

        return [
            'informe_id' => $informeId > 0 ? $informeId : null,
            'tiene_informe' => $informeId > 0,
            'titulo' => $informe['titulo'] ?? ($study['study_description'] ?? ''),
            'patient_id' => $study['patient_id'] ?? ($informe['patient_id'] ?? ''),
            'patient_name' => $study['patient_name'] ?? ($informe['patient_name'] ?? ''),
            'orthanc_id' => $orthancId,
            'study_instance_uid' => $uid,
            'accession_number' => $study['accession_number'] ?? ($informe['accession_number'] ?? ''),
            'modality' => $study['modality'] ?? ($informe['modality'] ?? ''),
            'study_description' => $study['study_description'] ?? '',
            'study_date' => $studyDate,
            'fecha_creacion' => $informe['fecha_creacion'] ?? $studyDate,
            'sort_date' => $studyDate !== '' ? $studyDate : (string) ($informe['fecha_creacion'] ?? ''),
            'en_pacs' => $enPacs,
            'qa_estudio' => [
                'estado' => $effectiveStudy,
                'motivo' => $studyStatuses[$uid]['motivo'] ?? null,
                'revisado_en' => $studyStatuses[$uid]['revisado_en'] ?? null,
            ],
            'qa_informe' => [
                'estado' => $effectiveInforme,
                'motivo' => $informeId > 0 ? ($informeStatuses[$informeId]['motivo'] ?? null) : null,
                'bajado_de_pacs' => $informeId > 0 ? (bool) ($informeStatuses[$informeId]['bajado_de_pacs'] ?? false) : false,
                'revisado_en' => $informeId > 0 ? ($informeStatuses[$informeId]['revisado_en'] ?? null) : null,
            ],
            'mixed_series' => $mixed,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $studyStatuses
     * @param array<int, array<string, mixed>> $informeStatuses
     * @param array<string, mixed> $informe
     * @return array<string, mixed>
     */
    private static function composeQueueItemFromInforme(
        PDO $db,
        string $mode,
        array $studyStatuses,
        array $informeStatuses,
        array $informe,
        bool $orphan
    ): array {
        $uid = trim((string) ($informe['study_instance_uid'] ?? ''));
        $study = [
            'study_instance_uid' => $uid,
            'orthanc_id' => $informe['orthanc_id'] ?? $informe['study_id'] ?? '',
            'patient_id' => $informe['patient_id'] ?? '',
            'patient_name' => $informe['patient_name'] ?? '',
            'accession_number' => $informe['accession_number'] ?? '',
            'modality' => $informe['modality'] ?? '',
            'study_description' => $informe['titulo'] ?? '',
            'study_date' => '',
        ];
        return self::composeQueueItem($db, $mode, $studyStatuses, $informeStatuses, $study, $informe, false);
    }

    /** @param array<string, mixed> $item */
    private static function queueItemMatchesFilters(
        array $item,
        string $search,
        string $estadoFilter,
        string $tieneInforme
    ): bool {
        if ($tieneInforme === 'si' && empty($item['tiene_informe'])) {
            return false;
        }
        if ($tieneInforme === 'no' && !empty($item['tiene_informe'])) {
            return false;
        }

        if ($estadoFilter !== '') {
            $studyState = (string) ($item['qa_estudio']['estado'] ?? '');
            $infState = (string) ($item['qa_informe']['estado'] ?? '');
            if ($studyState !== $estadoFilter && $infState !== $estadoFilter) {
                return false;
            }
        }

        if ($search === '') {
            return true;
        }

        $haystack = strtolower(implode(' ', [
            (string) ($item['patient_id'] ?? ''),
            (string) ($item['patient_name'] ?? ''),
            (string) ($item['accession_number'] ?? ''),
            (string) ($item['study_instance_uid'] ?? ''),
            (string) ($item['study_description'] ?? ''),
            (string) ($item['titulo'] ?? ''),
        ]));

        return str_contains($haystack, strtolower($search));
    }

    /** @param array<string, array<string, mixed>> $statuses */
    private static function resolveQueueStudyState(string $mode, string $uid, array $statuses): string
    {
        $row = $uid !== '' ? ($statuses[$uid] ?? null) : null;
        if ($row && ($row['estado'] ?? '') !== '') {
            return (string) $row['estado'];
        }
        return $mode === self::MODE_LISTA_BLANCA
            ? self::ESTADO_PENDIENTE
            : self::ESTADO_PUBLICADO;
    }

    /** @param array<string, mixed>|null $row */
    private static function resolveQueueInformeState(string $mode, ?array $row): string
    {
        if ($row && ($row['estado'] ?? '') !== '') {
            return (string) $row['estado'];
        }
        return $mode === self::MODE_LISTA_BLANCA
            ? self::ESTADO_PENDIENTE
            : self::ESTADO_PUBLICADO;
    }

    /**
     * @param array<int, string> $studyUids
     * @return array<string, array<string, mixed>>
     */
    private static function loadLatestInformesForQueue(
        PDO $db,
        string $dateFrom,
        string $dateTo,
        array $studyUids
    ): array {
        $params = [$dateFrom, $dateTo];
        $uidClause = '';
        if (!empty($studyUids)) {
            $placeholders = implode(',', array_fill(0, count($studyUids), '?'));
            $uidClause = " OR i.study_instance_uid IN ({$placeholders})";
            $params = array_merge($params, array_values($studyUids));
        }

        $sql = "SELECT
                    i.id AS informe_id,
                    i.titulo,
                    i.patient_id,
                    i.patient_name,
                    i.study_id AS orthanc_id,
                    i.study_instance_uid,
                    i.accession_number,
                    i.modality,
                    i.fecha_creacion
                FROM informes i
                WHERE i.estado = 'finalizado'
                  AND (
                    (DATE(i.fecha_creacion) >= ? AND DATE(i.fecha_creacion) <= ?)
                    {$uidClause}
                  )
                ORDER BY i.fecha_creacion DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $uid = trim((string) ($row['study_instance_uid'] ?? ''));
            if ($uid === '') {
                continue;
            }
            if (!isset($out[$uid])) {
                $out[$uid] = $row;
            }
        }
        return $out;
    }

    /**
     * Informes finalizados en rango sin estudio PACS emparejado en la consulta Orthanc.
     *
     * @param array<int, string> $orthancUids
     * @return array<int, array<string, mixed>>
     */
    private static function loadOrphanInformesForQueue(
        PDO $db,
        string $dateFrom,
        string $dateTo,
        array $orthancUids
    ): array {
        $sql = "SELECT
                    i.id AS informe_id,
                    i.titulo,
                    i.patient_id,
                    i.patient_name,
                    i.study_id AS orthanc_id,
                    i.study_instance_uid,
                    i.accession_number,
                    i.modality,
                    i.fecha_creacion
                FROM informes i
                WHERE i.estado = 'finalizado'
                  AND DATE(i.fecha_creacion) >= ?
                  AND DATE(i.fecha_creacion) <= ?
                  AND (i.study_instance_uid IS NULL OR i.study_instance_uid = '')
                ORDER BY i.fecha_creacion DESC
                LIMIT 200";
        $stmt = $db->prepare($sql);
        $stmt->execute([$dateFrom, $dateTo]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function dicomDateToIso(?string $dicomDate): string
    {
        $raw = preg_replace('/\D/', '', (string) $dicomDate);
        if (strlen($raw) >= 8) {
            return substr($raw, 0, 4) . '-' . substr($raw, 4, 2) . '-' . substr($raw, 6, 2);
        }
        return '';
    }

    /**
     * Al bloquear un estudio, propagar bloqueo a sus informes asociados.
     */
    public static function cascadeBlockInformesForStudy(
        PDO $db,
        string $studyUid,
        int $userId,
        ?string $motivo = null,
        ?string $motivoDetalle = null
    ): int {
        if ($studyUid === '') {
            return 0;
        }
        $stmt = $db->prepare(
            "SELECT id, study_instance_uid FROM informes
             WHERE study_instance_uid = ? AND estado = 'finalizado'"
        );
        $stmt->execute([$studyUid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $count = 0;
        foreach ($rows as $row) {
            self::upsertInformeStatus(
                $db,
                (int) $row['id'],
                self::ESTADO_BLOQUEADO,
                $motivo,
                $motivoDetalle,
                $userId,
                $row['study_instance_uid'] ?? $studyUid,
                false
            );
            $count++;
        }
        return $count;
    }

    /**
     * Detecta series con distinto PatientID dentro de un estudio Orthanc.
     *
     * @return array{mixed:bool, patient_ids:array<int,string>, series:array<int,array<string,mixed>>}
     */
    public static function detectMixedSeries(string $orthancId): array
    {
        $result = ['mixed' => false, 'patient_ids' => [], 'series' => []];
        if ($orthancId === '') {
            return $result;
        }

        try {
            require_once __DIR__ . '/../../api/OrthancPacsSender.php';
            $sender = new OrthancPacsSender();
            $studyRes = $sender->makeRequestWithRetry('/studies/' . rawurlencode($orthancId));
            if (empty($studyRes['success']) || empty($studyRes['data'])) {
                return $result;
            }
            $studyData = $studyRes['data'];
            $expectedPatientId = trim((string) ($studyData['PatientMainDicomTags']['PatientID'] ?? ''));
            $patientIds = [];
            if ($expectedPatientId !== '') {
                $patientIds[$expectedPatientId] = true;
            }

            $seriesList = $studyData['Series'] ?? [];
            foreach ($seriesList as $seriesId) {
                $seriesRes = $sender->makeRequestWithRetry('/series/' . rawurlencode((string) $seriesId));
                $seriesData = $seriesRes['data'] ?? [];
                $seriesPatientId = trim((string) ($seriesData['PatientMainDicomTags']['PatientID'] ?? ''));
                if ($seriesPatientId === '' && !empty($seriesData['Instances'][0])) {
                    $instId = (string) $seriesData['Instances'][0];
                    $tagsRes = $sender->makeRequestWithRetry('/instances/' . rawurlencode($instId) . '/simplified-tags');
                    $seriesPatientId = trim((string) (($tagsRes['data']['PatientID'] ?? '')));
                }
                if ($seriesPatientId !== '') {
                    $patientIds[$seriesPatientId] = true;
                }
                $result['series'][] = [
                    'series_id' => (string) $seriesId,
                    'modality' => (string) ($seriesData['MainDicomTags']['Modality'] ?? ''),
                    'patient_id' => $seriesPatientId,
                ];
            }

            $ids = array_keys($patientIds);
            $result['patient_ids'] = $ids;
            $result['mixed'] = count($ids) > 1;
        } catch (Throwable $e) {
            error_log('[QA] detectMixedSeries: ' . $e->getMessage());
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    public static function getStudyStatusByUid(PDO $db, string $studyUid): ?array
    {
        if ($studyUid === '' || !self::isInstalled($db)) {
            return null;
        }
        $stmt = $db->prepare('SELECT * FROM qa_study_status WHERE study_instance_uid = ? LIMIT 1');
        $stmt->execute([$studyUid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function getInformeStatus(PDO $db, int $informeId): ?array
    {
        if (!self::isInstalled($db)) {
            return null;
        }
        $stmt = $db->prepare('SELECT * FROM qa_informe_status WHERE informe_id = ? LIMIT 1');
        $stmt->execute([$informeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Normaliza fecha de estudio para mostrar en auditoría.
     */
    public static function formatStudyDateForLog(?string $raw): string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/^\d{8}$/', $raw)) {
            return substr($raw, 0, 4) . '-' . substr($raw, 4, 2) . '-' . substr($raw, 6, 2);
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $m)) {
            return $m[1];
        }
        return $raw;
    }

    /**
     * Arma contexto de estudio para el registro de auditoría.
     *
     * @param array<string, mixed> $hints
     * @return array<string, string>
     */
    public static function resolveStudyContextForLog(PDO $db, array $hints): array
    {
        $context = [
            'patient_name' => trim((string) ($hints['patient_name'] ?? '')),
            'patient_id' => trim((string) ($hints['patient_id'] ?? $hints['patient_id_pacs'] ?? '')),
            'modality' => trim((string) ($hints['modality'] ?? '')),
            'study_date' => self::formatStudyDateForLog((string) ($hints['study_date'] ?? '')),
            'study_description' => trim((string) ($hints['study_description'] ?? $hints['titulo'] ?? '')),
            'accession_number' => trim((string) ($hints['accession_number'] ?? '')),
        ];

        $hasBasics = $context['patient_name'] !== '' && $context['modality'] !== '' && $context['study_date'] !== '';
        if ($hasBasics) {
            return array_filter($context, static fn($v) => $v !== '');
        }

        $informeId = (int) ($hints['informe_id'] ?? 0);
        $studyUid = trim((string) ($hints['study_instance_uid'] ?? ''));
        $orthancId = trim((string) ($hints['orthanc_id'] ?? ''));

        if ($informeId > 0) {
            $stmt = $db->prepare(
                'SELECT patient_name, patient_id, modality, titulo, fecha_creacion, study_instance_uid, study_id
                 FROM informes WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$informeId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $context['patient_name'] = $context['patient_name'] ?: trim((string) ($row['patient_name'] ?? ''));
                $context['patient_id'] = $context['patient_id'] ?: trim((string) ($row['patient_id'] ?? ''));
                $context['modality'] = $context['modality'] ?: trim((string) ($row['modality'] ?? ''));
                $context['study_description'] = $context['study_description'] ?: trim((string) ($row['titulo'] ?? ''));
                if ($context['study_date'] === '' && !empty($row['fecha_creacion'])) {
                    $context['study_date'] = self::formatStudyDateForLog((string) $row['fecha_creacion']);
                }
                if ($studyUid === '' && !empty($row['study_instance_uid'])) {
                    $studyUid = (string) $row['study_instance_uid'];
                }
                if ($orthancId === '' && !empty($row['study_id'])) {
                    $orthancId = (string) $row['study_id'];
                }
            }
        }

        if (($context['patient_name'] === '' || $context['modality'] === '') && $studyUid !== '') {
            $stmt = $db->prepare(
                'SELECT patient_name, patient_id, modality, titulo, fecha_creacion
                 FROM informes
                 WHERE study_instance_uid = ?
                 ORDER BY fecha_creacion DESC
                 LIMIT 1'
            );
            $stmt->execute([$studyUid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $context['patient_name'] = $context['patient_name'] ?: trim((string) ($row['patient_name'] ?? ''));
                $context['patient_id'] = $context['patient_id'] ?: trim((string) ($row['patient_id'] ?? ''));
                $context['modality'] = $context['modality'] ?: trim((string) ($row['modality'] ?? ''));
                $context['study_description'] = $context['study_description'] ?: trim((string) ($row['titulo'] ?? ''));
                if ($context['study_date'] === '' && !empty($row['fecha_creacion'])) {
                    $context['study_date'] = self::formatStudyDateForLog((string) $row['fecha_creacion']);
                }
            }
        }

        if (($context['patient_name'] === '' || $context['modality'] === '' || $context['study_date'] === '') && $orthancId !== '') {
            try {
                require_once __DIR__ . '/../../api/OrthancClient.php';
                $client = new OrthancClient();
                $study = $client->getStudyDetails($orthancId);
                if (is_array($study)) {
                    $context['patient_name'] = $context['patient_name'] ?: trim((string) ($study['patient_name'] ?? ''));
                    $context['patient_id'] = $context['patient_id'] ?: trim((string) ($study['patient_id'] ?? ''));
                    $context['modality'] = $context['modality'] ?: trim((string) ($study['modality'] ?? ''));
                    $context['study_description'] = $context['study_description'] ?: trim((string) ($study['study_description'] ?? ''));
                    $context['accession_number'] = $context['accession_number'] ?: trim((string) ($study['accession_number'] ?? ''));
                    if ($context['study_date'] === '') {
                        $context['study_date'] = self::formatStudyDateForLog((string) ($study['study_date'] ?? ''));
                    }
                }
            } catch (Throwable $e) {
                error_log('[QA] resolveStudyContextForLog Orthanc: ' . $e->getMessage());
            }
        }

        return array_filter($context, static fn($v) => $v !== '');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function enrichLogItems(PDO $db, array $rows): array
    {
        foreach ($rows as &$row) {
            $detalle = [];
            if (!empty($row['detalle'])) {
                if (is_string($row['detalle'])) {
                    $detalle = json_decode($row['detalle'], true) ?: [];
                } elseif (is_array($row['detalle'])) {
                    $detalle = $row['detalle'];
                }
            }

            $studyCtx = [];
            if (!empty($detalle['study']) && is_array($detalle['study'])) {
                $studyCtx = $detalle['study'];
            } else {
                $studyCtx = array_intersect_key($detalle, array_flip([
                    'patient_name', 'patient_id', 'modality', 'study_date', 'study_description', 'accession_number',
                ]));
            }

            $resolved = self::resolveStudyContextForLog($db, array_merge([
                'informe_id' => $row['informe_id'] ?? null,
                'study_instance_uid' => $row['study_instance_uid'] ?? null,
                'orthanc_id' => $row['orthanc_id'] ?? null,
            ], $studyCtx));

            $row['study_context'] = array_merge($resolved, array_filter($studyCtx, static fn($v) => $v !== '' && $v !== null));
            if (!empty($row['study_context']['study_date'])) {
                $row['study_context']['study_date'] = self::formatStudyDateForLog((string) $row['study_context']['study_date']);
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function buildLogDetalle(PDO $db, array $data): ?string
    {
        $study = self::resolveStudyContextForLog($db, $data);
        $payload = [];
        if (!empty($data['detalle']) && is_array($data['detalle'])) {
            $payload = $data['detalle'];
        }
        if (!empty($study)) {
            $payload['study'] = $study;
        }
        if (empty($payload)) {
            return null;
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
}
