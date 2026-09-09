<?php
/**
 * Helpers de rúbrica / sello para firma médica de informes.
 */

if (!function_exists('firma_build_sello_html')) {

    function firma_get_perfil(PDO $db, int $usuarioId): ?array
    {
        if ($usuarioId <= 0) {
            return null;
        }
        $stmt = $db->prepare('SELECT * FROM usuarios_firmas WHERE usuario_id = ? LIMIT 1');
        $stmt->execute([$usuarioId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    function firma_default_sello_texto(PDO $db, int $usuarioId): string
    {
        $stmt = $db->prepare('SELECT nombre, apellido, especialidad, matricula_profesional FROM usuarios WHERE id = ?');
        $stmt->execute([$usuarioId]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $nombre = trim(($u['nombre'] ?? '') . ' ' . ($u['apellido'] ?? ''));
        $esp = trim((string)($u['especialidad'] ?? ''));
        $mat = trim((string)($u['matricula_profesional'] ?? ''));
        $lines = [];
        if ($nombre !== '') {
            $lines[] = 'Dr. ' . $nombre;
        }
        if ($esp !== '') {
            $lines[] = $esp;
        }
        if ($mat !== '') {
            $lines[] = 'M.P. ' . $mat;
        }
        return implode("\n", $lines);
    }

    /**
     * Bloque HTML de firma para insertar al final del informe de plataforma.
     */
    function firma_build_sello_html(PDO $db, int $usuarioId, ?array $perfil = null): string
    {
        $perfil = $perfil ?: firma_get_perfil($db, $usuarioId);
        $texto = trim((string)($perfil['sello_texto'] ?? ''));
        if ($texto === '') {
            $texto = firma_default_sello_texto($db, $usuarioId);
        }
        $imgRel = trim((string)($perfil['imagen_path'] ?? ''));
        $imgTag = '';
        if ($imgRel !== '') {
            $abs = firma_resolve_upload_path($imgRel);
            if ($abs && is_readable($abs)) {
                $mime = 'image/png';
                $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
                if ($ext === 'jpg' || $ext === 'jpeg') {
                    $mime = 'image/jpeg';
                } elseif ($ext === 'gif') {
                    $mime = 'image/gif';
                } elseif ($ext === 'webp') {
                    $mime = 'image/webp';
                }
                $data = base64_encode((string)file_get_contents($abs));
                $imgTag = '<img class="informe-firma-img" src="data:' . $mime . ';base64,' . $data . '" alt="Firma" style="max-width:220px;max-height:90px;" />';
            }
        }
        $textoHtml = nl2br(htmlspecialchars($texto, ENT_QUOTES, 'UTF-8'));
        return "\n"
            . '<div class="informe-firma-sello" data-firma-usuario="' . (int)$usuarioId . '" style="margin-top:28px;page-break-inside:avoid;">'
            . ($imgTag !== '' ? '<div style="margin-bottom:6px;">' . $imgTag . '</div>' : '')
            . '<div class="informe-firma-sello-texto" style="font-size:12pt;line-height:1.35;">' . $textoHtml . '</div>'
            . '</div>';
    }

    function firma_html_ya_tiene_sello(string $html): bool
    {
        return strpos($html, 'informe-firma-sello') !== false
            || strpos($html, 'data-firma-usuario') !== false;
    }

    function firma_resolve_upload_path(string $relativeOrAbs): ?string
    {
        $p = str_replace('\\', '/', trim($relativeOrAbs));
        if ($p === '') {
            return null;
        }
        if ($p[0] === '/' && is_readable($p)) {
            return $p;
        }
        $root = realpath(__DIR__ . '/../..');
        if ($root === false) {
            $root = dirname(__DIR__, 2);
        }
        $candidate = $root . '/' . ltrim($p, '/');
        return is_readable($candidate) ? $candidate : null;
    }

    function firma_parse_posicion(?string $json): array
    {
        $default = ['mode' => 'final_informe', 'x' => 140, 'y' => 40, 'width' => 55];
        if ($json === null || trim($json) === '') {
            return $default;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return $default;
        }
        return array_merge($default, $decoded);
    }

    /**
     * Overlay de rúbrica+texto en la última página de un PDF existente.
     * Requiere TCPDF. Si FPDI no está disponible, genera página adicional con sello.
     *
     * @return string|null ruta relativa del PDF firmado, o null si falló
     */
    function firma_overlay_sello_en_pdf(PDO $db, string $pdfRelativePath, int $usuarioId, ?array $perfil = null): ?string
    {
        $perfil = $perfil ?: firma_get_perfil($db, $usuarioId);
        if (!$perfil || empty($perfil['imagen_path'])) {
            // Permitir solo texto si no hay imagen
            if (trim((string)($perfil['sello_texto'] ?? '')) === '' && firma_default_sello_texto($db, $usuarioId) === '') {
                return null;
            }
        }

        $srcAbs = firma_resolve_upload_path($pdfRelativePath);
        if (!$srcAbs) {
            error_log('[FIRMA_OVERLAY] PDF no legible: ' . $pdfRelativePath);
            return null;
        }

        if (!class_exists('TCPDF') && file_exists(__DIR__ . '/../../vendor/autoload.php')) {
            require_once __DIR__ . '/../../vendor/autoload.php';
        }
        if (!class_exists('TCPDF')) {
            error_log('[FIRMA_OVERLAY] TCPDF no disponible');
            return null;
        }

        $texto = trim((string)($perfil['sello_texto'] ?? ''));
        if ($texto === '') {
            $texto = firma_default_sello_texto($db, $usuarioId);
        }
        $pos = firma_parse_posicion($perfil['posicion_json'] ?? null);
        $imgAbs = null;
        if (!empty($perfil['imagen_path'])) {
            $imgAbs = firma_resolve_upload_path((string)$perfil['imagen_path']);
        }

        $root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
        $outDir = $root . '/uploads/pdf_informes';
        if (!is_dir($outDir)) {
            @mkdir($outDir, 0755, true);
        }
        $outName = 'firmado_' . $usuarioId . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.pdf';
        $outAbs = $outDir . '/' . $outName;

        // Página de sello (TCPDF) + merge con Ghostscript (FPDI no está instalado)
        $sealAbs = $outDir . '/seal_' . $usuarioId . '_' . time() . '.pdf';
        try {
            $sealPdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
            $sealPdf->setPrintHeader(false);
            $sealPdf->setPrintFooter(false);
            $sealPdf->SetMargins(20, 20, 20);
            $sealPdf->AddPage();
            $sealPdf->SetFont('helvetica', 'B', 11);
            $sealPdf->Cell(0, 8, 'Firma / sello del médico informante', 0, 1, 'L');
            $sealPdf->Ln(4);
            firma_tcpdf_draw_sello($sealPdf, $imgAbs, $texto, $pos, 210.0, 297.0);
            $sealPdf->Output($sealAbs, 'F');
        } catch (Throwable $e) {
            error_log('[FIRMA_OVERLAY] TCPDF seal: ' . $e->getMessage());
            return null;
        }

        $gs = trim((string)@shell_exec('command -v gs 2>/dev/null'));
        if ($gs === '') {
            @unlink($sealAbs);
            error_log('[FIRMA_OVERLAY] Ghostscript (gs) no disponible');
            return null;
        }
        $cmd = escapeshellcmd($gs)
            . ' -dBATCH -dNOPAUSE -dQUIET -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 '
            . '-sOutputFile=' . escapeshellarg($outAbs) . ' '
            . escapeshellarg($srcAbs) . ' ' . escapeshellarg($sealAbs) . ' 2>&1';
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        @unlink($sealAbs);
        if ($code !== 0 || !is_readable($outAbs)) {
            error_log('[FIRMA_OVERLAY] gs merge failed code=' . $code . ' ' . implode(' ', $out));
            return null;
        }

        $rel = 'uploads/pdf_informes/' . $outName;
        @chmod($outAbs, 0644);
        return $rel;
    }

    /**
     * @param TCPDF $pdf
     */
    function firma_tcpdf_draw_sello($pdf, ?string $imgAbs, string $texto, array $pos, float $pageW, float $pageH): void
    {
        $widthMm = isset($pos['width']) ? (float)$pos['width'] : 55.0;
        $x = isset($pos['x']) ? (float)$pos['x'] : max(20.0, $pageW - $widthMm - 20.0);
        $y = isset($pos['y']) ? (float)$pos['y'] : max(20.0, $pageH - 45.0);
        if (($pos['mode'] ?? '') === 'final_informe' || !isset($pos['x'])) {
            $x = max(20.0, $pageW - $widthMm - 25.0);
            $y = max(20.0, $pageH - 50.0);
        }
        if ($imgAbs && is_readable($imgAbs)) {
            $pdf->Image($imgAbs, $x, $y, $widthMm, 0, '', '', '', false, 300);
            $y += 22;
        }
        if ($texto !== '') {
            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetXY($x, $y);
            $pdf->MultiCell($widthMm + 10, 4, $texto, 0, 'L', false, 1);
        }
    }

    function firma_allowed_estados(): array
    {
        return ['borrador', 'transcripto', 'revisado', 'firmado', 'finalizado'];
    }
}
