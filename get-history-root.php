<?php
/**
 * API para obtener historial de informes - Versión raíz
 */

// Configurar headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Simular variables de servidor si no están disponibles (para pruebas CLI)
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['informe_id'] = '35'; // Para pruebas
}

// Manejar preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Solo permitir GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

try {
    // Intentar conectar a la base de datos
    require_once 'config/database.php';
    $db = getDBConnection();
    
    // Obtener ID del informe
    $informeId = $_GET['informe_id'] ?? null;
    if (!$informeId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID del informe requerido']);
        exit();
    }
    
    // Obtener todas las versiones del informe (incluyendo la actual y las del historial)
    $query = "
        SELECT 
            i.id,
            i.version as version_numero,
            i.contenido_html,
            i.estado,
            i.fecha_modificacion as fecha_cambio,
            'Versión actual' as motivo_cambio,
            u.nombre as usuario_nombre,
            u.apellido as usuario_apellido,
            'actual' as tipo_version
        FROM informes i
        LEFT JOIN usuarios u ON i.usuario_id = u.id
        WHERE i.id = ?
        
        UNION ALL
        
        SELECT 
            ih.informe_id as id,
            ih.version_anterior as version_numero,
            ih.contenido_html_anterior as contenido_html,
            ih.estado_anterior as estado,
            ih.fecha_cambio,
            ih.motivo_cambio,
            u.nombre as usuario_nombre,
            u.apellido as usuario_apellido,
            'historial' as tipo_version
        FROM informes_historial ih
        LEFT JOIN usuarios u ON ih.usuario_modificacion = u.id
        WHERE ih.informe_id = ?
        
        ORDER BY version_numero DESC, fecha_cambio DESC
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$informeId, $informeId]);
    $todasLasVersiones = $stmt->fetchAll();
    
    // Separar versión actual del historial
    $versionActual = null;
    $historial = [];
    
    foreach ($todasLasVersiones as $version) {
        if ($version['tipo_version'] === 'actual') {
            $versionActual = $version;
        } else {
            $historial[] = $version;
        }
    }
    
    // Formatear fechas
    if ($versionActual) {
        $versionActual['fecha_cambio_formatted'] = date('d/m/Y H:i', strtotime($versionActual['fecha_cambio']));
        $versionActual['usuario_completo'] = trim($versionActual['usuario_nombre'] . ' ' . $versionActual['usuario_apellido']);
        $versionActual['contenido_preview'] = mb_substr(strip_tags($versionActual['contenido_html']), 0, 200) . '...';
    }
    
    foreach ($historial as &$version) {
        $version['fecha_cambio_formatted'] = date('d/m/Y H:i', strtotime($version['fecha_cambio']));
        $version['usuario_completo'] = trim($version['usuario_nombre'] . ' ' . $version['usuario_apellido']);
        $version['contenido_preview'] = mb_substr(strip_tags($version['contenido_html']), 0, 200) . '...';
    }
    
    // Respuesta exitosa
    echo json_encode([
        'success' => true,
        'data' => [
            'informe_actual' => $versionActual,
            'historial' => $historial,
            'total_versiones' => count($historial) + ($versionActual ? 1 : 0)
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Error en get-history-root.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor',
        'error' => $e->getMessage()
    ]);
}
?>
