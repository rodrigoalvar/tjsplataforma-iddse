<?php
/**
 * Reasignar propietario de informes al usuario autenticado
 * Uso: fix-report-user.php?ids=12,11,1,3[&token=...]
 * También actualiza usuario_id en audios_informe.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
header('Content-Type: text/html; charset=utf-8');

require_once 'config/database.php';
require_once 'classes/User.php';

// Obtener token de sesión desde múltiples fuentes
function getSessionToken(): ?string {
    $sessionToken = null;
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        $sessionToken = $headers['Authorization'] ?? null;
    }
    if (!$sessionToken) {
        $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    }
    if (!$sessionToken) {
        $sessionToken = $_GET['token'] ?? $_GET['session_token'] ?? null;
    }
    if (!$sessionToken) {
        $sessionToken = $_COOKIE['session_token'] ?? null;
    }
    if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
        $sessionToken = substr($sessionToken, 7);
    }
    return $sessionToken ?: null;
}

function parseIds(string $idsParam): array {
    $ids = [];
    foreach (explode(',', $idsParam) as $idStr) {
        $id = (int) trim($idStr);
        if ($id > 0) { $ids[] = $id; }
    }
    return array_values(array_unique($ids));
}

echo "<h2>Reasignar informes al usuario autenticado</h2>";

try {
    $token = getSessionToken();
    if (!$token) {
        http_response_code(401);
        echo "<p style='color:red;'>Token de sesión requerido. Inicie sesión o pase ?token=...</p>";
        exit;
    }

    $user = new User();
    $userData = $user->validateSession($token);
    if (!$userData) {
        http_response_code(401);
        echo "<p style='color:red;'>Sesión inválida.</p>";
        exit;
    }

    $idsParam = isset($_GET['ids']) ? trim($_GET['ids']) : '';
    if ($idsParam === '') {
        echo "<p>Uso: fix-report-user.php?ids=12,11,1,3</p>";
        exit;
    }
    $ids = parseIds($idsParam);
    if (empty($ids)) {
        echo "<p>No se detectaron IDs válidos.</p>";
        exit;
    }

    $db = getDBConnection();

    // Preparar placeholders dinámicos
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    // Actualizar informes
    $updateReportsSql = "UPDATE informes SET usuario_id = ? WHERE id IN ($placeholders)";
    $stmt = $db->prepare($updateReportsSql);
    $params = array_merge([$userData['id']], $ids);
    $stmt->execute($params);
    $updatedReports = $stmt->rowCount();

    // Actualizar audios vinculados
    $updateAudiosSql = "UPDATE audios_informe SET usuario_id = ? WHERE informe_id IN ($placeholders)";
    $stmt2 = $db->prepare($updateAudiosSql);
    $stmt2->execute($params);
    $updatedAudios = $stmt2->rowCount();

    echo "<p style='color:green;'>Usuario autenticado: ID={$userData['id']}, Email={$userData['email']}</p>";
    echo "<p>IDs procesados: " . htmlspecialchars($idsParam) . "</p>";
    echo "<p>Informes reasignados: {$updatedReports}</p>";
    echo "<p>Audios reasignados: {$updatedAudios}</p>";
    echo "<hr>";
    echo "<p>Ahora debería poder visualizar y editar estos informes desde la UI.</p>";
    echo "<p><a href='debug-search.php?ids=" . urlencode($idsParam) . "' target='_blank'>Ver listado de informes</a></p>";

} catch (Exception $e) {
    echo "<p style='color:red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>