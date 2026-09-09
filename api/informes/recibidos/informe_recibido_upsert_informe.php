<?php
/**
 * Upsert único de filas en `informes` desde informes recibidos (API, vinculación manual, reproceso).
 * Unifica criterios de búsqueda por estudio (Orthanc REST, Study UID, PK estudios, study_id)
 * y el fallback ACCNO → informe sin ACCNO del mismo estudio.
 */

if (!function_exists('ir_upsert_informe_desde_recibido')) {

    require_once __DIR__ . '/informe_recibido_version_helper.php';
    require_once __DIR__ . '/../../OrthancClient.php';
    require_once __DIR__ . '/../informe_medico_responsable_helper.php';

    /**
     * Modalidades compuestas ("CT, MR") → primer token, VARCHAR corto en BD.
     */
    function ir_normalize_modality_for_db(?string $value): string
    {
        $raw = strtoupper(trim((string)($value ?? '')));
        if ($raw === '') {
            return 'DOC';
        }
        $parts = preg_split('/[\s,\\\\\/;|]+/', $raw);
        $first = trim((string)($parts[0] ?? ''));
        if ($first === '') {
            return 'DOC';
        }

        return substr($first, 0, 10);
    }

    /**
     * @param array $payload estudio_id (int), accession_number, patient_id, patient_name, modality,
     *                        procedure_description, pdf_path, opcional: study_instance_uid, orthanc_study_id,
     *                        orthanc_internal_id, historial_usuario_id, historial_motivo
     */
    function ir_upsert_informe_desde_recibido(PDO $db, array $payload, int $systemUserId): ?int
    {
        if ($systemUserId <= 0 || empty($payload['estudio_id'])) {
            return null;
        }

        $studyStmt = $db->prepare('SELECT id, orthanc_study_id, study_instance_uid, study_description, modality FROM estudios WHERE id = ? LIMIT 1');
        $studyStmt->execute([(int)$payload['estudio_id']]);
        $study = $studyStmt->fetch(PDO::FETCH_ASSOC);
        if (!$study) {
            return null;
        }

        $orthancRef = trim((string)($study['orthanc_study_id'] ?? ''));
        $uidRef = trim((string)($study['study_instance_uid'] ?? ''));
        $candOrthanc = trim((string)($payload['orthanc_study_id'] ?? ''));
        $candUid = trim((string)($payload['study_instance_uid'] ?? ''));
        $candInternal = trim((string)($payload['orthanc_internal_id'] ?? ''));

        if ($orthancRef === '' && $candOrthanc !== '') {
            $orthancRef = $candOrthanc;
        }
        if ($uidRef === '' && $candUid !== '') {
            $uidRef = $candUid;
        }
        if ($uidRef === '' && $orthancRef !== '' && preg_match('/^\d+(\.\d+){3,}$/', $orthancRef)) {
            $uidRef = $orthancRef;
        }
        if ($uidRef === '' && !empty($payload['study_instance_uid'])) {
            $uidRef = trim((string)$payload['study_instance_uid']);
        }

        $orthancRestInternal = null;
        if ($candInternal !== '') {
            $orthancRestInternal = $candInternal;
        }
        if ($orthancRef !== '' && preg_match('/^1\\.2\\.\\d/', $orthancRef)) {
            try {
                $oc = new OrthancClient();
                $resolved = $oc->findOrthancStudyIdByStudyInstanceUid($orthancRef);
                if ($resolved !== null && $resolved !== '') {
                    $orthancRestInternal = $resolved;
                }
            } catch (Exception $e) {
                // sin PACS o error de red
            }
        }
        if ($orthancRestInternal === null && $uidRef !== '') {
            try {
                $oc2 = new OrthancClient();
                $resolved = $oc2->findOrthancStudyIdByStudyInstanceUid($uidRef);
                if ($resolved !== null && $resolved !== '') {
                    $orthancRestInternal = $resolved;
                }
            } catch (Exception $e) {
                // sin PACS
            }
        }

        $internalBind = ($orthancRestInternal !== null && $orthancRestInternal !== '') ? $orthancRestInternal : '';
        $studyPkVal = (string)($study['id'] ?? '');

        $whereParts = [
            'estudio_id = :sw_a',
            'estudio_id = :sw_b',
            'estudio_id = :sw_e',
            'study_instance_uid = :sw_c',
            'study_id = :sw_d',
        ];
        $params = [
            ':sw_a' => $orthancRef,
            ':sw_b' => $uidRef,
            ':sw_c' => $uidRef,
            ':sw_d' => $orthancRef,
            ':sw_e' => $studyPkVal,
        ];
        if ($internalBind !== '') {
            $whereParts[] = '(estudio_id = :internal_e OR study_id = :internal_s)';
            $params[':internal_e'] = $internalBind;
            $params[':internal_s'] = $internalBind;
        }
        $studyWhereClause = '(' . implode(' OR ', $whereParts) . ')';

        $irAccno = trim((string)($payload['accession_number'] ?? ''));
        $existingId = false;
        if ($irAccno !== '') {
            $accnoStmt = $db->prepare(
                "SELECT id FROM informes WHERE $studyWhereClause AND accession_number = :accno ORDER BY id DESC LIMIT 1"
            );
            $accnoStmt->execute(array_merge($params, [':accno' => $irAccno]));
            $existingId = $accnoStmt->fetchColumn();
            if ($existingId === false || $existingId === '') {
                $existingId = false;
                $noAccnoStmt = $db->prepare(
                    "SELECT id FROM informes WHERE $studyWhereClause AND (accession_number IS NULL OR accession_number = '') ORDER BY id DESC LIMIT 1"
                );
                $noAccnoStmt->execute($params);
                $noAccnoId = $noAccnoStmt->fetchColumn();
                if ($noAccnoId !== false && $noAccnoId !== '') {
                    $existingId = $noAccnoId;
                }
            }
        } else {
            $fallbackStmt = $db->prepare("SELECT id FROM informes WHERE $studyWhereClause ORDER BY id DESC LIMIT 1");
            $fallbackStmt->execute($params);
            $existingId = $fallbackStmt->fetchColumn();
        }

        $numericStudyId = (int)($study['id'] ?? 0);
        $estudioIdForReport = '';
        if ($orthancRestInternal !== null && $orthancRestInternal !== '') {
            $estudioIdForReport = $orthancRestInternal;
        } elseif ($orthancRef !== '') {
            $estudioIdForReport = $orthancRef;
        } elseif ($uidRef !== '') {
            $estudioIdForReport = $uidRef;
        } elseif ($numericStudyId > 0) {
            $estudioIdForReport = (string)$numericStudyId;
        }
        if ($estudioIdForReport === '') {
            return null;
        }

        $studyInstanceUid = trim((string)($study['study_instance_uid'] ?? ''));
        if ($studyInstanceUid === '' && $candUid !== '') {
            $studyInstanceUid = $candUid;
        }
        if ($studyInstanceUid === '' && $orthancRef !== '' && preg_match('/^1\\.2\\.\\d/', $orthancRef)) {
            $studyInstanceUid = $orthancRef;
        }
        if ($studyInstanceUid === '' && preg_match('/^\d+(\.\d+){3,}$/', (string)($study['orthanc_study_id'] ?? ''))) {
            $studyInstanceUid = (string)$study['orthanc_study_id'];
        }
        if ($studyInstanceUid === '' && !empty($payload['study_instance_uid'])
            && preg_match('/^\d+(\.\d+){3,}$/', (string)$payload['study_instance_uid'])) {
            $studyInstanceUid = (string)$payload['study_instance_uid'];
        }

        $titulo = 'Informe externo API - ' . ($payload['accession_number'] ?? 'SIN-ACCNO');
        $studyDescription = $payload['procedure_description'] ?? $study['study_description'] ?? 'Informe externo';
        $modality = ir_normalize_modality_for_db((string)($payload['modality'] ?? ($study['modality'] ?? '')));

        $motivoHist = trim((string)($payload['historial_motivo'] ?? ''));
        if ($motivoHist === '') {
            $motivoHist = 'Actualización PDF recibido por API (mismo estudio / ACCNO)';
        }

        $actorId = (isset($payload['historial_usuario_id']) && (int)$payload['historial_usuario_id'] > 0)
            ? (int)$payload['historial_usuario_id']
            : $systemUserId;

        $pdfPathVal = trim((string)($payload['pdf_path'] ?? ''));

        $medico = ir_resolve_medico_responsable_estudio($db, [
            'estudio_id' => (string)$estudioIdForReport,
            'study_id' => $internalBind !== '' ? $internalBind : (string)($study['orthanc_study_id'] ?? ''),
            'study_instance_uid' => $studyInstanceUid,
            'orthanc_study_id' => (string)($study['orthanc_study_id'] ?? ''),
        ]);
        $ownerUserId = !empty($medico['usuario_id']) ? (int)$medico['usuario_id'] : $systemUserId;
        $ownerRol = $medico['medico_informante_rol'] ?? null;
        if ($ownerRol === null || $ownerRol === '') {
            try {
                $rStmt = $db->prepare('SELECT rol FROM usuarios WHERE id = ?');
                $rStmt->execute([$ownerUserId]);
                $ownerRol = $rStmt->fetchColumn() ?: null;
            } catch (Throwable $e) {
                $ownerRol = null;
            }
        }

        if ($existingId) {
            $eid = (int)$existingId;
            $applied = irvh_aplicarVersionPorPdfRecibido(
                $db,
                $eid,
                (string)($payload['pdf_path'] ?? ''),
                $titulo,
                $actorId,
                $motivoHist
            );
            // Externos API: finalizado → auto-PACS. Firma médica aplica a informes de plataforma.
            $updateStmt = $db->prepare("
                UPDATE informes
                SET pdf_path = ?,
                    contenido_html = ?,
                    contenido_texto = ?,
                    version = ?,
                    patient_id = COALESCE(?, patient_id),
                    patient_name = COALESCE(?, patient_name),
                    accession_number = COALESCE(?, accession_number),
                    modality = COALESCE(?, modality),
                    study_description = COALESCE(?, study_description),
                    study_instance_uid = COALESCE(study_instance_uid, ?),
                    usuario_id = CASE
                        WHEN firmado_en IS NULL THEN ?
                        ELSE usuario_id
                    END,
                    medico_informante_rol = COALESCE(?, medico_informante_rol),
                    origen = 'externo',
                    estado = 'finalizado',
                    fecha_finalizacion = COALESCE(fecha_finalizacion, NOW()),
                    fecha_modificacion = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([
                $payload['pdf_path'],
                $applied['contenido_html'],
                $applied['contenido_texto'],
                $applied['version'],
                $payload['patient_id'] ?? null,
                $payload['patient_name'] ?? null,
                $payload['accession_number'] ?? null,
                $modality,
                $studyDescription,
                $studyInstanceUid !== '' ? $studyInstanceUid : null,
                $ownerUserId,
                $ownerRol,
                $eid,
            ]);

            return $eid;
        }

        $contenidoAdjunto = ($pdfPathVal !== '')
            ? irvh_buildAdjuntoPdfContenidoHtml($pdfPathVal, $titulo)
            : '';
        $contenidoTextoAdj = ($pdfPathVal !== '') ? ('Informe PDF recibido por API - ' . $titulo) : '';

        $studyIdForRow = ($internalBind !== '') ? $internalBind : trim((string)($study['orthanc_study_id'] ?? ''));
        $studyIdForRow = $studyIdForRow !== '' ? $studyIdForRow : null;

        $hasFechaFin = false;
        try {
            $c = $db->query("SHOW COLUMNS FROM informes LIKE 'fecha_finalizacion'");
            $hasFechaFin = $c && $c->rowCount() > 0;
        } catch (Throwable $e) {
            $hasFechaFin = false;
        }

        if ($hasFechaFin) {
            $insertStmt = $db->prepare("
                INSERT INTO informes (
                    estudio_id, study_instance_uid, study_id, usuario_id,
                    patient_id, patient_name, modality, study_description,
                    titulo, contenido_html, contenido_texto, estado, origen, version,
                    accession_number, pdf_path, medico_informante_rol, fecha_finalizacion
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'finalizado', 'externo', 1, ?, ?, ?, NOW())
            ");
        } else {
            $insertStmt = $db->prepare("
                INSERT INTO informes (
                    estudio_id, study_instance_uid, study_id, usuario_id,
                    patient_id, patient_name, modality, study_description,
                    titulo, contenido_html, contenido_texto, estado, origen, version,
                    accession_number, pdf_path, medico_informante_rol
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'finalizado', 'externo', 1, ?, ?, ?)
            ");
        }
        $insertParams = [
            $estudioIdForReport,
            $studyInstanceUid !== '' ? $studyInstanceUid : null,
            $studyIdForRow,
            $ownerUserId,
            $payload['patient_id'] ?? null,
            $payload['patient_name'] ?? null,
            $modality,
            $studyDescription,
            $titulo,
            $contenidoAdjunto !== '' ? $contenidoAdjunto : '',
            $contenidoTextoAdj !== '' ? $contenidoTextoAdj : '',
            $payload['accession_number'] ?? null,
            $payload['pdf_path'],
            $ownerRol,
        ];
        $insertStmt->execute($insertParams);
        return (int)$db->lastInsertId();
    }
}
