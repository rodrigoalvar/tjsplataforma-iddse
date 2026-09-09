<?php
/**
 * Ajuste opcional de metadatos PDF (Title, Author) según configuración.
 * Requiere ExifTool en el servidor (paquete libimage-exiftool-perl en Debian/Ubuntu).
 * Si los campos de configuración están vacíos, exiftool no está instalado o el PDF no es
 * escribible, no se hace nada (no se lanza excepción: no altera flujos de ingesta/PACS).
 */

if (!function_exists('ir_getPdfMetadataConfig')) {
    /**
     * Lee las claves ir_pdf_metadata_title e ir_pdf_metadata_author de configuración.
     * @return array{title:string, author:string}
     */
    function ir_getPdfMetadataConfig(PDO $db): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        try {
            $st = $db->prepare('SELECT clave, valor FROM configuracion WHERE clave IN (?,?) LIMIT 2');
            $st->execute(['ir_pdf_metadata_title', 'ir_pdf_metadata_author']);
            $rows = $st->fetchAll(PDO::FETCH_KEY_PAIR);
            $cache = [
                'title'  => trim((string)($rows['ir_pdf_metadata_title']  ?? '')),
                'author' => trim((string)($rows['ir_pdf_metadata_author'] ?? '')),
            ];
        } catch (Throwable $e) {
            error_log('[PDF_META] leer config: ' . $e->getMessage());
            $cache = ['title' => '', 'author' => ''];
        }
        return $cache;
    }
}

// Mantener compatibilidad con cualquier llamada directa a la función antigua
if (!function_exists('ir_getPdfMetadataTitleConfig')) {
    function ir_getPdfMetadataTitleConfig(PDO $db): string
    {
        return ir_getPdfMetadataConfig($db)['title'];
    }
}

if (!function_exists('ir_findExiftoolBinary')) {
    function ir_findExiftoolBinary(): ?string
    {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved === false ? null : $resolved;
        }
        $candidates = [
            '/usr/bin/exiftool',
            '/usr/local/bin/exiftool',
        ];
        foreach ($candidates as $p) {
            if (is_file($p) && is_executable($p)) {
                $resolved = $p;
                return $resolved;
            }
        }
        $which = trim((string)@shell_exec('command -v exiftool 2>/dev/null'));
        if ($which !== '' && is_file($which) && is_executable($which)) {
            $resolved = $which;
            return $resolved;
        }
        $resolved = false;
        return null;
    }
}

if (!function_exists('ir_sanitizePdfMetaValue')) {
    function ir_sanitizePdfMetaValue(string $value): string
    {
        // Caracteres que romperían el argumento de exiftool
        if (str_contains($value, "\0") || str_contains($value, "\n") || str_contains($value, "\r")) {
            return '';
        }
        return mb_strlen($value) > 500 ? mb_substr($value, 0, 500) : $value;
    }
}

if (!function_exists('ir_applyPdfTitleMetadataIfConfigured')) {
    /**
     * Si alguna de las configuraciones ir_pdf_metadata_title / ir_pdf_metadata_author no está
     * vacía, aplica los metadatos al PDF en una sola llamada a exiftool.
     * Nunca lanza excepción: errores → error_log.
     */
    function ir_applyPdfTitleMetadataIfConfigured(PDO $db, string $absPath): void
    {
        $cfg = ir_getPdfMetadataConfig($db);

        $title  = ir_sanitizePdfMetaValue($cfg['title']);
        $author = ir_sanitizePdfMetaValue($cfg['author']);

        // Si ambos están vacíos, nada que hacer
        if ($title === '' && $author === '') {
            return;
        }

        if ($absPath === '' || !is_file($absPath) || !is_readable($absPath) || !is_writable($absPath)) {
            return;
        }
        if (strtolower(pathinfo($absPath, PATHINFO_EXTENSION)) !== 'pdf') {
            return;
        }

        $exif = ir_findExiftoolBinary();
        if ($exif === null) {
            static $loggedMissing = false;
            if (!$loggedMissing) {
                error_log('[PDF_META] exiftool no disponible: instalar p. ej. libimage-exiftool-perl (no se modifican metadatos PDF).');
                $loggedMissing = true;
            }
            return;
        }
        if (!function_exists('proc_open')) {
            return;
        }

        // Construir argumentos solo para los campos configurados
        $cmd = [$exif];
        if ($title !== '') {
            $cmd[] = '-Title=' . $title;
        }
        if ($author !== '') {
            $cmd[] = '-Author=' . $author;
        }
        $cmd[] = '-overwrite_original';
        $cmd[] = '-q';
        $cmd[] = $absPath;

        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $desc, $pipes, null, null);
        if (!is_resource($proc)) {
            error_log('[PDF_META] no se pudo ejecutar exiftool');
            return;
        }
        if (isset($pipes[0])) {
            fclose($pipes[0]);
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) {
            error_log('[PDF_META] exiftool exit=' . $code . ' stderr=' . trim($err) . ' stdout=' . trim($out) . ' file=' . $absPath);
        }
    }
}
