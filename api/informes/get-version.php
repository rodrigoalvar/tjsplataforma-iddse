<?php
/**
 * API Endpoint para obtener una versión específica de un informe
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

// Configurar manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 0); // No mostrar errores en pantalla
ini_set('log_errors', 1); // Registrar errores en log

// Configurar headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../classes/User.php';
    
    // Validar sesión - Obtener token de múltiples fuentes
    $sessionToken = null;
    
    // Intentar obtener de getallheaders() si está disponible
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        $sessionToken = $headers['Authorization'] ?? null;
    }
    
    // Fallback a $_SERVER
    if (!$sessionToken) {
        $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    }
    
    // Fallback a parámetros GET
    if (!$sessionToken) {
        $sessionToken = $_GET['token'] ?? null;
    }
    
    // Limpiar token si tiene prefijo "Bearer "
    if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
        $sessionToken = substr($sessionToken, 7);
    }
    
    if (!$sessionToken) {
        http_response_code(401);
        echo json_encode(['error' => 'Token de sesión requerido']);
        exit;
    }
    
    // Validar token con la clase User
    $user = new User();
    $userData = $user->validateSession($sessionToken);
    
    if (!$userData) {
        http_response_code(401);
        echo json_encode(['error' => 'Token de sesión inválido']);
        exit;
    }
    
    // Obtener parámetros
    $informeId = $_GET['informe_id'] ?? null;
    $version = $_GET['version'] ?? null;
    
    if (!$informeId || !$version) {
        http_response_code(400);
        echo json_encode(['error' => 'ID del informe y versión requeridos']);
        exit;
    }
    
    // Conectar a la base de datos
    $database = new Database();
    $db = $database->getConnection();
    
    // Buscar la versión específica en el historial
    $query = "
        SELECT 
            ih.informe_id as id,
            ih.version_anterior as version,
            ih.contenido_html_anterior as contenido_html,
            ih.estado_anterior as estado,
            ih.fecha_cambio as fecha_modificacion,
            ih.motivo_cambio,
            i.patient_name,
            i.modality,
            i.study_description,
            i.titulo,
            u.nombre as usuario_nombre,
            u.apellido as usuario_apellido
        FROM informes_historial ih
        LEFT JOIN informes i ON ih.informe_id = i.id
        LEFT JOIN usuarios u ON ih.usuario_modificacion = u.id
        WHERE ih.informe_id = ? AND ih.version_anterior = ?
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$informeId, $version]);
    $versionData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Si no se encuentra en historial, buscar en la tabla principal
    if (!$versionData) {
        $queryMain = "
            SELECT 
                i.id,
                i.version,
                i.contenido_html,
                i.estado,
                i.fecha_modificacion,
                'Versión actual' as motivo_cambio,
                i.patient_name,
                i.modality,
                i.study_description,
                i.titulo,
                u.nombre as usuario_nombre,
                u.apellido as usuario_apellido
            FROM informes i
            LEFT JOIN usuarios u ON i.usuario_id = u.id
            WHERE i.id = ? AND i.version = ?
        ";
        
        $stmtMain = $db->prepare($queryMain);
        $stmtMain->execute([$informeId, $version]);
        $versionData = $stmtMain->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$versionData) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Versión no encontrada',
            'debug' => [
                'informe_id' => $informeId,
                'version' => $version,
                'message' => 'No se encontró la versión especificada'
            ]
        ]);
        exit;
    }
    
    // Formatear datos
    $versionData['fecha_modificacion'] = date('d/m/Y H:i', strtotime($versionData['fecha_modificacion']));
    $versionData['usuario_completo'] = trim($versionData['usuario_nombre'] . ' ' . $versionData['usuario_apellido']);
    
    echo json_encode([
        'success' => true,
        'data' => $versionData,
        'debug' => [
            'informe_id' => $informeId,
            'version' => $version,
            'source' => isset($versionData['motivo_cambio']) && $versionData['motivo_cambio'] === 'Versión actual' ? 'main_table' : 'historial_table'
        ]
    ]);
    
} catch (PDOException $e) {
    error_log('Error obteniendo versión: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error interno del servidor',
        'debug' => [
            'type' => 'PDOException',
            'message' => $e->getMessage(),
            'informe_id' => $informeId ?? 'N/A',
            'version' => $version ?? 'N/A'
        ]
    ]);
} catch (Exception $e) {
    error_log('Error general: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug' => [
            'type' => 'Exception',
            'message' => $e->getMessage(),
            'informe_id' => $informeId ?? 'N/A',
            'version' => $version ?? 'N/A'
        ]
    ]);
}

// Asegurar que se envíe la respuesta
if (ob_get_level()) {
    ob_end_flush();
}
?>
