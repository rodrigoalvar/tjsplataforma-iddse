<?php
/**
 * Firmar informe médico (estado transcripto → firmado).
 * Plataforma: inserta sello en HTML. Externo: merge sello en PDF.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/informe_medico_responsable_helper.php';
require_once __DIR__ . '/informe_firma_helper.php';
require_once __DIR__ . '/recibidos/informe_recibido_version_helper.php';

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? $input['session_token'] ?? null;
    if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
        $sessionToken = substr($sessionToken, 7);
    }
    if (!$sessionToken && !empty($_COOKIE['session_token'])) {
        $sessionToken = $_COOKIE['session_token'];
    }
    if (!$sessionToken) {
        throw new Exception('Token de sesión requerido');
    }

    $user = new User();
    $userData = $user->validateSession($sessionToken);
    if (!$userData) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sesión inválida']);
        exit();
    }

    $permisos = json_decode($userData['permisos'] ?? '[]', true) ?: [];
    $canSign = in_array('all', $permisos, true) || in_array('firmarInformes', $permisos, true);
    if (!$canSign) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'No tiene permiso para firmar informes']);
        exit();
    }

    $informeId = (int)($input['id'] ?? $input['informe_id'] ?? 0);
    if ($informeId <= 0) {
        throw new Exception('ID de informe requerido');
    }

    $db = getDBConnection();
    $stmt = $db->prepare('SELECT * FROM informes WHERE id = ? LIMIT 1');
    $stmt->execute([$informeId]);
    $informe = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$informe) {
        throw new Exception('Informe no encontrado');
    }

    $estado = strtolower(trim((string)($informe['estado'] ?? '')));
    if ($estado !== 'transcripto') {
        throw new Exception('Solo se pueden firmar informes en estado Transcripto. Estado actual: ' . ($informe['estado'] ?? ''));
    }

    $userId = (int)$userData['id'];
    if (!ir_usuario_puede_firmar_informe($db, $informe, $userId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'No es el médico responsable de este informe']);
        exit();
    }

    $perfil = firma_get_perfil($db, $userId);
    if (!$perfil || (empty($perfil['imagen_path']) && trim((string)($perfil['sello_texto'] ?? '')) === '')) {
        // Crear perfil mínimo con sello de texto
        $texto = firma_default_sello_texto($db, $userId);
        if ($texto === '') {
            throw new Exception('Configure su rúbrica/sello antes de firmar (Gestión Informes → Mi firma)');
        }
        $ins = $db->prepare('INSERT INTO usuarios_firmas (usuario_id, sello_texto, posicion_json) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE sello_texto = COALESCE(NULLIF(sello_texto,\'\'), VALUES(sello_texto))');
        $ins->execute([$userId, $texto, json_encode(['mode' => 'final_informe'])]);
        $perfil = firma_get_perfil($db, $userId);
    }
    if (empty($perfil['imagen_path']) && trim((string)($perfil['sello_texto'] ?? '')) === '') {
        throw new Exception('Configure su rúbrica/sello antes de firmar');
    }

    $esExterno = ir_informe_es_externo($informe);
    $contenidoHtml = (string)($informe['contenido_html'] ?? '');
    $pdfPath = $informe['pdf_path'] ?? null;
    $version = (int)($informe['version'] ?? 1);
    $hayCambioHtml = false;

    if ($esExterno) {
        $pdfSrc = trim((string)($informe['pdf_path'] ?? ''));
        if ($pdfSrc === '') {
            throw new Exception('El informe externo no tiene PDF para sellar');
        }
        $newPdf = firma_overlay_sello_en_pdf($db, $pdfSrc, $userId, $perfil);
        if (!$newPdf) {
            throw new Exception('No se pudo generar el PDF firmado');
        }
        $pdfPath = $newPdf;
        // Actualizar data-pdf-path en el wrapper HTML si existe
        if ($contenidoHtml !== '' && strpos($contenidoHtml, 'data-pdf-path') !== false) {
            $contenidoHtml = preg_replace(
                '/data-pdf-path="[^"]*"/',
                'data-pdf-path="' . htmlspecialchars($newPdf, ENT_QUOTES, 'UTF-8') . '"',
                $contenidoHtml
            );
            $hayCambioHtml = true;
        }
    } else {
        if (!firma_html_ya_tiene_sello($contenidoHtml)) {
            $contenidoHtml .= firma_build_sello_html($db, $userId, $perfil);
            $hayCambioHtml = true;
        }
    }

    if ($hayCambioHtml) {
        try {
            irvh_insertarHistorialPdfRecibido($db, $informe, $userId, 'Firma médica del informe');
        } catch (Throwable $e) {
            error_log('[SIGN] historial: ' . $e->getMessage());
        }
        $version++;
    }

    $contenidoTexto = strip_tags($contenidoHtml);
    $contenidoTexto = html_entity_decode($contenidoTexto, ENT_QUOTES, 'UTF-8');
    $contenidoTexto = preg_replace('/\s+/', ' ', trim($contenidoTexto));

    $upd = $db->prepare("UPDATE informes SET
        contenido_html = ?,
        contenido_texto = ?,
        pdf_path = ?,
        version = ?,
        estado = 'firmado',
        origen = COALESCE(origen, ?),
        firmado_por = ?,
        firmado_en = NOW(),
        fecha_modificacion = NOW()
        WHERE id = ?");
    $upd->execute([
        $contenidoHtml,
        $contenidoTexto,
        $pdfPath,
        $version,
        $esExterno ? 'externo' : 'plataforma',
        $userId,
        $informeId,
    ]);

    $get = $db->prepare("SELECT i.*, u.nombre AS usuario_nombre, u.apellido AS usuario_apellido
                         FROM informes i LEFT JOIN usuarios u ON u.id = i.usuario_id WHERE i.id = ?");
    $get->execute([$informeId]);
    $saved = $get->fetch(PDO::FETCH_ASSOC);

    try {
        require_once __DIR__ . '/../estudios/sla_helper.php';
        if ($saved) {
            sla_mark_informe_estado_event($db, $saved, 'firmado');
        }
    } catch (Throwable $slaEx) {
        error_log('[SIGN] sla firmado: ' . $slaEx->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'Informe firmado correctamente',
        'data' => $saved,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
