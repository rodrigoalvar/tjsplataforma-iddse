<?php
/**
 * Registro público de accesos al portal paciente (sin login).
 * Rate limit por IP (~200 registros / hora).
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'GET'], true)) {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../AuditLogger.php';

function auditPortalPacienteTableExists(PDO $db): bool {
    try {
        $st = $db->query("SHOW TABLES LIKE 'audit_portal_paciente_visits'");
        if (!$st) {
            return false;
        }

        return $st->fetch(PDO::FETCH_NUM) !== false;
    } catch (Exception $e) {
        return false;
    }
}

function auditPortalPacienteHasPatientQueryColumn(PDO $db): bool {
    try {
        $st = $db->query("SHOW COLUMNS FROM `audit_portal_paciente_visits` LIKE 'patient_query'");
        if (!$st) {
            return false;
        }

        return $st->fetch(PDO::FETCH_ASSOC) !== false;
    } catch (Exception $e) {
        return false;
    }
}

/** Texto introducido en el portal (documento / ID); null si vacío. */
function sanitizePatientPortalQuery($raw): ?string {
    if ($raw === null || $raw === '') {
        return null;
    }
    $s = trim((string) $raw);
    if ($s === '') {
        return null;
    }
    $s = preg_replace('/[^\p{L}\p{N}\s.\-\/@_]/u', '', $s);
    if ($s === '') {
        return null;
    }

    return substr($s, 0, 128);
}

try {
    $db = getDBConnection();
    if (!$db || !auditPortalPacienteTableExists($db)) {
        http_response_code(503);
        echo json_encode(['success' => false, 'error' => 'Registro no configurado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ip = AuditLogger::clientIp() ?? '';
    $ipKey = $ip !== '' ? $ip : '';
    $lim = $db->prepare(
        'SELECT COUNT(*) FROM audit_portal_paciente_visits
         WHERE IFNULL(ip_address, \'\') = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
    );
    $lim->execute([$ipKey]);
    if ((int) $lim->fetchColumn() >= 200) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Límite de registros por hora'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ua = AuditLogger::userAgent();
    $page = 'paciente.html';
    $patientQuery = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $input = $raw ? json_decode($raw, true) : [];
        if (is_array($input) && !empty($input['page'])) {
            $page = preg_replace('#[^a-zA-Z0-9._/-]#', '', substr((string) $input['page'], 0, 128));
        }
        if (is_array($input) && array_key_exists('patient_query', $input)) {
            $patientQuery = sanitizePatientPortalQuery($input['patient_query']);
        }
    } else {
        if (!empty($_GET['page'])) {
            $page = preg_replace('#[^a-zA-Z0-9._/-]#', '', substr((string) $_GET['page'], 0, 128));
        }
        if (!empty($_GET['q'])) {
            $patientQuery = sanitizePatientPortalQuery($_GET['q']);
        }
    }
    if ($page === '') {
        $page = 'paciente.html';
    }

    $ref = isset($_SERVER['HTTP_REFERER']) ? substr((string) $_SERVER['HTTP_REFERER'], 0, 512) : null;

    $hasPq = auditPortalPacienteHasPatientQueryColumn($db);
    if ($hasPq) {
        $ins = $db->prepare(
            'INSERT INTO audit_portal_paciente_visits (ip_address, user_agent, page_url, referer, patient_query) VALUES (?, ?, ?, ?, ?)'
        );
        $ins->execute([$ip !== '' ? $ip : null, $ua, $page, $ref, $patientQuery]);
    } else {
        $ins = $db->prepare(
            'INSERT INTO audit_portal_paciente_visits (ip_address, user_agent, page_url, referer) VALUES (?, ?, ?, ?)'
        );
        $ins->execute([$ip !== '' ? $ip : null, $ua, $page, $ref]);
    }

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[portal-paciente-log] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error interno'], JSON_UNESCAPED_UNICODE);
}
