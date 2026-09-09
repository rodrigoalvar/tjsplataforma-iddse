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

qaJsonSuccess([
    'config' => QaPublicationService::getConfig($db),
    'can_manage_config' => qaUserCanManageConfig($user),
    'can_view_log' => qaUserCanViewLog($user),
]);
