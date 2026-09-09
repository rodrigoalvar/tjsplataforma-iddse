<?php
/**
 * Gestor de sincronización de worklists hacia Orthanc.
 * Soporta modo filesystem (legacy) y REST (plugin nuevo).
 */

require_once __DIR__ . '/DicomWorklistGenerator.php';

class OrthancWorklistManager
{
    public static function publish(array $item, array $config): array
    {
        $mode = strtolower((string)($config['orthanc_worklist_mode'] ?? 'filesystem'));
        if ($mode === 'rest') {
            return self::publishRest($item, $config);
        }
        return self::publishFilesystem($item, $config);
    }

    public static function remove(array $item, array $config): array
    {
        $mode = strtolower((string)($config['orthanc_worklist_mode'] ?? 'filesystem'));
        if ($mode === 'rest') {
            return self::removeRest($item, $config);
        }
        return self::removeFilesystem($item, $config);
    }

    public static function testConnection(array $config): array
    {
        $mode = strtolower((string)($config['orthanc_worklist_mode'] ?? 'filesystem'));
        if ($mode === 'rest') {
            return self::testRestConnection($config);
        }
        return self::testFilesystemConnection($config);
    }

    /**
     * Lista worklists en Orthanc (modo REST): id + AccessionNumber.
     * Usa GET /worklists/?format=Full si está disponible; si no, Short + detalle por ítem.
     *
     * @return array<int, array{id: string, accession: string}>
     */
    public static function listRestWorklistsWithAccessions(array $config): array
    {
        $baseUrl = rtrim((string)($config['orthanc_rest_base_url'] ?? ''), '/');
        if ($baseUrl === '') {
            throw new Exception('No se configuró orthanc_rest_base_url');
        }

        $parsed = null;
        try {
            $full = self::request('GET', $baseUrl . '/worklists/?format=Full', null, $config, 120);
            $parsed = self::parseFullWorklistsResponse($full);
        } catch (Exception $e) {
            error_log('Orthanc worklists format=Full no disponible o error: ' . $e->getMessage());
        }
        if ($parsed !== null) {
            return $parsed;
        }

        $short = self::request('GET', $baseUrl . '/worklists/?format=Short', null, $config, 60);
        $ids = self::extractWorklistIdsFromShort($short);
        $out = [];
        $perItemTimeout = min(30, max(8, (int)($config['orthanc_rest_timeout'] ?? 15)));
        $maxItems = 500;
        $n = 0;
        foreach ($ids as $id) {
            if ($n++ >= $maxItems) {
                break;
            }
            $id = trim((string)$id);
            if ($id === '') {
                continue;
            }
            $detail = self::request(
                'GET',
                $baseUrl . '/worklists/' . rawurlencode($id),
                null,
                $config,
                $perItemTimeout
            );
            $acc = self::extractAccessionFromWorklistDocument($detail) ?? '';
            $out[] = ['id' => $id, 'accession' => $acc];
        }
        return $out;
    }

    /**
     * Accession -> existe archivo {accession}.wl en el directorio configurado.
     *
     * @return array<string, true> conjunto de accession presentes
     */
    public static function listFilesystemWorklistAccessions(array $config): array
    {
        $targetDir = rtrim((string)($config['orthanc_worklist_path'] ?? ''), '/');
        if ($targetDir === '' || !is_dir($targetDir)) {
            return [];
        }
        $set = [];
        foreach (glob($targetDir . '/*.wl') ?: [] as $path) {
            $base = basename($path, '.wl');
            if ($base !== '') {
                $set[$base] = true;
            }
        }
        return $set;
    }

    /**
     * @param mixed $full Respuesta decodificada de /worklists/?format=Full
     * @return array<int, array{id: string, accession: string}>|null
     */
    private static function parseFullWorklistsResponse($full): ?array
    {
        if (!is_array($full) || $full === []) {
            return [];
        }

        // A veces Orthanc devuelve objeto con claves UUID; otras, lista de objetos.
        $items = [];
        $keysLookLikeUuid = true;
        foreach ($full as $k => $v) {
            if (!is_string($k) || !preg_match('/^[0-9a-f-]{36}$/i', $k)) {
                $keysLookLikeUuid = false;
                break;
            }
        }
        if ($keysLookLikeUuid && !isset($full[0])) {
            foreach ($full as $id => $doc) {
                if (!is_array($doc)) {
                    $items[] = ['id' => (string)$id, 'accession' => self::extractAccessionFromWorklistDocument($doc) ?? ''];
                } else {
                    $items[] = [
                        'id' => (string)$id,
                        'accession' => self::extractAccessionFromWorklistDocument($doc) ?? ''
                    ];
                }
            }
            return $items;
        }

        if (isset($full[0]) && is_array($full[0])) {
            foreach ($full as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (string)($row['ID'] ?? $row['id'] ?? '');
                if ($id === '' && isset($row['Path']) && preg_match('#/worklists/([^/]+)#', (string)$row['Path'], $m)) {
                    $id = $m[1];
                }
                if ($id === '') {
                    continue;
                }
                $items[] = [
                    'id' => $id,
                    'accession' => self::extractAccessionFromWorklistDocument($row) ?? ''
                ];
            }
            return $items;
        }

        return null;
    }

    /**
     * @param mixed $short
     * @return list<string>
     */
    private static function extractWorklistIdsFromShort($short): array
    {
        if (!is_array($short)) {
            return [];
        }
        $ids = [];
        foreach ($short as $k => $v) {
            if (is_string($k) && preg_match('/^[0-9a-f-]{36}$/i', $k)) {
                $ids[] = $k;
            }
            if (is_string($v) && preg_match('/^[0-9a-f-]{36}$/i', $v)) {
                $ids[] = $v;
            } elseif (is_array($v)) {
                $id = $v['ID'] ?? $v['id'] ?? null;
                if (is_string($id) && $id !== '') {
                    $ids[] = $id;
                }
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids !== []) {
            return $ids;
        }
        foreach ($short as $v) {
            if (is_string($v) && $v !== '') {
                $ids[] = $v;
            }
        }
        return $ids;
    }

    /**
     * Extrae AccessionNumber de la respuesta de un worklist (Full/Simplify/instancia).
     */
    public static function extractAccessionFromWorklistDocument(?array $doc): ?string
    {
        if ($doc === null || $doc === []) {
            return null;
        }
        $try = [
            ['MainDicomTags', 'AccessionNumber'],
            ['Tags', 'AccessionNumber'],
        ];
        foreach ($try as $path) {
            $cur = $doc;
            foreach ($path as $p) {
                if (!is_array($cur) || !array_key_exists($p, $cur)) {
                    $cur = null;
                    break;
                }
                $cur = $cur[$p];
            }
            if (is_string($cur) && trim($cur) !== '') {
                return trim($cur);
            }
        }
        if (isset($doc['AccessionNumber']) && is_string($doc['AccessionNumber']) && trim($doc['AccessionNumber']) !== '') {
            return trim($doc['AccessionNumber']);
        }
        // Formato Simplify: "0008,0050"
        if (isset($doc['0008,0050']) && is_string($doc['0008,0050']) && trim($doc['0008,0050']) !== '') {
            return trim($doc['0008,0050']);
        }
        return self::deepFindAccession($doc);
    }

    private static function deepFindAccession($node, int $depth = 0): ?string
    {
        if ($depth > 12 || !is_array($node)) {
            return null;
        }
        foreach (['AccessionNumber', '0008,0050'] as $key) {
            if (isset($node[$key]) && is_string($node[$key]) && trim($node[$key]) !== '') {
                return trim($node[$key]);
            }
        }
        foreach ($node as $v) {
            $f = self::deepFindAccession($v, $depth + 1);
            if ($f !== null) {
                return $f;
            }
        }
        return null;
    }

    private static function publishFilesystem(array $item, array $config): array
    {
        $accession = (string)($item['accession_number'] ?? '');
        if ($accession === '') {
            throw new Exception('No se puede sincronizar: accession_number vacío');
        }

        $targetDir = rtrim((string)($config['orthanc_worklist_path'] ?? ''), '/');
        if ($targetDir === '') {
            throw new Exception('No se configuró orthanc_worklist_path');
        }

        $tempPath = sys_get_temp_dir() . '/' . $accession . '.wl';
        DicomWorklistGenerator::generate($item, $tempPath);

        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
            throw new Exception("No se pudo crear el directorio de worklists: $targetDir");
        }

        $targetPath = $targetDir . '/' . $accession . '.wl';
        if (!copy($tempPath, $targetPath)) {
            @unlink($tempPath);
            throw new Exception("No se pudo copiar el archivo a: $targetPath");
        }

        @unlink($tempPath);

        return [
            'mode' => 'filesystem',
            'orthanc_worklist_id' => null,
            'orthanc_resource' => $targetPath
        ];
    }

    private static function removeFilesystem(array $item, array $config): array
    {
        $accession = (string)($item['accession_number'] ?? '');
        if ($accession === '') {
            throw new Exception('No se puede eliminar worklist: accession_number vacío');
        }

        $targetDir = rtrim((string)($config['orthanc_worklist_path'] ?? ''), '/');
        if ($targetDir === '') {
            throw new Exception('No se configuró orthanc_worklist_path');
        }

        $targetPath = $targetDir . '/' . $accession . '.wl';
        if (is_file($targetPath) && !@unlink($targetPath)) {
            throw new Exception("No se pudo eliminar el archivo: $targetPath");
        }

        return [
            'mode' => 'filesystem',
            'orthanc_worklist_id' => null,
            'orthanc_resource' => $targetPath
        ];
    }

    private static function publishRest(array $item, array $config): array
    {
        $baseUrl = rtrim((string)($config['orthanc_rest_base_url'] ?? ''), '/');
        if ($baseUrl === '') {
            throw new Exception('No se configuró orthanc_rest_base_url');
        }

        $tags = self::buildOrthancTags($item);
        $response = self::request(
            'POST',
            $baseUrl . '/worklists/create',
            ['Tags' => $tags],
            $config
        );

        $id = $response['ID'] ?? null;
        $path = $response['Path'] ?? null;

        if (!$id) {
            throw new Exception('Orthanc no devolvió ID de worklist');
        }

        return [
            'mode' => 'rest',
            'orthanc_worklist_id' => $id,
            'orthanc_resource' => $path
        ];
    }

    private static function removeRest(array $item, array $config): array
    {
        $baseUrl = rtrim((string)($config['orthanc_rest_base_url'] ?? ''), '/');
        if ($baseUrl === '') {
            throw new Exception('No se configuró orthanc_rest_base_url');
        }

        $remoteId = (string)($item['orthanc_worklist_id'] ?? '');
        if ($remoteId === '') {
            return [
                'mode' => 'rest',
                'orthanc_worklist_id' => null,
                'orthanc_resource' => null,
                'warning' => 'No hay orthanc_worklist_id para eliminar en modo REST'
            ];
        }

        self::request('DELETE', $baseUrl . '/worklists/' . rawurlencode($remoteId), null, $config);
        return [
            'mode' => 'rest',
            'orthanc_worklist_id' => $remoteId,
            'orthanc_resource' => '/worklists/' . $remoteId
        ];
    }

    private static function testRestConnection(array $config): array
    {
        $baseUrl = rtrim((string)($config['orthanc_rest_base_url'] ?? ''), '/');
        if ($baseUrl === '') {
            throw new Exception('No se configuró orthanc_rest_base_url');
        }

        self::request('GET', $baseUrl . '/worklists/?format=Short', null, $config);
        return ['success' => true, 'message' => 'Conexión REST con Orthanc exitosa'];
    }

    private static function testFilesystemConnection(array $config): array
    {
        $targetDir = rtrim((string)($config['orthanc_worklist_path'] ?? ''), '/');
        if ($targetDir === '') {
            throw new Exception('No se configuró orthanc_worklist_path');
        }

        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) {
            throw new Exception("No existe y no se pudo crear directorio: $targetDir");
        }
        if (!is_writable($targetDir)) {
            throw new Exception("Sin permisos de escritura en: $targetDir");
        }

        return ['success' => true, 'message' => 'Directorio de worklist accesible'];
    }

    /**
     * Texto legible para logs cuando Orthanc devuelve JSON de error (p. ej. 500).
     */
    private static function formatHttpErrorResponse(int $code, string $raw): string
    {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $msg = trim((string)($decoded['Message'] ?? $decoded['OrthancError'] ?? ''));
            $uri = trim((string)($decoded['Uri'] ?? ''));
            if ($msg !== '') {
                $line = "Orthanc HTTP $code: $msg" . ($uri !== '' ? " [$uri]" : '');
                $hint = '';
                if ($code >= 500 && stripos($msg, 'Cannot write to file') !== false) {
                    $hint = ' En Orthanc: (1) Si usás solo REST en BD, poner "SaveInOrthancDatabase": true y un índice '
                        . 'compatible (SQLite/PostgreSQL según libro Orthanc); reiniciar Orthanc y comprobar que el '
                        . 'GET /system muestra el plugin. (2) Si SaveInOrthancDatabase es false, crear `Directory`, '
                        . 'chown al usuario del servicio Orthanc y permisos de escritura. Probar: curl -X POST …/worklists/create '
                        . 'con el mismo JSON que el portal.';
                }
                return $line . $hint;
            }
        }

        return "Orthanc respondió HTTP $code: " . trim(substr($raw, 0, 2000));
    }

    private static function request(string $method, string $url, ?array $payload, array $config, ?int $timeoutSeconds = null): array
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new Exception('No se pudo inicializar cURL');
        }

        $headers = ['Content-Type: application/json'];
        $user = (string)($config['orthanc_rest_user'] ?? '');
        $pass = (string)($config['orthanc_rest_pass'] ?? '');
        $timeout = $timeoutSeconds ?? (int)($config['orthanc_rest_timeout'] ?? 15);
        $verify = (int)($config['orthanc_rest_verify_ssl'] ?? 0) === 1;

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, max(5, $timeout));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify);

        if ($user !== '') {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $pass);
        }
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        }

        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new Exception('Error de conexión a Orthanc: ' . $err);
        }
        if ($code < 200 || $code >= 300) {
            if ($code === 401) {
                throw new Exception(
                    'Orthanc HTTP 401 (credenciales REST rechazadas). Revise orthanc_rest_user y '
                    . 'orthanc_rest_pass en worklist_config (deben coincidir con RegisteredUsers de Orthanc). '
                    . 'Detalle: ' . trim((string)substr($raw, 0, 300))
                );
            }
            throw new Exception(self::formatHttpErrorResponse($code, $raw));
        }

        if ($raw === '' || $raw === null) {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : ['raw' => $raw];
    }

    private static function buildOrthancTags(array $item): array
    {
        $reason = trim((string)($item['reason_for_study'] ?? ''));
        $procedure = trim((string)($item['procedure_description'] ?? ''));
        // RequestedProcedureDescription (0032,1060); muchos TXT solo traen descripción en el paso programado (0040,0007).
        $requestedDesc = $reason !== '' ? $reason : $procedure;

        $tags = [
            'AccessionNumber' => (string)($item['accession_number'] ?? ''),
            'PatientID' => (string)($item['patient_id'] ?? ''),
            'PatientName' => self::toDicomPatientName((string)($item['patient_name'] ?? '')),
            'PatientBirthDate' => self::toDicomDate((string)($item['patient_birth_date'] ?? '')),
            'PatientSex' => (string)($item['patient_sex'] ?? ''),
            'ReferringPhysicianName' => (string)($item['referring_physician'] ?? ''),
            'RequestedProcedureDescription' => $requestedDesc,
            'ScheduledProcedureStepSequence' => [[
                'ScheduledStationAETitle' => (string)($item['equipment_name'] ?? ''),
                'Modality' => (string)($item['modality'] ?? ''),
                'ScheduledProcedureStepStartDate' => self::toDicomDate((string)($item['scheduled_date'] ?? '')),
                'ScheduledProcedureStepStartTime' => self::toDicomTime((string)($item['scheduled_time'] ?? '')),
                'ScheduledProcedureStepDescription' => $procedure !== '' ? $procedure : $reason
            ]]
        ];

        return array_filter($tags, function ($v) {
            if (is_array($v)) {
                return !empty($v);
            }
            return $v !== '' && $v !== null;
        });
    }

    private static function toDicomDate(string $date): string
    {
        if ($date === '') {
            return '';
        }
        if (preg_match('/^\d{8}$/', $date)) {
            return $date;
        }
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        return $dt ? $dt->format('Ymd') : '';
    }

    private static function toDicomTime(string $time): string
    {
        if ($time === '') {
            return '';
        }
        if (preg_match('/^\d{6}$/', $time)) {
            return $time;
        }
        $dt = DateTime::createFromFormat('H:i:s', $time);
        if (!$dt) {
            $dt = DateTime::createFromFormat('H:i', $time);
        }
        return $dt ? $dt->format('His') : '';
    }

    private static function toDicomPatientName(string $name): string
    {
        if ($name === '' || strpos($name, '^') !== false) {
            return $name;
        }
        $parts = preg_split('/\s+/', trim($name), 2);
        if (count($parts) < 2) {
            return $name;
        }
        return $parts[0] . '^' . $parts[1];
    }
}
