<?php
/**
 * Reintenta enviar PDF+TXT de un informe recibido hacia un endpoint externo.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/_common.php';
auditManagerRequireAuditor();

if (!function_exists('curl_init')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'cURL no disponible en servidor'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new Exception('JSON inválido');
    }

    $attemptId = (int)($input['attempt_id'] ?? 0);
    $targetUrl = trim((string)($input['target_url'] ?? ''));
    if ($attemptId <= 0) {
        throw new Exception('attempt_id requerido');
    }
    if ($targetUrl === '' || !preg_match('/^https?:\/\//i', $targetUrl)) {
        throw new Exception('target_url inválida (debe iniciar con http/https)');
    }

    $db = getDBConnection();

    $tableCheck = $db->query("SHOW TABLES LIKE 'informes_recibidos_intentos'");
    if ($tableCheck->rowCount() === 0) {
        throw new Exception('Tabla informes_recibidos_intentos no existe');
    }

    $stmt = $db->prepare("
        SELECT
            a.id,
            a.request_id,
            a.informe_recibido_id,
            ir.pdf_path,
            ir.txt_path
        FROM informes_recibidos_intentos a
        LEFT JOIN informes_recibidos ir ON ir.id = a.informe_recibido_id
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmt->execute([$attemptId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Intento no encontrado');
    }
    if (empty($row['informe_recibido_id'])) {
        throw new Exception('El intento no está asociado a un informe recibido guardado');
    }

    $baseDir = realpath(__DIR__ . '/../../../');
    $pdfAbs = $baseDir . '/' . ltrim((string)($row['pdf_path'] ?? ''), '/');
    $txtAbs = $baseDir . '/' . ltrim((string)($row['txt_path'] ?? ''), '/');
    if (!is_file($pdfAbs) || !is_file($txtAbs)) {
        throw new Exception('No se encontraron archivos PDF/TXT en servidor para reintento');
    }

    $postFields = [
        'pdf' => new CURLFile($pdfAbs, 'application/pdf', basename($pdfAbs)),
        'txt' => new CURLFile($txtAbs, 'text/plain', basename($txtAbs)),
    ];

    $ch = curl_init($targetUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $raw = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new Exception('Error cURL: ' . ($curlErr ?: 'desconocido'));
    }

    $decoded = json_decode($raw, true);

    echo json_encode([
        'success' => true,
        'attempt_id' => $attemptId,
        'request_id' => $row['request_id'] ?? null,
        'target_url' => $targetUrl,
        'http_status' => $httpCode,
        'response_json' => is_array($decoded) ? $decoded : null,
        'response_raw' => is_array($decoded) ? null : $raw,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

