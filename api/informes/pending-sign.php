<?php
/**
 * Cola / conteo de informes pendientes de firma para el médico logueado.
 * Query: count_only, limit, fecha_inicio, fecha_fin (YYYY-MM-DD sobre fecha_modificacion).
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../config/database.php';

try {
    $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? $_GET['session_token'] ?? null;
    if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
        $sessionToken = substr($sessionToken, 7);
    }
    if (!$sessionToken && !empty($_COOKIE['session_token'])) {
        $sessionToken = $_COOKIE['session_token'];
    }
    if (!$sessionToken) {
        throw new Exception('Token de sesión requerido');
    }

    $user = new User();
    $userData = $user->validateSession($sessionToken);
    if (!$userData) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sesión inválida']);
        exit();
    }

    $permisos = json_decode($userData['permisos'] ?? '[]', true) ?: [];
    $canSign = in_array('all', $permisos, true) || in_array('firmarInformes', $permisos, true);
    if (!$canSign) {
        echo json_encode(['success' => true, 'count' => 0, 'items' => [], 'message' => 'Sin permiso firmarInformes']);
        exit();
    }

    $db = getDBConnection();
    $uid = (int)$userData['id'];
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $withItems = !isset($_GET['count_only']) || $_GET['count_only'] === '0' || $_GET['count_only'] === 'false';

    $fechaInicio = trim((string)($_GET['fecha_inicio'] ?? ''));
    $fechaFin = trim((string)($_GET['fecha_fin'] ?? ''));
    if ($fechaInicio !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaInicio)) {
        throw new Exception('fecha_inicio inválida');
    }
    if ($fechaFin !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaFin)) {
        throw new Exception('fecha_fin inválida');
    }

    $ownerSql = "
          AND (
            i.usuario_id = :uid
            OR EXISTS (
              SELECT 1 FROM study_assignments sa
              WHERE sa.status = 'active' AND sa.user_id = :uid2
                AND (
                  sa.study_id = i.estudio_id OR sa.study_id = i.study_id
                  OR (sa.study_instance_uid IS NOT NULL AND sa.study_instance_uid <> '' AND sa.study_instance_uid = i.study_instance_uid)
                  OR (sa.orthanc_study_id IS NOT NULL AND sa.orthanc_study_id <> '' AND (sa.orthanc_study_id = i.estudio_id OR sa.orthanc_study_id = i.study_id))
                )
            )
            OR EXISTS (
              SELECT 1 FROM study_subassignments ss
              WHERE ss.status = 'active'
                AND (ss.main_user_id = :uid3 OR ss.subassigned_to_user_id = :uid4)
                AND (ss.study_id = i.estudio_id OR ss.study_id = i.study_id)
            )
          )
    ";

    $dateSql = '';
    $params = [':uid' => $uid, ':uid2' => $uid, ':uid3' => $uid, ':uid4' => $uid];
    if ($fechaInicio !== '') {
        $dateSql .= ' AND DATE(i.fecha_modificacion) >= :fecha_inicio';
        $params[':fecha_inicio'] = $fechaInicio;
    }
    if ($fechaFin !== '') {
        $dateSql .= ' AND DATE(i.fecha_modificacion) <= :fecha_fin';
        $params[':fecha_fin'] = $fechaFin;
    }

    $sqlCount = "
        SELECT COUNT(DISTINCT i.id) AS cnt
        FROM informes i
        WHERE i.estado = 'transcripto'
        {$ownerSql}
        {$dateSql}
    ";
    $cStmt = $db->prepare($sqlCount);
    $cStmt->execute($params);
    $count = (int)$cStmt->fetchColumn();

    $items = [];
    if ($withItems) {
        $sql = "
            SELECT i.id, i.titulo, i.patient_name, i.patient_id, i.modality, i.estudio_id,
                   i.study_instance_uid, i.study_id, i.origen, i.estado, i.fecha_modificacion, i.pdf_path,
                   i.fecha_creacion
            FROM informes i
            WHERE i.estado = 'transcripto'
            {$ownerSql}
            {$dateSql}
            ORDER BY i.fecha_modificacion DESC
            LIMIT {$limit}
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $origen = strtolower((string)($row['origen'] ?? 'plataforma'));
            $row['editable'] = ($origen !== 'externo');
            $row['fecha_modificacion_fmt'] = !empty($row['fecha_modificacion'])
                ? date('d/m/Y H:i', strtotime($row['fecha_modificacion']))
                : '';
            $items[] = $row;
        }
    }

    echo json_encode([
        'success' => true,
        'count' => $count,
        'items' => $items,
        'filters' => [
            'fecha_inicio' => $fechaInicio !== '' ? $fechaInicio : null,
            'fecha_fin' => $fechaFin !== '' ? $fechaFin : null,
            'limit' => $limit,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
