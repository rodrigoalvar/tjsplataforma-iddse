<?php
/**
 * Desvincula un informe recibido por API del estudio/informe en informes-manager.
 * Restaura el recibido a estado "recibido" y limpia el informe asociado si corresponde al PDF recibido / título API.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit();
}

require_once '../../../classes/User.php';
require_once '../../../config/database.php';

function resolveSessionUser(User $user): ?array
{
    $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
        $sessionToken = substr($sessionToken, 7);
    }
    if (!$sessionToken && !empty($_COOKIE['session_token'])) {
        $sessionToken = $_COOKIE['session_token'];
    }
    if (!$sessionToken) {
        return null;
    }

    return $user->validateSession($sessionToken) ?: null;
}

/**
 * Comparación estable de rutas guardadas en BD / HTML (slashes, mayúsculas, prefijos).
 */
function normalizeInformesStoragePath(?string $path): string
{
    $s = str_replace('\\', '/', trim((string)($path ?? '')));
    if ($s === '') {
        return '';
    }
    if (preg_match('#^https?://[^/]+/(.+)$#i', $s, $m)) {
        $s = $m[1];
    }
    $s = strtolower($s);
    $s = ltrim($s, '/');

    return $s;
}

function informesPdfPathsEquivalent(?string $a, ?string $b): bool
{
    $na = normalizeInformesStoragePath($a);
    $nb = normalizeInformesStoragePath($b);

    return $na !== '' && $nb !== '' && $na === $nb;
}

/**
 * Quita bloques .informe-adjunto cuyo botón apunta al mismo PDF que el recibido (HTML de vincular/attach).
 */
function removeAdjuntoDivsMatchingRecibidoPdf(string $html, string $recibidoPdf): string
{
    $target = normalizeInformesStoragePath($recibidoPdf);
    if ($target === '' || strpos($html, 'informe-adjunto') === false) {
        return $html;
    }
    $out = preg_replace_callback(
        '#<div\s[^>]*\binforme-adjunto\b[^>]*>.*?</div>#is',
        static function (array $m) use ($recibidoPdf) {
            $block = $m[0];
            if (!preg_match('/data-pdf-path\s*=\s*"([^"]*)"/i', $block, $pm)) {
                return $block;
            }
            if (informesPdfPathsEquivalent($pm[1], $recibidoPdf)) {
                return '';
            }

            return $block;
        },
        $html
    );

    return is_string($out) ? $out : $html;
}

/**
 * Mismo criterio que send-to-pacs: solo el bloque de adjunto sin redacción larga.
 */
function informeHtmlEsSoloBloqueAdjuntoPdf(string $html): bool
{
    $h = trim($html);
    if ($h === '') {
        return true;
    }
    if (strpos($h, 'informe-adjunto') === false || strpos($h, 'pdf-viewer-btn') === false) {
        return false;
    }
    if (strlen($h) > 8000) {
        return false;
    }

    return true;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new Exception('JSON inválido');
    }

    $recibidoId = (int)($input['recibido_id'] ?? 0);
    if ($recibidoId <= 0) {
        throw new Exception('Parámetro requerido: recibido_id');
    }

    $db = getDBConnection();
    $user = new User();
    $sessionUser = resolveSessionUser($user);
    if (!$sessionUser || empty($sessionUser['id'])) {
        throw new Exception('Sesión inválida o expirada');
    }
    $userId = (int)$sessionUser['id'];

    $columnsStmt = $db->query('SHOW COLUMNS FROM informes_recibidos');
    $irColumns = $columnsStmt ? $columnsStmt->fetchAll(PDO::FETCH_COLUMN) : [];
    $hasInformeIdCol = in_array('informe_id', $irColumns, true);
    $hasMetodoVinculacion = in_array('metodo_vinculacion', $irColumns, true);
    $hasMatchingScore = in_array('matching_score', $irColumns, true);
    $hasMatchingReasons = in_array('matching_reasons', $irColumns, true);
    $hasAutoPacsEstado = in_array('auto_pacs_estado', $irColumns, true);

    $selectSql = 'SELECT id, estado, estudio_id, pdf_path'
        . ($hasInformeIdCol ? ', informe_id' : ', NULL AS informe_id')
        . ' FROM informes_recibidos WHERE id = ? LIMIT 1';
    $rowStmt = $db->prepare($selectSql);
    $rowStmt->execute([$recibidoId]);
    $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Informe recibido no encontrado');
    }
    if ((string)($row['estado'] ?? '') === 'descartado') {
        throw new Exception('No se puede desvincular un informe descartado');
    }
    $estudioId = (int)($row['estudio_id'] ?? 0);
    $estado = (string)($row['estado'] ?? '');
    if ($estudioId <= 0 && $estado !== 'vinculado') {
        throw new Exception('El informe no está vinculado a un estudio');
    }

    $informeId = (int)($row['informe_id'] ?? 0);
    $recibidoPdf = trim((string)($row['pdf_path'] ?? ''));

    if ($informeId > 0) {
        try {
            $pacsColStmt = $db->query("
                SELECT COLUMN_NAME
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'informes'
                  AND COLUMN_NAME IN ('pacs_series_id', 'pacs_instance_id', 'pacs_study_id', 'fecha_enviado_pacs')
            ");
            $pacsCols = $pacsColStmt ? $pacsColStmt->fetchAll(PDO::FETCH_COLUMN) : [];
        } catch (Exception $e) {
            $pacsCols = [];
        }
        if (!empty($pacsCols)) {
            $sel = array_map(static function ($c) {
                return 'i.' . $c;
            }, $pacsCols);
            $infPacsStmt = $db->prepare('SELECT ' . implode(', ', $sel) . ' FROM informes i WHERE i.id = ? LIMIT 1');
            $infPacsStmt->execute([$informeId]);
            $pacsRow = $infPacsStmt->fetch(PDO::FETCH_ASSOC);
            if ($pacsRow) {
                foreach ($pacsCols as $field) {
                    if (!empty($pacsRow[$field])) {
                        throw new Exception(
                            'No se puede desvincular: el informe asociado está enviado a PACS. ' .
                            'Elimínelo primero de PACS desde el gestor de informes.'
                        );
                    }
                }
            }
        }
    }

    $setParts = [
        "estado = 'recibido'",
        'estudio_id = NULL',
        'fecha_vinculacion = NULL',
    ];
    if ($hasMetodoVinculacion) {
        $setParts[] = 'metodo_vinculacion = NULL';
    }
    if ($hasInformeIdCol) {
        $setParts[] = 'informe_id = NULL';
    }
    if ($hasAutoPacsEstado) {
        $setParts[] = 'auto_pacs_estado = NULL';
        $setParts[] = 'auto_pacs_error = NULL';
        $setParts[] = 'auto_pacs_last_try_at = NULL';
        $setParts[] = 'auto_pacs_try_count = 0';
    }
    $updateRecSql = 'UPDATE informes_recibidos SET ' . implode(', ', $setParts) . ' WHERE id = ?';

    $db->beginTransaction();

    try {
        $upd = $db->prepare($updateRecSql);
        $upd->execute([$recibidoId]);

        if ($hasMatchingScore || $hasMatchingReasons) {
            $matchSets = [];
            if ($hasMatchingScore) {
                $matchSets[] = 'matching_score = NULL';
            }
            if ($hasMatchingReasons) {
                $matchSets[] = 'matching_reasons = NULL';
            }
            if ($matchSets) {
                $clrSql = 'UPDATE informes_recibidos SET ' . implode(', ', $matchSets) . ' WHERE id = ?';
                $clr = $db->prepare($clrSql);
                $clr->execute([$recibidoId]);
            }
        }

        if ($informeId > 0) {
            $infStmt = $db->prepare('SELECT id, pdf_path, titulo, contenido_html, contenido_texto, estado FROM informes WHERE id = ? LIMIT 1');
            $infStmt->execute([$informeId]);
            $inf = $infStmt->fetch(PDO::FETCH_ASSOC);
            if ($inf) {
                $titulo = (string)($inf['titulo'] ?? '');
                $infPdf = trim((string)($inf['pdf_path'] ?? ''));
                $contenidoHtml = (string)($inf['contenido_html'] ?? '');
                $isExternoApi = (strpos($titulo, 'Informe externo API') === 0);
                $pdfCoincideRecibido = informesPdfPathsEquivalent($infPdf, $recibidoPdf);
                $htmlSinAdjuntoRecibido = removeAdjuntoDivsMatchingRecibidoPdf($contenidoHtml, $recibidoPdf);
                $htmlCambio = ($htmlSinAdjuntoRecibido !== $contenidoHtml);
                $soloAdjunto = informeHtmlEsSoloBloqueAdjuntoPdf($contenidoHtml);

                $limpiezaTotal = $isExternoApi
                    || ($pdfCoincideRecibido && $soloAdjunto)
                    || ($pdfCoincideRecibido && trim($contenidoHtml) === '');

                if ($limpiezaTotal) {
                    $cleanStmt = $db->prepare("
                        UPDATE informes
                        SET pdf_path = NULL,
                            contenido_html = '',
                            contenido_texto = '',
                            estado = 'borrador',
                            fecha_finalizacion = NULL,
                            fecha_modificacion = NOW()
                        WHERE id = ?
                    ");
                    $cleanStmt->execute([$informeId]);
                } elseif ($pdfCoincideRecibido || $htmlCambio) {
                    $sets = ['fecha_modificacion = NOW()'];
                    $params = [];
                    if ($pdfCoincideRecibido) {
                        $sets[] = 'pdf_path = NULL';
                    }
                    if ($htmlCambio) {
                        $sets[] = 'contenido_html = ?';
                        $params[] = $htmlSinAdjuntoRecibido;
                    }
                    $params[] = $informeId;
                    $sqlUp = 'UPDATE informes SET ' . implode(', ', $sets) . ' WHERE id = ?';
                    $partial = $db->prepare($sqlUp);
                    $partial->execute($params);
                }
            }
        }

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Informe recibido desvinculado correctamente',
        'data' => [
            'recibido_id' => $recibidoId,
            'estado' => 'recibido',
            'desvinculado_por_usuario_id' => $userId,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
