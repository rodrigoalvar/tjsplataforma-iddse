<?php
declare(strict_types=1);

ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../QaPublicationService.php';

requireQaAuth('qa_ver_registro');

$db = getDBConnection();
if (!QaPublicationService::isInstalled($db)) {
    qaJsonError('El módulo QA no está instalado', 503);
}

$limit = min(200, max(1, (int) ($_GET['limit'] ?? 50)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));

$stmt = $db->prepare(
    'SELECT l.*, u.nombre as usuario_nombre, u.apellido as usuario_apellido
     FROM qa_action_log l
     LEFT JOIN usuarios u ON u.id = l.usuario_id
     ORDER BY l.created_at DESC
     LIMIT ? OFFSET ?'
);
$stmt->bindValue(1, $limit, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$rows = QaPublicationService::enrichLogItems($db, $rows);

qaJsonSuccess(['items' => $rows, 'limit' => $limit, 'offset' => $offset]);
