<?php
/**
 * Envío de informes PDF a Gasalud (API PortalPaciente).
 *
 * Auth: POST login JSON → Bearer token (cache en configuracion)
 * Envío: multipart/form-data con campo binario Archivo
 *
 * Solo origen=plataforma (nunca externo / API recibidos → evita loop).
 */

if (!function_exists('gasalud_config_value')) {
    function gasalud_config_value(PDO $db, string $key, string $default = ''): string
    {
        static $cache = [];
        $ck = spl_object_id($db) . '|' . $key;
        if (array_key_exists($ck, $cache)) {
            return $cache[$ck];
        }
        try {
            $st = $db->prepare('SELECT valor FROM configuracion WHERE clave = ? LIMIT 1');
            $st->execute([$key]);
            $v = $st->fetchColumn();
            $cache[$ck] = ($v === false || $v === null) ? $default : (string)$v;
        } catch (Throwable $e) {
            $cache[$ck] = $default;
        }
        return $cache[$ck];
    }
}

if (!function_exists('gasalud_config_forget')) {
    function gasalud_config_forget(PDO $db, string $key): void
    {
        // Forzar relectura: sobrescribe vía set + nueva clave de cache por valor fresco
        // (la cache estática se invalida escribiendo y usando gasalud_config_value_uncached en login)
    }
}

if (!function_exists('gasalud_set_config_value')) {
    function gasalud_set_config_value(PDO $db, string $key, string $value): void
    {
        $st = $db->prepare('INSERT INTO configuracion (clave, valor) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE valor = VALUES(valor)');
        $st->execute([$key, $value]);
    }
}

if (!function_exists('gasalud_load_envio_config')) {
    /** @return array<string,mixed> */
    function gasalud_load_envio_config(PDO $db): array
    {
        return [
            'activo' => gasalud_config_value($db, 'gasalud_envio_activo', '0') === '1',
            'url' => trim(gasalud_config_value($db, 'gasalud_api_url', '')),
            'login_url' => trim(gasalud_config_value($db, 'gasalud_login_url', '')),
            'method' => strtoupper(trim(gasalud_config_value($db, 'gasalud_http_method', 'POST'))) ?: 'POST',
            'auth_mode' => trim(gasalud_config_value($db, 'gasalud_auth_mode', 'login')) ?: 'login',
            'auth_token' => gasalud_config_value($db, 'gasalud_auth_token', ''),
            'token_expires_at' => trim(gasalud_config_value($db, 'gasalud_token_expires_at', '')),
            'api_key_header' => trim(gasalud_config_value($db, 'gasalud_api_key_header', 'X-API-Key')) ?: 'X-API-Key',
            'auth_username' => gasalud_config_value($db, 'gasalud_auth_username', ''),
            'auth_password' => gasalud_config_value($db, 'gasalud_auth_password', ''),
            'tipo_default' => trim(gasalud_config_value($db, 'gasalud_tipo_default', 'pdf')) ?: 'pdf',
            'prestador_modo' => trim(gasalud_config_value($db, 'gasalud_prestador_modo', 'worklist')) ?: 'worklist',
            'trigger' => trim(gasalud_config_value($db, 'gasalud_trigger', 'al_pacs')) ?: 'al_pacs',
            'timeout_sec' => max(5, (int)gasalud_config_value($db, 'gasalud_timeout_sec', '60')),
            'verify_ssl' => gasalud_config_value($db, 'gasalud_verify_ssl', '0') === '1',
        ];
    }
}

if (!function_exists('gasalud_build_auth_headers')) {
    /** @return string[] */
    function gasalud_build_auth_headers(array $cfg): array
    {
        $headers = [];
        $mode = $cfg['auth_mode'] ?? 'none';
        if (($mode === 'bearer' || $mode === 'login') && ($cfg['auth_token'] ?? '') !== '') {
            $headers[] = 'Authorization: Bearer ' . $cfg['auth_token'];
        } elseif ($mode === 'api_key' && ($cfg['auth_token'] ?? '') !== '') {
            $name = $cfg['api_key_header'] ?? 'X-API-Key';
            $headers[] = $name . ': ' . $cfg['auth_token'];
        } elseif ($mode === 'basic') {
            $user = (string)($cfg['auth_username'] ?? '');
            $pass = (string)($cfg['auth_password'] ?? '');
            $headers[] = 'Authorization: Basic ' . base64_encode($user . ':' . $pass);
        }
        return $headers;
    }
}

if (!function_exists('gasalud_login_and_cache_token')) {
    /**
     * POST login → guarda token + expiration en configuracion.
     * @return array{token:string,expiration:?string}
     */
    function gasalud_login_and_cache_token(PDO $db, array $cfg): array
    {
        $loginUrl = trim((string)($cfg['login_url'] ?? ''));
        if ($loginUrl === '' || !preg_match('#^https?://#i', $loginUrl)) {
            throw new Exception('Configure gasalud_login_url (URL de Usuarios/Login)');
        }
        $user = (string)($cfg['auth_username'] ?? '');
        $pass = (string)($cfg['auth_password'] ?? '');
        if ($user === '' || $pass === '') {
            throw new Exception('Faltan usuario/contraseña de login Gasalud en configuración');
        }

        $payload = json_encode([
            'nombreUsuario' => $user,
            'password' => $pass,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($loginUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => min(30, (int)($cfg['timeout_sec'] ?? 60)),
            CURLOPT_SSL_VERIFYPEER => !empty($cfg['verify_ssl']),
            CURLOPT_SSL_VERIFYHOST => !empty($cfg['verify_ssl']) ? 2 : 0,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new Exception('Login Gasalud cURL: ' . $err);
        }
        $data = json_decode((string)$body, true);
        if (!is_array($data) || empty($data['token'])) {
            throw new Exception('Login Gasalud HTTP ' . $code . ' sin token válido');
        }
        $token = (string)$data['token'];
        $expiration = isset($data['expiration']) ? (string)$data['expiration'] : '';

        gasalud_set_config_value($db, 'gasalud_auth_token', $token);
        gasalud_set_config_value($db, 'gasalud_token_expires_at', $expiration);

        return ['token' => $token, 'expiration' => $expiration !== '' ? $expiration : null];
    }
}

if (!function_exists('gasalud_ensure_bearer_token')) {
    function gasalud_ensure_bearer_token(PDO $db, array &$cfg): string
    {
        $mode = $cfg['auth_mode'] ?? 'login';
        if ($mode === 'none') {
            return '';
        }
        if ($mode === 'basic' || $mode === 'api_key') {
            return (string)($cfg['auth_token'] ?? '');
        }
        if ($mode === 'bearer') {
            if (($cfg['auth_token'] ?? '') === '') {
                throw new Exception('Falta Bearer token en configuración');
            }
            return (string)$cfg['auth_token'];
        }

        // login
        $token = (string)($cfg['auth_token'] ?? '');
        $exp = trim((string)($cfg['token_expires_at'] ?? ''));
        $needLogin = ($token === '');
        if (!$needLogin && $exp !== '') {
            $ts = strtotime($exp);
            if ($ts !== false && $ts < (time() + 60)) {
                $needLogin = true;
            }
        }
        if ($needLogin) {
            $got = gasalud_login_and_cache_token($db, $cfg);
            $cfg['auth_token'] = $got['token'];
            $cfg['token_expires_at'] = (string)($got['expiration'] ?? '');
            $token = $got['token'];
        }
        return $token;
    }
}

if (!function_exists('gasalud_project_root')) {
    function gasalud_project_root(): string
    {
        $root = realpath(__DIR__ . '/../../');
        return $root !== false ? $root : dirname(__DIR__, 2);
    }
}

if (!function_exists('gasalud_resolve_pdf_abs')) {
    function gasalud_resolve_pdf_abs(?string $pdfPath): ?string
    {
        $pdfPath = trim(str_replace('\\', '/', (string)$pdfPath));
        if ($pdfPath === '') {
            return null;
        }
        $root = gasalud_project_root();
        $rel = ltrim($pdfPath, '/');
        $abs = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        return is_file($abs) ? $abs : null;
    }
}

if (!function_exists('gasalud_digits_int')) {
    function gasalud_digits_int(?string $raw): ?int
    {
        $d = preg_replace('/\D+/', '', (string)$raw);
        if ($d === null || $d === '') {
            return null;
        }
        // Evitar overflow int32 de Swagger: tomar hasta 9 dígitos significativos si es enorme
        if (strlen($d) > 9) {
            $d = substr($d, -9);
        }
        return (int)$d;
    }
}

if (!function_exists('gasalud_resolve_prestador')) {
    function gasalud_resolve_prestador(PDO $db, array $informe, array $cfg): ?int
    {
        $modo = $cfg['prestador_modo'] ?? 'worklist';
        if ($modo === 'worklist') {
            $acc = trim((string)($informe['accession_number'] ?? ''));
            if ($acc !== '') {
                $st = $db->prepare('SELECT referring_physician FROM worklist WHERE accession_number = ? LIMIT 1');
                $st->execute([$acc]);
                $ref = $st->fetchColumn();
                $n = gasalud_digits_int($ref !== false ? (string)$ref : null);
                if ($n !== null) {
                    return $n;
                }
            }
            return null;
        }
        if ($modo === 'matricula') {
            $uid = (int)($informe['usuario_id'] ?? $informe['firmado_por'] ?? 0);
            if ($uid > 0) {
                $st = $db->prepare('SELECT matricula_profesional FROM usuarios WHERE id = ? LIMIT 1');
                $st->execute([$uid]);
                return gasalud_digits_int($st->fetchColumn() ?: null);
            }
            return null;
        }
        // user_id
        $uid = (int)($informe['usuario_id'] ?? $informe['firmado_por'] ?? 0);
        return $uid > 0 ? $uid : null;
    }
}

if (!function_exists('gasalud_should_send_for_trigger')) {
    function gasalud_should_send_for_trigger(array $cfg, string $event): bool
    {
        if (empty($cfg['activo'])) {
            return false;
        }
        $t = $cfg['trigger'] ?? 'manual';
        if ($event === 'manual') {
            return true;
        }
        return $t === $event;
    }
}

if (!function_exists('gasalud_informe_is_plataforma')) {
    function gasalud_informe_is_plataforma(array $informe): bool
    {
        $origen = strtolower(trim((string)($informe['origen'] ?? 'plataforma')));
        return $origen !== 'externo';
    }
}

if (!function_exists('gasalud_try_send_informe')) {
    /**
     * Envía PDF del informe a Gasalud si aplica.
     *
     * @param string $event manual|al_finalizado|al_pacs
     * @return array{skipped:bool,success?:bool,message:string,http_code?:int,fields?:array}
     */
    function gasalud_try_send_informe(PDO $db, int $informeId, string $event = 'manual'): array
    {
        $cfg = gasalud_load_envio_config($db);
        if (!gasalud_should_send_for_trigger($cfg, $event)) {
            return [
                'skipped' => true,
                'message' => 'Envío Gasalud omitido (inactivo o trigger=' . ($cfg['trigger'] ?? '') . ', evento=' . $event . ')',
            ];
        }
        if ($cfg['url'] === '' || !preg_match('#^https?://#i', $cfg['url'])) {
            return ['skipped' => true, 'message' => 'gasalud_api_url no configurada'];
        }

        $st = $db->prepare('SELECT * FROM informes WHERE id = ? LIMIT 1');
        $st->execute([$informeId]);
        $informe = $st->fetch(PDO::FETCH_ASSOC);
        if (!$informe) {
            return ['skipped' => false, 'success' => false, 'message' => 'Informe no encontrado'];
        }
        if (!gasalud_informe_is_plataforma($informe)) {
            return [
                'skipped' => true,
                'message' => 'Informe externo/API recibidos: no se envía a Gasalud (evita loop)',
            ];
        }

        $pdfAbs = gasalud_resolve_pdf_abs($informe['pdf_path'] ?? null);
        if ($pdfAbs === null) {
            return ['skipped' => false, 'success' => false, 'message' => 'Sin PDF en disco para enviar'];
        }

        $paciente = gasalud_digits_int($informe['patient_id'] ?? null);
        if ($paciente === null) {
            return ['skipped' => false, 'success' => false, 'message' => 'Paciente (DNI) inválido o vacío'];
        }

        $accession = trim((string)($informe['accession_number'] ?? ''));
        if ($accession === '') {
            return ['skipped' => false, 'success' => false, 'message' => 'AccessionNumber vacío en informe'];
        }

        $prestador = gasalud_resolve_prestador($db, $informe, $cfg);
        if ($prestador === null) {
            return [
                'skipped' => false,
                'success' => false,
                'message' => 'Prestador (matrícula PV1/worklist) no resuelto para accession ' . $accession,
            ];
        }

        $nombre = basename($pdfAbs);
        $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
        $tipo = $ext !== '' ? $ext : strtolower((string)$cfg['tipo_default']);
        if ($tipo !== 'pdf') {
            // Política: solo PDF
            return ['skipped' => true, 'message' => 'Solo se envían PDF (tipo=' . $tipo . ')'];
        }

        $descripcion = trim((string)($informe['study_description'] ?? ''));
        if ($descripcion === '') {
            $descripcion = trim((string)($informe['titulo'] ?? 'Informe'));
        }

        try {
            gasalud_ensure_bearer_token($db, $cfg);
        } catch (Throwable $e) {
            return ['skipped' => false, 'success' => false, 'message' => $e->getMessage()];
        }

        $fields = [
            'Paciente' => $paciente,
            'Prestador' => $prestador,
            'AcessionNumber' => $accession,
            'Nombre' => $nombre,
            'Descripcion' => $descripcion,
            'Tipo' => $tipo,
        ];

        $cfile = new CURLFile($pdfAbs, 'application/pdf', $nombre);
        $post = $fields;
        $post['Archivo'] = $cfile;

        $headers = array_merge(
            ['Accept: application/json'],
            gasalud_build_auth_headers($cfg)
        );

        $ch = curl_init($cfg['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => (int)$cfg['timeout_sec'],
            CURLOPT_SSL_VERIFYPEER => !empty($cfg['verify_ssl']),
            CURLOPT_SSL_VERIFYHOST => !empty($cfg['verify_ssl']) ? 2 : 0,
        ]);
        if (strtoupper((string)$cfg['method']) === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        }

        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            error_log('[GASALUD] informe=' . $informeId . ' cURL ' . $err);
            return ['skipped' => false, 'success' => false, 'message' => 'cURL: ' . $err, 'fields' => $fields];
        }

        $ok = $code >= 200 && $code < 300;
        error_log('[GASALUD] informe=' . $informeId . ' HTTP ' . $code . ' acc=' . $accession . ' ok=' . ($ok ? '1' : '0'));
        return [
            'skipped' => false,
            'success' => $ok,
            'message' => $ok
                ? ('Enviado a Gasalud HTTP ' . $code)
                : ('Gasalud HTTP ' . $code . ': ' . substr((string)$body, 0, 300)),
            'http_code' => $code,
            'fields' => $fields,
        ];
    }
}
