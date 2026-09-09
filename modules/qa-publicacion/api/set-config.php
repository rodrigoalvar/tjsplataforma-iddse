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

$user = requireQaAuth('qa_config');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    qaJsonError('Método no permitido', 405);
}

$input = qaReadJsonInput();
$db = getDBConnection();
if (!QaPublicationService::isInstalled($db)) {
    qaJsonError('El módulo QA no está instalado', 503);
}

$allowed = [
    'qa_enabled' => ['0', '1'],
    'qa_mode' => [
        QaPublicationService::MODE_LISTA_NEGRA,
        QaPublicationService::MODE_LISTA_BLANCA,
        QaPublicationService::MODE_HIBRIDO,
    ],
    'qa_hybrid_require_informe' => ['0', '1'],
    'qa_hybrid_block_mixed' => ['0', '1'],
];

foreach ($allowed as $key => $values) {
    if (!array_key_exists($key, $input)) {
        continue;
    }
    $val = trim((string) $input[$key]);
    if (!in_array($val, $values, true)) {
        qaJsonError("Valor inválido para {$key}", 400);
    }
    QaPublicationService::setConfigValue($db, $key, $val);
}

QaPublicationService::logAction($db, [
    'accion' => 'config',
    'target_type' => 'estudio',
    'usuario_id' => (int) $user['id'],
    'motivo' => 'actualizacion_config',
    'resultado' => 'ok',
    'detalle' => $input,
]);

qaJsonSuccess(['config' => QaPublicationService::getConfig($db)]);
