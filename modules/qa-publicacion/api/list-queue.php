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

$user = requireQaManagerAccess();

$db = getDBConnection();
if (!QaPublicationService::isInstalled($db)) {
    qaJsonError('El módulo QA no está instalado', 503);
}

$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
if ($dateFrom === '' || $dateTo === '') {
    $dateTo = date('Y-m-d');
    $dateFrom = date('Y-m-d', strtotime('-7 days'));
}

$items = QaPublicationService::buildQueueList($db, [
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'q' => trim((string) ($_GET['q'] ?? '')),
    'estado' => trim((string) ($_GET['estado'] ?? '')),
    'tiene_informe' => trim((string) ($_GET['tiene_informe'] ?? '')),
    'check_mixed' => ($_GET['check_mixed'] ?? '0') === '1',
    'limit' => (int) ($_GET['limit'] ?? 200),
]);

$cfg = QaPublicationService::getConfig($db);

qaJsonSuccess([
    'config' => $cfg,
    'enabled' => QaPublicationService::isEnabled($db),
    'can_manage_config' => qaUserCanManageConfig($user),
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'items' => $items,
    'count' => count($items),
]);
