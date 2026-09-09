<?php
declare(strict_types=1);

ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../QaPublicationService.php';

$user = requireQaRevisarOrQuick();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    qaJsonError('Método no permitido', 405);
}

$input = qaReadJsonInput();
$db = getDBConnection();
if (!QaPublicationService::isInstalled($db)) {
    qaJsonSuccess(['installed' => false, 'studies' => [], 'informes' => []]);
}

$studyUids = array_values(array_filter(array_map('strval', $input['study_instance_uids'] ?? [])));
$informeIds = array_values(array_filter(array_map('intval', $input['informe_ids'] ?? [])));

qaJsonSuccess([
    'installed' => true,
    'enabled' => QaPublicationService::isEnabled($db),
    'config' => QaPublicationService::getConfig($db),
    'studies' => QaPublicationService::loadStudyStatuses($db, $studyUids),
    'informes' => QaPublicationService::loadInformeStatuses($db, $informeIds),
]);
