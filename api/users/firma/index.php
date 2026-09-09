<?php
/**
 * GET/POST perfil de firma (rúbrica + sello) del usuario en sesión.
 * POST JSON: sello_texto, posicion_json
 * POST multipart: imagen (file) + opcional sello_texto
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../../classes/User.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../informes/informe_firma_helper.php';

function firma_auth_user(): array
{
    $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? $_GET['session_token'] ?? $_POST['session_token'] ?? null;
    if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
        $sessionToken = substr($sessionToken, 7);
    }
    if (!$sessionToken && !empty($_COOKIE['session_token'])) {
        $sessionToken = $_COOKIE['session_token'];
    }
    if (!$sessionToken) {
        $raw = file_get_contents('php://input');
        $j = json_decode($raw ?: '[]', true);
        $sessionToken = $j['session_token'] ?? null;
        $GLOBALS['_firma_json_input'] = is_array($j) ? $j : [];
    }
    if (!$sessionToken) {
        throw new Exception('Token de sesión requerido');
    }
    $user = new User();
    $userData = $user->validateSession($sessionToken);
    if (!$userData) {
        http_response_code(401);
        throw new Exception('Sesión inválida');
    }
    $permisos = json_decode($userData['permisos'] ?? '[]', true) ?: [];
    $ok = in_array('all', $permisos, true)
        || in_array('gestionarFirmaPropia', $permisos, true)
        || in_array('firmarInformes', $permisos, true)
        || in_array('gestionInformes', $permisos, true);
    if (!$ok) {
        http_response_code(403);
        throw new Exception('Sin permiso para gestionar firma');
    }
    return $userData;
}

try {
    $userData = firma_auth_user();
    $db = getDBConnection();
    $uid = (int)$userData['id'];
    $root = realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
    $dir = $root . '/uploads/firmas';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $perfil = firma_get_perfil($db, $uid);
        if (!$perfil) {
            $perfil = [
                'usuario_id' => $uid,
                'imagen_path' => null,
                'sello_texto' => firma_default_sello_texto($db, $uid),
                'posicion_json' => json_encode(['mode' => 'final_informe']),
                'updated_at' => null,
            ];
        } elseif (trim((string)($perfil['sello_texto'] ?? '')) === '') {
            $perfil['sello_texto'] = firma_default_sello_texto($db, $uid);
        }
        echo json_encode(['success' => true, 'data' => $perfil], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if (!is_writable($dir)) {
        throw new Exception('El servidor no puede guardar la firma (permisos de uploads/firmas)');
    }

    // POST
    $json = $GLOBALS['_firma_json_input'] ?? null;
    if (!is_array($json)) {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw ?: '[]', true) ?: [];
    }

    $sello = null;
    if (isset($_POST['sello_texto'])) {
        $sello = (string)$_POST['sello_texto'];
    } elseif (isset($json['sello_texto'])) {
        $sello = (string)$json['sello_texto'];
    }

    $posicion = null;
    if (isset($_POST['posicion_json'])) {
        $posicion = (string)$_POST['posicion_json'];
    } elseif (isset($json['posicion_json'])) {
        $posicion = is_array($json['posicion_json'])
            ? json_encode($json['posicion_json'])
            : (string)$json['posicion_json'];
    }

    $imagenPath = null;
    // base64 desde móvil / canvas
    $b64 = $_POST['imagen_base64'] ?? $json['imagen_base64'] ?? null;
    if (is_string($b64) && $b64 !== '') {
        if (preg_match('#^data:image/(png|jpeg|jpg|webp);base64,#i', $b64, $m)) {
            $b64 = preg_replace('#^data:image/\w+;base64,#i', '', $b64);
            $ext = strtolower($m[1]) === 'jpeg' || strtolower($m[1]) === 'jpg' ? 'jpg' : strtolower($m[1]);
        } else {
            $ext = 'png';
        }
        $bin = base64_decode($b64, true);
        if ($bin === false || strlen($bin) < 32) {
            throw new Exception('Imagen base64 inválida');
        }
        if (strlen($bin) > 2 * 1024 * 1024) {
            throw new Exception('Imagen demasiado grande (máx 2MB)');
        }
        $fname = 'firma_' . $uid . '_' . time() . '.' . $ext;
        $abs = $dir . '/' . $fname;
        if (file_put_contents($abs, $bin) === false) {
            throw new Exception('No se pudo guardar la imagen');
        }
        @chmod($abs, 0644);
        $imagenPath = 'uploads/firmas/' . $fname;
    } elseif (!empty($_FILES['imagen']['tmp_name'])) {
        $f = $_FILES['imagen'];
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new Exception('Error al subir imagen');
        }
        if (($f['size'] ?? 0) > 2 * 1024 * 1024) {
            throw new Exception('Imagen demasiado grande (máx 2MB)');
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $f['tmp_name']);
        finfo_close($finfo);
        $map = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
        if (!isset($map[$mime])) {
            throw new Exception('Formato no permitido (PNG/JPEG/WebP)');
        }
        $fname = 'firma_' . $uid . '_' . time() . '.' . $map[$mime];
        $abs = $dir . '/' . $fname;
        if (!move_uploaded_file($f['tmp_name'], $abs)) {
            throw new Exception('No se pudo guardar la imagen');
        }
        @chmod($abs, 0644);
        $imagenPath = 'uploads/firmas/' . $fname;
    }

    $existing = firma_get_perfil($db, $uid);
    $finalSello = $sello !== null ? $sello : ($existing['sello_texto'] ?? firma_default_sello_texto($db, $uid));
    $finalPos = $posicion !== null ? $posicion : ($existing['posicion_json'] ?? json_encode(['mode' => 'final_informe']));
    $finalImg = $imagenPath !== null ? $imagenPath : ($existing['imagen_path'] ?? null);

    $stmt = $db->prepare('INSERT INTO usuarios_firmas (usuario_id, imagen_path, sello_texto, posicion_json)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          imagen_path = VALUES(imagen_path),
          sello_texto = VALUES(sello_texto),
          posicion_json = VALUES(posicion_json)');
    $stmt->execute([$uid, $finalImg, $finalSello, $finalPos]);

    echo json_encode([
        'success' => true,
        'message' => 'Firma guardada',
        'data' => firma_get_perfil($db, $uid),
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    $code = http_response_code();
    if ($code < 400) {
        http_response_code(400);
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
