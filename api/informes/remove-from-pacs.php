<?php
/**
 * Eliminar informe de Orthanc PACS (botón amarillo informes-manager).
 */
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    require_once '../../vendor/autoload.php';
    require_once '../../classes/User.php';
    require_once '../../config/database.php';
    require_once 'InformePacsRemover.php';

    $sessionToken = null;
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        $sessionToken = $headers['Authorization'] ?? null;
    }
    if (!$sessionToken) {
        $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    }
    if (!$sessionToken) {
        $sessionToken = $_POST['token'] ?? $_POST['session_token'] ?? null;
    }
    if (!$sessionToken) {
        $sessionToken = $_COOKIE['session_token'] ?? null;
    }
    if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
        $sessionToken = substr($sessionToken, 7);
    }
    if (!$sessionToken) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Token de autorización requerido']);
        exit();
    }

    $user = new User();
    $userData = $user->validateSession($sessionToken);
    if (!$userData) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sesión inválida']);
        exit();
    }

    $userPermissions = json_decode($userData['permisos'] ?? '[]', true);
    $canManageAllReports = in_array('all', $userPermissions, true)
        || in_array('gestionInformes', $userPermissions, true);

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $informeId = (int) ($input['informe_id'] ?? 0);
    if ($informeId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'informe_id es requerido']);
        exit();
    }

    $db = getDBConnection();

    if (!$canManageAllReports) {
        $ownStmt = $db->prepare('SELECT id FROM informes WHERE id = ? AND usuario_id = ?');
        $ownStmt->execute([$informeId, $userData['id']]);
        if (!$ownStmt->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Informe no encontrado o sin permisos']);
            exit();
        }
    }

    $result = InformePacsRemover::removeInformeFromPacs($db, $informeId);
    if (!$result['success']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $result['message']]);
        exit();
    }

    $qaFile = __DIR__ . '/../../modules/qa-publicacion/QaPublicationService.php';
    if (is_file($qaFile)) {
        require_once $qaFile;
        if (class_exists('QaPublicationService') && QaPublicationService::isInstalled($db)) {
            $infStmt = $db->prepare('SELECT study_instance_uid, study_id FROM informes WHERE id = ?');
            $infStmt->execute([$informeId]);
            $infRow = $infStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            QaPublicationService::upsertInformeStatus(
                $db,
                $informeId,
                QaPublicationService::ESTADO_BLOQUEADO,
                'eliminado_desde_informes_manager',
                null,
                (int) $userData['id'],
                $infRow['study_instance_uid'] ?? null,
                true
            );
            QaPublicationService::logAction($db, [
                'accion' => 'bajar_pacs',
                'target_type' => 'informe',
                'orthanc_id' => $infRow['study_id'] ?? null,
                'study_instance_uid' => $infRow['study_instance_uid'] ?? null,
                'informe_id' => $informeId,
                'pacs_series_id' => $result['data']['series_id'] ?? null,
                'pacs_instance_id' => $result['data']['instance_id'] ?? null,
                'usuario_id' => (int) $userData['id'],
                'motivo' => 'informes_manager_remove_from_pacs',
                'resultado' => 'ok',
                'detalle' => $result,
            ]);
        }
    }

    $payload = [
        'success' => true,
        'message' => $result['message'],
        'deleted_by' => $result['deleted_by'],
        'data' => $result['data'],
    ];
    if (!empty($result['warning'])) {
        $payload['warning'] = $result['warning'];
    }
    echo json_encode($payload);
} catch (PDOException $e) {
    error_log('[REMOVE_FROM_PACS] Error de BD: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de base de datos', 'error' => $e->getMessage()]);
} catch (Exception $e) {
    error_log('[REMOVE_FROM_PACS] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al eliminar de PACS', 'error' => $e->getMessage()]);
}
