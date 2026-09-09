<?php
/**
 * Webhook Orthanc OnStableStudy → marca llegada local (ancla SLA).
 *
 * POST JSON: orthanc_study_id (requerido), study_instance_uid, modality, patient_id, patient_name
 * Auth: Authorization: Bearer <sla_webhook_secret> o X-SLA-Token
 *
 * Nota: marca timestamp aunque sla_activo=0 (para no perder anclas); la UI/API de cola respeta sla_activo.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-SLA-Token');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/sla_helper.php';

try {
    $db = getDBConnection();
    $secret = trim(sla_config_value($db, 'sla_webhook_secret', ''));
    if ($secret === '') {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'sla_webhook_secret no configurado']);
        exit;
    }

    $token = '';
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (stripos($auth, 'Bearer ') === 0) {
        $token = trim(substr($auth, 7));
    }
    if ($token === '' && !empty($_SERVER['HTTP_X_SLA_TOKEN'])) {
        $token = trim((string)$_SERVER['HTTP_X_SLA_TOKEN']);
    }
    if ($token === '' || !hash_equals($secret, $token)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'No autorizado']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $payload = json_decode($raw ?: '{}', true);
    if (!is_array($payload)) {
        $payload = [];
    }
    $orthanc = trim((string)($payload['orthanc_study_id'] ?? $payload['study_id'] ?? ''));
    if ($orthanc === '' && is_string($raw) && preg_match('/^[a-f0-9\-]{10,64}$/i', trim($raw))) {
        $orthanc = trim($raw);
    }
    if ($orthanc === '') {
        throw new Exception('orthanc_study_id requerido');
    }

    $res = sla_mark_local_arrived($db, [
        'orthanc_study_id' => $orthanc,
        'study_instance_uid' => trim((string)($payload['study_instance_uid'] ?? '')),
        'modality' => trim((string)($payload['modality'] ?? '')),
        'patient_id' => trim((string)($payload['patient_id'] ?? '')),
        'patient_name' => trim((string)($payload['patient_name'] ?? '')),
    ], null, 'webhook');

    echo json_encode([
        'success' => true,
        'estudios_id' => $res['estudios_id'],
        'created_row' => $res['created_row'],
        'updated' => $res['updated'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
