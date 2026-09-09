<?php
/**
 * Normaliza un valor de TXT DICOM a UTF-8 válido.
 * Algunos RIS generan ANSI/Latin1 y MySQL utf8mb4 rechaza esos bytes.
 */
function ir_normalize_text_utf8(string $value): string
{
    $v = trim($value);
    if ($v === '') {
        return '';
    }

    if (function_exists('mb_check_encoding') && mb_check_encoding($v, 'UTF-8')) {
        return $v;
    }

    if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($v, 'UTF-8', 'Windows-1252,ISO-8859-1,UTF-8');
        if (is_string($converted) && $converted !== '') {
            return $converted;
        }
    }

    if (function_exists('iconv')) {
        $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $v);
        if (is_string($converted) && $converted !== '') {
            return $converted;
        }
        $converted = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $v);
        if (is_string($converted) && $converted !== '') {
            return $converted;
        }
    }

    return $v;
}
/**
 * Parseo del TXT DicomData usado por recibir-pdf.php e ingesta desde carpeta.
 *
 * @throws Exception si falta (0008.0050)
 * @return array Mismas claves que usa recibir-pdf / ingest
 */
function ir_parse_dicom_txt_content(string $txtContent): array
{
    $dicomData = [];
    $lines = explode("\n", $txtContent);
    $inDicomData = false;

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '') {
            continue;
        }

        if (preg_match('/^\[DicomData\]/i', $line)) {
            $inDicomData = true;
            continue;
        }

        if (!$inDicomData) {
            continue;
        }

        if (preg_match('/^\(([0-9A-F]{4}\.[0-9A-F]{4})\)=(.+)$/i', $line, $matches)) {
            $tag = $matches[1];
            $value = ir_normalize_text_utf8($matches[2]);

            switch ($tag) {
                case '0008.0050':
                    $dicomData['accession_number'] = $value;
                    break;
                case '0008.0020':
                    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $parts)) {
                        $dicomData['study_date'] = $parts[1] . '-' . $parts[2] . '-' . $parts[3];
                    }
                    break;
                case '0008.0060':
                    $dicomData['modality'] = $value;
                    break;
                case '0008.0090':
                    $dicomData['referring_physician'] = $value;
                    break;
                case '0010.0010':
                    $dicomData['patient_name'] = str_replace('^', ' ', $value);
                    break;
                case '0010.0020':
                    $dicomData['patient_id'] = $value;
                    break;
                case '0010.0030':
                    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $parts)) {
                        $dicomData['patient_birth_date'] = $parts[1] . '-' . $parts[2] . '-' . $parts[3];
                    }
                    break;
                case '0010.0040':
                    $sex = strtoupper(substr($value, 0, 1));
                    if (in_array($sex, ['M', 'F', 'O'], true)) {
                        $dicomData['patient_sex'] = $sex;
                    }
                    break;
                case '0040.0001':
                    $dicomData['equipment_name'] = $value;
                    break;
                case '0040.0002':
                    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $parts)) {
                        $dicomData['scheduled_date'] = $parts[1] . '-' . $parts[2] . '-' . $parts[3];
                    }
                    break;
                case '0040.0003':
                    if (preg_match('/^(\d{2})(\d{2})(\d{2})$/', $value, $parts)) {
                        $dicomData['scheduled_time'] = $parts[1] . ':' . $parts[2] . ':' . $parts[3];
                    }
                    break;
                case '0040.0007':
                    $dicomData['procedure_description'] = $value;
                    break;
                case '0032.1060':
                    $dicomData['reason_for_study'] = $value;
                    break;
            }
        }
    }

    if (empty($dicomData['accession_number'])) {
        throw new Exception('El archivo TXT debe contener el número de acceso (0008.0050)');
    }

    // Inferir modalidad desde el nombre del equipo (0040.0001) cuando no viene (0008.0060).
    if (empty($dicomData['modality']) && !empty($dicomData['equipment_name'])) {
        $dicomData['modality'] = ir_infer_modality_from_equipment($dicomData['equipment_name']);
    }

    return $dicomData;
}

/**
 * Intenta inferir la modalidad DICOM a partir del nombre del equipo (tag 0040.0001).
 * Retorna la modalidad inferida (p.ej. 'CT', 'MR') o cadena vacía si no puede determinarse.
 */
function ir_infer_modality_from_equipment(string $equipmentName): string
{
    $n = strtolower(trim($equipmentName));
    if ($n === '') {
        return '';
    }

    // Tomógrafo / CT
    if (preg_match('/tom[oó]grafo|tomog|scanner|tac\b|ct\b|comput.*tomog/u', $n)) {
        return 'CT';
    }

    // Resonancia / MR
    if (preg_match('/resonad|resonanc|magneti|mri\b|\bmr\b/u', $n)) {
        return 'MR';
    }

    // Ecógrafo / Ultrasonido
    if (preg_match('/ecog|eco\b|ultras|sonogr/u', $n)) {
        return 'US';
    }

    // Mamógrafo
    if (preg_match('/mam[oó]grafo|mamog/u', $n)) {
        return 'MG';
    }

    // Rx / Radiografía (genérico — CR o DX, preferimos CR por ser lo más frecuente en el sistema)
    if (preg_match('/radio|rx\b|rayos\s*x|placa|cr\b|dx\b/u', $n)) {
        return 'CR';
    }

    return '';
}
