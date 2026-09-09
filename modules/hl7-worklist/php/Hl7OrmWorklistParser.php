<?php
/**
 * Parser HL7 v2 ORM^O01 → payload normalizado de worklist
 * (mismos keys que TxtWorklistParser).
 */

class Hl7OrmWorklistParser
{
    public const PRESTADOR_FIELDS = ['PV1-7', 'PV1-8', 'OBR-16', 'NONE'];

    /**
     * @param string $content Mensaje HL7 crudo
     * @param string $prestadorField PV1-7|PV1-8|OBR-16|none
     * @return array
     */
    public static function parseContent(string $content, string $prestadorField = 'PV1-8'): array
    {
        $prestadorField = strtoupper(trim($prestadorField));
        if (!in_array($prestadorField, self::PRESTADOR_FIELDS, true)) {
            $prestadorField = 'PV1-8';
        }

        $segments = self::splitSegments($content);
        if (empty($segments)) {
            throw new Exception('Mensaje HL7 vacío o sin segmentos');
        }

        $byType = [];
        foreach ($segments as $seg) {
            $name = self::segmentName($seg);
            if ($name === '') {
                continue;
            }
            // Primer segmento de cada tipo (suficiente para ORM^O01 típico)
            if (!isset($byType[$name])) {
                $byType[$name] = $seg;
            }
        }

        if (!isset($byType['MSH'])) {
            throw new Exception('Segmento MSH requerido');
        }

        $pid = $byType['PID'] ?? '';
        $pv1 = $byType['PV1'] ?? '';
        $orc = $byType['ORC'] ?? '';
        $obr = $byType['OBR'] ?? '';
        $ipc = $byType['IPC'] ?? '';

        $orderControl = strtoupper(trim(self::fieldComponent($orc, 1, 1)));
        $isCancel = in_array($orderControl, ['CA', 'OC', 'DC'], true);

        $accession = self::firstNonEmpty([
            self::fieldComponent($orc, 2, 1),
            self::fieldComponent($obr, 2, 1),
            self::fieldComponent($ipc, 1, 1),
        ]);

        $patientId = self::extractPatientId($pid);
        $patientName = self::formatPatientName(self::field($pid, 5));
        $dob = self::hl7DateToSql(self::fieldComponent($pid, 7, 1));
        $sex = self::normalizeSex(self::fieldComponent($pid, 8, 1));

        $modality = self::firstNonEmpty([
            self::fieldComponent($obr, 24, 1),
            self::fieldComponent($ipc, 5, 1),
            self::fieldComponent($obr, 25, 1), // algunos emisores desplazan MG
        ]);

        $procedure = self::firstNonEmpty([
            self::fieldComponent($ipc, 7, 1),
            self::field($obr, 19), // descripción libre frecuente
            self::fieldComponent($obr, 4, 2),
            self::fieldComponent($obr, 4, 1),
            self::field($obr, 4),
        ]);

        $scheduleRaw = self::firstNonEmpty([
            self::extractScheduleTimestamp($orc, 7),
            self::extractScheduleTimestamp($obr, 27),
            self::fieldComponent($orc, 7, 4),
            self::fieldComponent($obr, 27, 4),
        ]);

        [$scheduledDate, $scheduledTime] = self::parseSchedule($scheduleRaw);

        $referring = null;
        if ($prestadorField !== 'NONE') {
            $referring = self::extractPrestador($prestadorField, $pv1, $obr);
        }

        $msgControlId = isset($byType['MSH']) ? self::field($byType['MSH'], 10) : '';

        $data = [
            'accession_number' => $accession,
            'patient_id' => $patientId !== '' ? $patientId : null,
            'patient_name' => $patientName !== '' ? $patientName : null,
            'patient_birth_date' => $dob,
            'patient_sex' => $sex,
            'modality' => $modality !== '' ? $modality : null,
            'referring_physician' => $referring,
            'equipment_name' => null,
            'scheduled_date' => $scheduledDate,
            'scheduled_time' => $scheduledTime,
            'procedure_description' => $procedure !== '' ? $procedure : null,
            'reason_for_study' => $procedure !== '' ? $procedure : null,
            'status' => $isCancel ? 'cancelled' : 'pending',
            'order_control' => $orderControl !== '' ? $orderControl : null,
            'message_control_id' => $msgControlId !== '' ? $msgControlId : null,
        ];

        if ($isCancel) {
            self::validateCancel($data);
        } else {
            self::validate($data);
        }
        return $data;
    }

    public static function validate(array $data): void
    {
        if (empty($data['accession_number'])) {
            throw new Exception('El campo accession_number es requerido');
        }
        if (empty($data['scheduled_date'])) {
            throw new Exception('El campo scheduled_date es requerido');
        }
        if (empty($data['scheduled_time'])) {
            throw new Exception('El campo scheduled_time es requerido');
        }
    }

    /**
     * Cancelación ORM (ORC-1 = CA/OC/DC): solo exige accession.
     */
    public static function validateCancel(array $data): void
    {
        if (empty($data['accession_number'])) {
            throw new Exception('Cancelación HL7 sin accession_number (ORC-2/OBR-2)');
        }
    }

    /**
     * ¿Orden de cancelación (ORC-1)?
     */
    public static function isCancelOrderControl(?string $orderControl): bool
    {
        $oc = strtoupper(trim((string)$orderControl));
        return in_array($oc, ['CA', 'OC', 'DC'], true);
    }

    /**
     * Extrae MSH-10 (Message Control ID) para ACK.
     */
    public static function extractMessageControlId(string $content): string
    {
        $segments = self::splitSegments($content);
        foreach ($segments as $seg) {
            if (self::segmentName($seg) === 'MSH') {
                $id = self::field($seg, 10);
                return $id !== '' ? $id : ('ACK' . date('YmdHis'));
            }
        }
        return 'ACK' . date('YmdHis');
    }

    private static function splitSegments(string $content): array
    {
        $content = str_replace(["\r\n", "\n"], "\r", $content);
        $parts = explode("\r", $content);
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return $out;
    }

    private static function segmentName(string $seg): string
    {
        $pipe = strpos($seg, '|');
        if ($pipe === false) {
            return strtoupper(trim($seg));
        }
        return strtoupper(trim(substr($seg, 0, $pipe)));
    }

    /**
     * Campo N (1-based). MSH: el separador de campos es el primer char tras MSH,
     * y el índice 1 es el encoding characters — usamos índice estándar HL7.
     */
    private static function field(string $seg, int $index): string
    {
        if ($seg === '' || $index < 1) {
            return '';
        }
        $name = self::segmentName($seg);
        $parts = explode('|', $seg);

        if ($name === 'MSH') {
            // parts[0]=MSH, parts[1]=^~\&, parts[2]=sending app = MSH-3
            // MSH-N => parts[N-1] for N>=2; MSH-1 is field separator itself
            if ($index === 1) {
                return '|';
            }
            return isset($parts[$index - 1]) ? trim($parts[$index - 1]) : '';
        }

        // parts[0]=SEG, parts[1]=field1, ...
        return isset($parts[$index]) ? trim($parts[$index]) : '';
    }

    private static function fieldComponent(string $seg, int $fieldIndex, int $componentIndex): string
    {
        $field = self::field($seg, $fieldIndex);
        if ($field === '') {
            return '';
        }
        $comps = explode('^', $field);
        $idx = $componentIndex - 1;
        return isset($comps[$idx]) ? trim($comps[$idx]) : '';
    }

    private static function firstNonEmpty(array $values): string
    {
        foreach ($values as $v) {
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }
        return '';
    }

    private static function extractPatientId(string $pid): string
    {
        // Preferir CX marcado como DNI (PID-2 o PID-3 según emisor)
        foreach ([2, 3] as $fi) {
            $raw = self::field($pid, $fi);
            if ($raw === '') {
                continue;
            }
            foreach (explode('~', $raw) as $rep) {
                $comps = explode('^', $rep);
                $id = trim($comps[0] ?? '');
                $idType = strtoupper(trim($comps[4] ?? ''));
                if ($id !== '' && $idType === 'DNI') {
                    return $id;
                }
            }
        }
        // Fallback: PID-2 luego PID-3 (primer componente)
        foreach ([2, 3] as $fi) {
            $id = self::fieldComponent($pid, $fi, 1);
            if ($id !== '') {
                return $id;
            }
        }
        return '';
    }

    private static function formatPatientName(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        // APELLIDO^NOMBRE → espacio
        return trim(preg_replace('/\s+/', ' ', str_replace('^', ' ', $raw)));
    }

    private static function hl7DateToSql(?string $yyyymmdd): ?string
    {
        if (!$yyyymmdd || !preg_match('/^(\d{4})(\d{2})(\d{2})/', $yyyymmdd, $m)) {
            return null;
        }
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }

    private static function normalizeSex(?string $sex): ?string
    {
        if ($sex === null || $sex === '') {
            return null;
        }
        $s = strtoupper(substr($sex, 0, 1));
        return in_array($s, ['M', 'F', 'O'], true) ? $s : 'O';
    }

    /**
     * TQ / timing: puede ser "1^^10^20260805084000^20260805085000" (ORC-7)
     */
    private static function extractScheduleTimestamp(string $seg, int $fieldIndex): string
    {
        $raw = self::field($seg, $fieldIndex);
        if ($raw === '') {
            return '';
        }
        // Buscar componente con 12+ dígitos (YYYYMMDDHHMMSS)
        $comps = explode('^', $raw);
        foreach ($comps as $c) {
            $c = trim($c);
            if (preg_match('/^\d{12,14}$/', $c)) {
                return $c;
            }
        }
        if (preg_match('/\d{12,14}/', $raw, $m)) {
            return $m[0];
        }
        return '';
    }

    /**
     * @return array{0:?string,1:?string} [date Y-m-d, time H:i:s]
     */
    private static function parseSchedule(string $raw): array
    {
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})?/', $raw, $m)) {
            return [null, null];
        }
        $date = $m[1] . '-' . $m[2] . '-' . $m[3];
        $sec = isset($m[6]) && $m[6] !== '' ? $m[6] : '00';
        $time = $m[4] . ':' . $m[5] . ':' . $sec;
        return [$date, $time];
    }

    private static function extractPrestador(string $fieldSpec, string $pv1, string $obr): ?string
    {
        $raw = '';
        switch ($fieldSpec) {
            case 'PV1-7':
                $raw = self::field($pv1, 7);
                break;
            case 'PV1-8':
                $raw = self::field($pv1, 8);
                break;
            case 'OBR-16':
                $raw = self::field($obr, 16);
                break;
            default:
                return null;
        }
        if ($raw === '') {
            return null;
        }
        $id = explode('^', $raw)[0];
        $id = trim($id);
        return $id !== '' ? $id : null;
    }
}
