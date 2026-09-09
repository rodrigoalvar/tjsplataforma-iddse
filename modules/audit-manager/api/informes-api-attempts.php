<?php
/**
 * Lista intentos de recepción de informes API (éxitos/errores).
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/_common.php';
auditManagerRequireAuditor();

try {
    $db = getDBConnection();

    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    $estado = trim((string)($_GET['estado'] ?? ''));
    $from = trim((string)($_GET['from'] ?? ''));
    $to = trim((string)($_GET['to'] ?? ''));
    $q = trim((string)($_GET['q'] ?? ''));
    if (strlen($q) > 120) {
        $q = substr($q, 0, 120);
    }

    $tableExistsStmt = $db->query("SHOW TABLES LIKE 'informes_recibidos_intentos'");
    if ($tableExistsStmt->rowCount() === 0) {
        echo json_encode([
            'success' => true,
            'configured' => false,
            'attempts' => [],
            'total' => 0,
            'page' => $page,
            'limit' => $limit,
            'message' => 'Tabla informes_recibidos_intentos no existe',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $where = ['1=1'];
    $params = [];

    if ($estado !== '' && in_array($estado, ['iniciado', 'exitoso', 'error'], true)) {
        $where[] = 'a.estado = ?';
        $params[] = $estado;
    }
    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $from)) {
        $where[] = 'a.created_at >= ?';
        $params[] = $from . ' 00:00:00';
    }
    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $to)) {
        $where[] = 'a.created_at <= ?';
        $params[] = $to . ' 23:59:59';
    }
    if ($q !== '') {
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $where[] = '(a.request_id LIKE ? OR a.accession_number LIKE ? OR a.remote_ip LIKE ? OR a.error_message LIKE ?)';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $whereSql = implode(' AND ', $where);

    $countStmt = $db->prepare("SELECT COUNT(*) FROM informes_recibidos_intentos a WHERE $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sql = "SELECT
                a.id,
                a.request_id,
                a.estado,
                a.error_message,
                a.accession_number,
                a.informe_recibido_id,
                a.remote_ip,
                a.pdf_filename,
                a.txt_filename,
                a.created_at,
                ir.pdf_path AS linked_pdf_path,
                ir.txt_path AS linked_txt_path
            FROM informes_recibidos_intentos a
            LEFT JOIN informes_recibidos ir ON ir.id = a.informe_recibido_id
            WHERE $whereSql
            ORDER BY a.id DESC
            LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $attempts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $baseDir = realpath(__DIR__ . '/../../../');
    foreach ($attempts as &$a) {
        $pdfPath = (string)($a['linked_pdf_path'] ?? '');
        $txtPath = (string)($a['linked_txt_path'] ?? '');
        $pdfAbs = $pdfPath !== '' ? $baseDir . '/' . ltrim($pdfPath, '/') : '';
        $txtAbs = $txtPath !== '' ? $baseDir . '/' . ltrim($txtPath, '/') : '';
        $a['retry_available'] = ($pdfAbs !== '' && $txtAbs !== '' && is_file($pdfAbs) && is_file($txtAbs));
    }
    unset($a);

    echo json_encode([
        'success' => true,
        'configured' => true,
        'attempts' => $attempts,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

