<?php
/**
 * Estrategia de visualización de informes en Portal estudios v2.
 * Prioridad: disco (pdf_path) → PDF encapsulado en PACS → imágenes DOC multipágina.
 */

require_once __DIR__ . '/../../OrthancPacsSender.php';

class InformeViewStrategy {

    /**
     * @param array<string, mixed> $informe Fila o fragmento con pdf_path, pacs_* , estado
     * @return array{informe_view_strategy:string, informe_pacs:array<string, mixed>}
     */
    public static function build(array $informe, ?OrthancPacsSender $pacs = null, ?string $orthancStudyId = null): array {
        $emptyPacs = [
            'available' => false,
            'series_id' => null,
            'instance_id' => null,
            'content_type' => 'none',
            'page_count' => 0,
        ];

        $estado = strtolower(trim((string) ($informe['estado'] ?? 'finalizado')));
        if ($estado !== '' && $estado !== 'finalizado') {
            return [
                'informe_view_strategy' => 'none',
                'informe_pacs' => $emptyPacs,
            ];
        }

        $pdfPath = trim((string) ($informe['pdf_path'] ?? ''));
        if ($pdfPath !== '') {
            return [
                'informe_view_strategy' => 'disk',
                'informe_pacs' => self::buildPacsMeta($informe, $pacs, $orthancStudyId),
            ];
        }

        $seriesId = trim((string) ($informe['pacs_series_id'] ?? ''));
        $instanceId = trim((string) ($informe['pacs_instance_id'] ?? ''));
        if ($seriesId === '' && $instanceId === '' && $pacs !== null && $orthancStudyId) {
            $acc = trim((string) ($informe['accession_number'] ?? ''));
            $found = $pacs->getDocSeriesInStudy($orthancStudyId, $acc !== '' ? $acc : null);
            if ($found) {
                $seriesId = (string) ($found['series_id'] ?? '');
                $instanceId = trim((string) ($found['instance_id'] ?? ''));
            }
        }

        if ($seriesId === '' && $instanceId === '') {
            return [
                'informe_view_strategy' => 'none',
                'informe_pacs' => $emptyPacs,
            ];
        }

        $pacsMeta = self::resolvePacsContent($pacs, $orthancStudyId, $seriesId, $instanceId);
        if (!$pacsMeta['available']) {
            return [
                'informe_view_strategy' => 'none',
                'informe_pacs' => $pacsMeta,
            ];
        }

        $strategy = 'pacs_images';
        if (($pacsMeta['content_type'] ?? '') === 'encapsulated_pdf') {
            $strategy = 'pacs_pdf';
        }

        return [
            'informe_view_strategy' => $strategy,
            'informe_pacs' => $pacsMeta,
        ];
    }

    /**
     * @param array<string, mixed> $informe
     * @return array<string, mixed>
     */
    private static function buildPacsMeta(array $informe, ?OrthancPacsSender $pacs, ?string $orthancStudyId): array {
        $seriesId = trim((string) ($informe['pacs_series_id'] ?? ''));
        $instanceId = trim((string) ($informe['pacs_instance_id'] ?? ''));
        if ($seriesId === '' && $instanceId === '' && $pacs !== null && $orthancStudyId) {
            $acc = trim((string) ($informe['accession_number'] ?? ''));
            $found = $pacs->getDocSeriesInStudy($orthancStudyId, $acc !== '' ? $acc : null);
            if ($found) {
                $seriesId = (string) ($found['series_id'] ?? '');
                $instanceId = trim((string) ($found['instance_id'] ?? ''));
            }
        }
        if ($seriesId === '' && $instanceId === '') {
            return [
                'available' => false,
                'series_id' => null,
                'instance_id' => null,
                'content_type' => 'none',
                'page_count' => 0,
            ];
        }
        return self::resolvePacsContent($pacs, $orthancStudyId, $seriesId, $instanceId);
    }

    /**
     * @return array{available:bool, series_id:?string, instance_id:?string, content_type:string, page_count:int}
     */
    private static function resolvePacsContent(
        ?OrthancPacsSender $pacs,
        ?string $orthancStudyId,
        string $seriesId,
        string $instanceId
    ): array {
        $base = [
            'available' => false,
            'series_id' => $seriesId !== '' ? $seriesId : null,
            'instance_id' => $instanceId !== '' ? $instanceId : null,
            'content_type' => 'unknown',
            'page_count' => 0,
        ];

        if ($pacs === null) {
            return $base;
        }

        if ($seriesId !== '') {
            foreach ($pacs->listDocSeriesInStudy((string) $orthancStudyId) as $docSeries) {
                if (($docSeries['series_id'] ?? '') !== $seriesId) {
                    continue;
                }
                $sop = trim((string) ($docSeries['sop_class_uid'] ?? ''));
                $pageCount = (int) ($docSeries['instance_count'] ?? 0);
                $instId = $docSeries['instance_id'] ?? $instanceId;
                $contentType = self::sopToContentType($sop, $pageCount, $pacs, (string) $instId);
                return [
                    'available' => true,
                    'series_id' => $seriesId,
                    'instance_id' => $instId ? (string) $instId : null,
                    'content_type' => $contentType,
                    'page_count' => max(1, $pageCount),
                ];
            }
        }

        if ($instanceId !== '') {
            $tags = self::fetchInstanceTags($pacs, $instanceId);
            $sop = trim((string) ($tags['SOPClassUID'] ?? ''));
            $contentType = self::sopToContentType($sop, 1, $pacs, $instanceId);
            return [
                'available' => true,
                'series_id' => $seriesId !== '' ? $seriesId : null,
                'instance_id' => $instanceId,
                'content_type' => $contentType,
                'page_count' => 1,
            ];
        }

        return $base;
    }

    private static function sopToContentType(string $sop, int $pageCount, OrthancPacsSender $pacs, string $instanceId): string {
        if ($sop === OrthancPacsSender::SOPCLASS_PDF) {
            return 'encapsulated_pdf';
        }
        if ($sop === OrthancPacsSender::SOPCLASS_SECONDARY_CAPTURE) {
            return 'image_pages';
        }
        if ($pageCount > 1) {
            return 'image_pages';
        }
        if ($pageCount === 1 && $sop !== '') {
            return 'encapsulated_pdf';
        }
        return $pageCount > 1 ? 'image_pages' : 'encapsulated_pdf';
    }

    /** @return array<string, mixed> */
    private static function fetchInstanceTags(OrthancPacsSender $pacs, string $instanceId): array {
        $res = $pacs->makeRequestWithRetry(
            '/instances/' . rawurlencode($instanceId) . '/simplified-tags',
            'GET',
            null,
            5
        );
        return ($res['success'] && is_array($res['data'] ?? null)) ? $res['data'] : [];
    }

    /**
     * Enriquece estudios e informes huérfanos del listado v2.
     *
     * @param array<int, array<string, mixed>> $studies
     */
    public static function enrichStudies(array &$studies, OrthancPacsSender $pacs): void {
        foreach ($studies as &$study) {
            self::enrichStudyEntry($study, $pacs);
        }
        unset($study);
    }

    /** @param array<string, mixed> $study */
    private static function enrichStudyEntry(array &$study, OrthancPacsSender $pacs): void {
        $orthancId = trim((string) ($study['orthanc_id'] ?? $study['study_id'] ?? ''));

        if (!empty($study['has_informe'])) {
            $principal = [
                'pdf_path' => $study['informe_pdf_path'] ?? '',
                'pacs_series_id' => $study['pacs_series_id'] ?? '',
                'pacs_instance_id' => $study['pacs_instance_id'] ?? '',
                'accession_number' => $study['accession_number'] ?? '',
                'estado' => 'finalizado',
            ];
            $built = self::build($principal, $pacs, $orthancId !== '' ? $orthancId : null);
            $study['informe_view_strategy'] = $built['informe_view_strategy'];
            $study['informe_pacs'] = $built['informe_pacs'];

            if (!empty($study['informes']) && is_array($study['informes'])) {
                foreach ($study['informes'] as &$inf) {
                    $row = [
                        'pdf_path' => $inf['pdf_path'] ?? '',
                        'pacs_series_id' => $inf['pacs_series_id'] ?? '',
                        'pacs_instance_id' => $inf['pacs_instance_id'] ?? '',
                        'accession_number' => $inf['accession_number'] ?? '',
                        'estado' => 'finalizado',
                    ];
                    $infBuilt = self::build($row, $pacs, $orthancId !== '' ? $orthancId : null);
                    $inf['informe_view_strategy'] = $infBuilt['informe_view_strategy'];
                    $inf['informe_pacs'] = $infBuilt['informe_pacs'];
                }
                unset($inf);
            }
            return;
        }

        if (!empty($study['is_informe'])) {
            $built = self::build([
                'pdf_path' => $study['pdf_path'] ?? '',
                'pacs_series_id' => $study['pacs_series_id'] ?? '',
                'pacs_instance_id' => $study['pacs_instance_id'] ?? '',
                'accession_number' => $study['accession_number'] ?? '',
                'estado' => 'finalizado',
            ], $pacs, $orthancId !== '' ? $orthancId : null);
            $study['informe_view_strategy'] = $built['informe_view_strategy'];
            $study['informe_pacs'] = $built['informe_pacs'];
            $study['has_informe'] = ($built['informe_view_strategy'] ?? 'none') !== 'none';
            if ($study['has_informe'] && empty($study['informe_pdf_path']) && ($study['pdf_path'] ?? '') !== '') {
                $study['informe_pdf_path'] = $study['pdf_path'];
            }
        }
    }
}
