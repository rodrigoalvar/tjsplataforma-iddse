<?php
/**
 * Subida de rúbrica desde sesión móvil QR (sin cookie de usuario web).
 * Autoriza por mobile_sessions.session_type=firma + created_by.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../informes/informe_firma_helper.php';

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $sessionId = trim((string)($input['session_id'] ?? ''));
    $b64 = (string)($input['imagen_base64'] ?? '');
    if ($sessionId === '' || $b64 === '') {
        throw new Exception('session_id e imagen_base64 requeridos');
    }

    $db = getDBConnection();
    $stmt = $db->prepare("SELECT * FROM mobile_sessions WHERE session_id = ? AND expires_at > NOW() AND status IN ('active','pending','connected','idle') LIMIT 1");
    // status may vary — fallback without status filter
    try {
        $stmt->execute([$sessionId]);
        $sess = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $sess = null;
    }
    if (!$sess) {
        $stmt2 = $db->prepare("SELECT * FROM mobile_sessions WHERE session_id = ? AND expires_at > NOW() LIMIT 1");
        $stmt2->execute([$sessionId]);
        $sess = $stmt2->fetch(PDO::FETCH_ASSOC);
    }
    if (!$sess) {
        throw new Exception('Sesión móvil inválida o expirada');
    }
    if (($sess['session_type'] ?? '') !== 'firma') {
        throw new Exception('Sesión no es de captura de firma');
    }
    $uid = (int)($sess['created_by'] ?? 0);
    if ($uid <= 0) {
        throw new Exception('Sesión sin usuario asociado');
    }

    if (preg_match('#^data:image/(png|jpeg|jpg|webp);base64,#i', $b64, $m)) {
        $b64raw = preg_replace('#^data:image/\w+;base64,#i', '', $b64);
        $ext = strtolower($m[1]);
        if ($ext === 'jpeg') $ext = 'jpg';
    } else {
        $b64raw = $b64;
        $ext = 'png';
    }
    $bin = base64_decode($b64raw, true);
    if ($bin === false || strlen($bin) < 32) {
        throw new Exception('Imagen inválida');
    }
    if (strlen($bin) > 2 * 1024 * 1024) {
        throw new Exception('Imagen demasiado grande');
    }

    $root = realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
    $dir = $root . '/uploads/firmas';
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new Exception('No se pudo crear el directorio de firmas');
        }
        @chmod($dir, 0775);
    }
    if (!is_writable($dir)) {
        error_log('[FIRMA_MOBILE] Directorio no escribible: ' . $dir);
        throw new Exception('El servidor no puede guardar la firma (permisos de uploads/firmas). Contacte a soporte.');
    }
    $fname = 'firma_' . $uid . '_' . time() . '.' . $ext;
    $abs = $dir . '/' . $fname;
    if (file_put_contents($abs, $bin) === false) {
        error_log('[FIRMA_MOBILE] file_put_contents falló: ' . $abs);
        throw new Exception('No se pudo guardar el archivo de firma');
    }
    @chmod($abs, 0644);
    $rel = 'uploads/firmas/' . $fname;

    $texto = firma_default_sello_texto($db, $uid);
    $existing = firma_get_perfil($db, $uid);
    $finalTexto = trim((string)($existing['sello_texto'] ?? '')) !== '' ? $existing['sello_texto'] : $texto;
    $pos = $existing['posicion_json'] ?? json_encode(['mode' => 'final_informe']);

    $up = $db->prepare('INSERT INTO usuarios_firmas (usuario_id, imagen_path, sello_texto, posicion_json)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE imagen_path = VALUES(imagen_path),
          sello_texto = COALESCE(NULLIF(sello_texto,\'\'), VALUES(sello_texto))');
    $up->execute([$uid, $rel, $finalTexto, $pos]);

    echo json_encode(['success' => true, 'message' => 'Firma guardada', 'data' => firma_get_perfil($db, $uid)]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
