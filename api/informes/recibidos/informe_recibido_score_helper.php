<?php
/**
 * Motor único de puntuación informe recibido ↔ estudio candidato.
 * Alinea modal (candidatos-estudio) y reproceso automático.
 */

if (!function_exists('ir_score_normalize_patient_id')) {

    function ir_score_normalize_patient_id(?string $value): string
    {
        $value = strtoupper(trim((string)($value ?? '')));
        if ($value === '') {
            return '';
        }

        return preg_replace('/[^A-Z0-9]/', '', $value);
    }

    function ir_score_normalize_text(?string $value): string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return '';
        }
        $trans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($trans !== false) {
            $value = $trans;
        }
        $value = strtoupper($value);
        $value = preg_replace('/[^A-Z0-9\s]/', ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);

        return trim($value);
    }

    function ir_score_token_similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        $aTokens = array_values(array_unique(array_filter(explode(' ', $a))));
        $bTokens = array_values(array_unique(array_filter(explode(' ', $b))));
        if (!$aTokens || !$bTokens) {
            return 0.0;
        }

        // Jaccard base: tokens exactamente iguales
        $intersection = array_intersect($aTokens, $bTokens);
        $union = array_unique(array_merge($aTokens, $bTokens));
        $baseScore = count($union) > 0 ? count($intersection) / count($union) : 0.0;

        // Bonus por iniciales: un token de 1-2 chars (incluyendo "V." con punto) puede ser
        // la inicial de un token completo en el otro (ej. "V." → "VALENTINA").
        $cleanToken = static function (string $t): string {
            return rtrim($t, '.');  // eliminar punto final: "V." → "V"
        };
        $aTokensClean = array_map($cleanToken, $aTokens);
        $bTokensClean = array_map($cleanToken, $bTokens);

        $initialMatches = 0;
        $initialTotal   = 0;
        foreach ($aTokensClean as $ta) {
            if (strlen($ta) === 1) {
                $initialTotal++;
                foreach ($bTokensClean as $tb) {
                    if (strlen($tb) > 1 && $tb[0] === $ta) {
                        $initialMatches++;
                        break;
                    }
                }
            }
        }
        foreach ($bTokensClean as $tb) {
            if (strlen($tb) === 1) {
                $initialTotal++;
                foreach ($aTokensClean as $ta) {
                    if (strlen($ta) > 1 && $ta[0] === $tb) {
                        $initialMatches++;
                        break;
                    }
                }
            }
        }

        if ($initialTotal > 0 && $initialMatches > 0) {
            // Bonificar proporcionalmente: cada inicial resuelta suma como media coincidencia
            $initialBonus = ($initialMatches / $initialTotal) * 0.15;
            return min(1.0, $baseScore + $initialBonus);
        }

        return $baseScore;
    }

    /**
     * Elimina sufijos de edad que los RIS/PACS agregan al nombre del paciente.
     * Ejemplos: "PAZ DOMINGA RAMONA 67A." → "PAZ DOMINGA RAMONA"
     *           "PINTOS SUAREZ VALENTINA 18A." → "PINTOS SUAREZ VALENTINA"
     *           "TORRES VELARDE C. 23A" → "TORRES VELARDE C."
     */
    function ir_score_strip_age_suffix(string $name): string
    {
        // Elimina patrones como " 67A.", " 18A", " 5A.", " 102A" al final del nombre
        return trim(preg_replace('/\s+\d{1,3}A\.?\s*$/i', '', $name));
    }

    function ir_score_name_similarity(?string $a, ?string $b): float
    {
        $na = ir_score_strip_age_suffix(ir_score_normalize_text($a));
        $nb = ir_score_strip_age_suffix(ir_score_normalize_text($b));
        if ($na === '' || $nb === '') {
            return 0.0;
        }

        similar_text($na, $nb, $percent);
        $simText = $percent / 100.0;
        $simTokens = ir_score_token_similarity($na, $nb);

        return max($simText, $simTokens);
    }

    function ir_score_day_diff_abs(?string $d1, ?string $d2): ?int
    {
        if (!$d1 || !$d2) {
            return null;
        }
        $t1 = strtotime($d1);
        $t2 = strtotime($d2);
        if ($t1 === false || $t2 === false) {
            return null;
        }

        return (int)floor(abs($t1 - $t2) / 86400);
    }

    function ir_score_day_diff_signed(?string $baseDate, ?string $candidateDate): ?int
    {
        if (!$baseDate || !$candidateDate) {
            return null;
        }
        $t1 = strtotime($baseDate);
        $t2 = strtotime($candidateDate);
        if ($t1 === false || $t2 === false) {
            return null;
        }

        return (int)floor(($t2 - $t1) / 86400);
    }

    function ir_score_normalize_dicom_date_to_iso(?string $value): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return null;
        }
        $digits = preg_replace('/\D/', '', $value);
        if (strlen($digits) < 8) {
            return null;
        }
        $digits = substr($digits, 0, 8);

        return substr($digits, 0, 4) . '-' . substr($digits, 4, 2) . '-' . substr($digits, 6, 2);
    }

    function ir_score_modality_matches(string $expected, string $candidate): bool
    {
        $expected = strtoupper(trim($expected));
        $candidate = strtoupper(trim($candidate));
        if ($expected === '' || $candidate === '') {
            return true;
        }

        // CR y DX son equivalentes: TXT externos usan CR para radiografías,
        // pero los estudios en PACS pueden estar almacenados como DX (y viceversa).
        $normalize = static function (string $m): string {
            return $m === 'DX' ? 'CR' : $m;
        };
        $expected = $normalize($expected);

        $tokens = preg_split('/[\s,\\\\\/;|]+/', $candidate);
        $tokens = array_values(array_filter(array_map('trim', $tokens)));
        if (!$tokens) {
            return $normalize($candidate) === $expected;
        }

        foreach ($tokens as $t) {
            if ($normalize($t) === $expected) {
                return true;
            }
        }

        return false;
    }

    function ir_score_build_reasons_text(array $reasons): string
    {
        if (!$reasons) {
            return 'sin_coincidencia_fuerte';
        }

        return implode(', ', $reasons);
    }

    /**
     * @param array $input accession_number, patient_id, patient_name, procedure_date (Y-m-d), modality;
     *                       opcional: study_date_dicom (Y-m-d) desde informes_recibidos
     * @param array $study fila candidato: accession_number, patient_id|patient_id_pacs, patient_name|patient_name_pacs,
     *                     modality, study_date; opcional _accno_pacs_enriched / accno_pacs_enriched
     * @param array $opts opcional: search_q (string) → +10 si coincide en campos del estudio
     * @return array{score:int,reasons:array,name_similarity:float,date_diff_days:?int,date_signed_diff_days:?int}
     */
    function ir_score_match_informe_estudio(array $input, array $study, array $opts = []): array
    {
        $accessionNumber = trim((string)($input['accession_number'] ?? ''));
        $patientId = trim((string)($input['patient_id'] ?? ''));
        $patientName = trim((string)($input['patient_name'] ?? ''));
        $procedureDate = trim((string)($input['procedure_date'] ?? ''));
        $modality = strtoupper(trim((string)($input['modality'] ?? '')));
        $studyDateDicom = trim((string)($input['study_date_dicom'] ?? ''));

        $candidateAccession = trim((string)($study['accession_number'] ?? ''));
        $candidatePatientId = trim((string)($study['patient_id_pacs'] ?? $study['patient_id'] ?? ''));
        $candidateName = trim((string)($study['patient_name_pacs'] ?? $study['patient_name'] ?? ''));
        $candidateModality = strtoupper(trim((string)($study['modality'] ?? '')));
        $candidateDate = trim((string)($study['study_date'] ?? ''));

        $normalizedPatientId = ir_score_normalize_patient_id($patientId);
        $candidatePatientIdNorm = ir_score_normalize_patient_id($candidatePatientId);

        $score = 0;
        $reasons = [];

        if ($accessionNumber !== '' && $candidateAccession !== '') {
            if (strcasecmp($accessionNumber, $candidateAccession) === 0) {
                if (!empty($study['_accno_pacs_enriched']) || !empty($study['accno_pacs_enriched'])) {
                    $score += 40;
                    $reasons[] = 'accession_pacs_match';
                } else {
                    $score += 60;
                    $reasons[] = 'accession_exacto';
                }
            }
        }

        $hasPidExacto = false;
        if ($normalizedPatientId !== '' && $candidatePatientIdNorm !== '') {
            if ($normalizedPatientId === $candidatePatientIdNorm) {
                $score += 35;
                $reasons[] = 'patient_id_exacto';
                $hasPidExacto = true;
            } else {
                // Dígito extra/faltante al final: uno es prefijo del otro (ej. 12821053 vs 128210523)
                $lenA = strlen($normalizedPatientId);
                $lenB = strlen($candidatePatientIdNorm);
                $isPrefijo = $lenA >= 5 && $lenB >= 5 && abs($lenA - $lenB) <= 2
                    && (str_starts_with($normalizedPatientId, $candidatePatientIdNorm)
                        || str_starts_with($candidatePatientIdNorm, $normalizedPatientId)
                        || str_ends_with($normalizedPatientId, $candidatePatientIdNorm)
                        || str_ends_with($candidatePatientIdNorm, $normalizedPatientId));
                // Distancia Levenshtein = 1: un dígito cambiado, insertado o eliminado
                $isLevenshtein1 = !$isPrefijo && levenshtein($normalizedPatientId, $candidatePatientIdNorm) === 1;
                if ($isPrefijo) {
                    $score += 20;
                    $reasons[] = 'patient_id_prefijo';
                } elseif ($isLevenshtein1) {
                    $score += 15;
                    $reasons[] = 'patient_id_similar';
                }
            }
        }

        $sim = ir_score_name_similarity($patientName, $candidateName);
        if ($sim >= 0.92) {
            $score += 25;
            $reasons[] = 'nombre_muy_similar';
        } elseif ($sim >= 0.80) {
            $score += 15;
            $reasons[] = 'nombre_similar';
        } elseif ($sim >= 0.65) {
            $score += 8;
            $reasons[] = 'nombre_parcial';
        }

        $dd = ir_score_day_diff_abs($procedureDate, $candidateDate);
        $signedDd = ir_score_day_diff_signed($procedureDate, $candidateDate);
        $ddDicom = ($studyDateDicom !== '') ? ir_score_day_diff_abs($studyDateDicom, $candidateDate) : null;
        $signedDdDicom = ($studyDateDicom !== '') ? ir_score_day_diff_signed($studyDateDicom, $candidateDate) : null;
        $useDicomDate = ($ddDicom !== null && ($dd === null || $ddDicom < $dd));
        $effectiveDd = $useDicomDate ? $ddDicom : $dd;
        $effectiveSignedDd = $useDicomDate ? $signedDdDicom : $signedDd;

        if ($effectiveDd !== null) {
            if ($effectiveDd === 0) {
                $score += 20;
                $reasons[] = $useDicomDate ? 'fecha_exacta_dicom' : 'fecha_exacta';
            } elseif ($effectiveDd === 1) {
                $score += 12;
                $reasons[] = $useDicomDate ? 'fecha_1_dia_dicom' : 'fecha_1_dia';
            } elseif ($effectiveDd <= 3) {
                $score += 8;
                $reasons[] = $useDicomDate ? 'fecha_cercana_dicom' : 'fecha_cercana';
            } elseif ($effectiveDd <= 7) {
                $score += 5;
                $reasons[] = $useDicomDate ? 'fecha_compatible_dicom' : 'fecha_compatible';
            } elseif ($effectiveDd <= 14) {
                $score += 2;
                $reasons[] = $useDicomDate ? 'fecha_proxima_dicom' : 'fecha_proxima';
            }
        }

        if ($effectiveSignedDd !== null && $effectiveSignedDd > 1) {
            if ($effectiveSignedDd <= 7) {
                $reasons[] = 'fecha_posterior_al_informe';
            } elseif ($effectiveSignedDd <= 21) {
                $score -= 10;
                $reasons[] = 'fecha_posterior_al_informe';
            } else {
                $score -= 25;
                $reasons[] = 'fecha_posterior_al_informe';
            }
        }

        if ($modality !== '' && $candidateModality !== '' && ir_score_modality_matches($modality, $candidateModality)) {
            $score += 5;
            $reasons[] = 'modalidad_igual';
        }

        // Señal patient_id_discrepante: el PID no coincide (ni exacto ni fuzzy), pero
        // nombre+fecha+modalidad son fuertes. Indica probable error de carga manual en el equipo.
        // Esta señal NO bloquea el auto-link pero el umbral es mayor; sirve para el modal UI.
        $hasPidFuzzy = in_array('patient_id_prefijo', $reasons, true) || in_array('patient_id_similar', $reasons, true);
        $hasPidAny   = $hasPidExacto || $hasPidFuzzy;
        if (!$hasPidAny && $normalizedPatientId !== '' && $candidatePatientIdNorm !== '') {
            $nameSim     = $sim; // ya calculado arriba
            $fechaOk     = $effectiveDd !== null && $effectiveDd <= 1;
            $modalidadOk = $modality !== '' && $candidateModality !== '' && ir_score_modality_matches($modality, $candidateModality);
            if ($nameSim >= 0.80 && $fechaOk && $modalidadOk) {
                $score += 10;
                $reasons[] = 'patient_id_discrepante';
            }
        }

        // Bono de confianza: patient_id (exacto o fuzzy) + fecha cercana es una combinación determinística
        // aunque no haya ACCNO completo o nombre abreviado disponible.
        // Escalonado: más bono cuanto más cercana la fecha.
        $hasPidExacto = in_array('patient_id_exacto', $reasons, true);
        $hasPidFuerteOFuzzy = $hasPidExacto || $hasPidFuzzy;
        if ($hasPidFuerteOFuzzy && $effectiveDd !== null) {
            // Bono reducido si el PID es fuzzy (no exacto) — menos certeza
            $bonusMult = $hasPidExacto ? 1.0 : 0.6;
            if ($effectiveDd === 0 || in_array('fecha_exacta', $reasons, true) || in_array('fecha_exacta_dicom', $reasons, true)) {
                $score += (int)round(8 * $bonusMult);
                $reasons[] = 'pid_fecha_confianza';
            } elseif ($effectiveDd <= 1 || in_array('fecha_1_dia', $reasons, true) || in_array('fecha_1_dia_dicom', $reasons, true)) {
                $score += (int)round(7 * $bonusMult);
                $reasons[] = 'pid_fecha_confianza';
            } elseif ($effectiveDd <= 7 && array_intersect(['fecha_cercana', 'fecha_cercana_dicom', 'fecha_compatible', 'fecha_compatible_dicom'], $reasons) !== []) {
                $score += (int)round(5 * $bonusMult);
                $reasons[] = 'pid_fecha_confianza';
            } elseif ($effectiveDd <= 14 && array_intersect(['fecha_proxima', 'fecha_proxima_dicom'], $reasons) !== []) {
                $score += (int)round(3 * $bonusMult);
                $reasons[] = 'pid_fecha_confianza';
            }
        }

        $searchQ = trim((string)($opts['search_q'] ?? ''));
        if ($searchQ !== '') {
            $q = mb_strtolower($searchQ, 'UTF-8');
            $pn = mb_strtolower((string)($study['patient_name_pacs'] ?? $study['patient_name'] ?? ''), 'UTF-8');
            $hayMatchBusqueda = strpos($pn, $q) !== false
                || strpos(mb_strtolower((string)($study['study_instance_uid'] ?? ''), 'UTF-8'), $q) !== false
                || strpos(mb_strtolower((string)($study['study_description'] ?? ''), 'UTF-8'), $q) !== false
                || strpos(mb_strtolower((string)($study['accession_number'] ?? ''), 'UTF-8'), $q) !== false
                || strpos(mb_strtolower((string)($study['patient_id_pacs'] ?? $study['patient_id'] ?? ''), 'UTF-8'), $q) !== false;
            if ($hayMatchBusqueda) {
                $score += 10;
                $reasons[] = 'coincidencia_busqueda';
            }
        }

        return [
            'score' => $score,
            'reasons' => $reasons,
            'name_similarity' => round($sim, 3),
            'date_diff_days' => $dd,
            'date_signed_diff_days' => $signedDd,
        ];
    }
}
