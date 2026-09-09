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
require_once __DIR__ . '/../QaPublicationService.php';

$user = requireQaAuth('qa_revisar');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    qaJsonError('Método no permitido', 405);
}

$input = qaReadJsonInput();
$orthancId = trim((string) ($input['orthanc_id'] ?? ''));
if ($orthancId === '') {
    qaJsonError('orthanc_id es requerido', 400);
}

$result = QaPublicationService::detectMixedSeries($orthancId);
qaJsonSuccess(['detection' => $result]);
