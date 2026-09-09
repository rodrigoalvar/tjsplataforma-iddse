<?php
/**
 * API simplificado para obtener audios de un informe - Versión raíz
 */

// Configurar headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Simular variables de servidor si no están disponibles (para pruebas CLI)
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['informe_id'] = '33'; // Para pruebas
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
    
    // Obtener parámetros
    $informeId = $_GET['informe_id'] ?? null;
    
    if (!$informeId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID de informe requerido']);
        exit();
    }
    
    // Obtener audios del informe
    $query = "SELECT id, informe_id, estudio_id, ruta_archivo, duracion_segundos, tamano_bytes, tipo_mime, calidad_audio, nombre_archivo, datos_sincronizacion, fecha_creacion, fecha_modificacion
              FROM audios_informe 
              WHERE informe_id = ?
              ORDER BY fecha_creacion ASC, id ASC";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$informeId]);
    $audios = $stmt->fetchAll();
    
    // Formatear datos de audios
    foreach ($audios as &$audio) {
        // Convertir fechas a formato ISO
        if ($audio['fecha_creacion']) {
            $audio['fecha_creacion_iso'] = date('c', strtotime($audio['fecha_creacion']));
            $audio['fecha_creacion_formatted'] = date('d/m/Y H:i', strtotime($audio['fecha_creacion']));
        }
        if ($audio['fecha_modificacion']) {
            $audio['fecha_modificacion_iso'] = date('c', strtotime($audio['fecha_modificacion']));
            $audio['fecha_modificacion_formatted'] = date('d/m/Y H:i', strtotime($audio['fecha_modificacion']));
        }
        
        // Agregar información adicional
        $audio['duracion_formatted'] = $audio['duracion_segundos'] ? gmdate('H:i:s', $audio['duracion_segundos']) : '00:00:00';
        $audio['tamano_formatted'] = $audio['tamano_bytes'] ? formatBytes($audio['tamano_bytes']) : '0 B';
        
        // Verificar si el archivo existe
        $audio['archivo_existe'] = file_exists($audio['ruta_archivo']);
        
        // Agregar URL de descarga
        $audio['url_descarga'] = $audio['archivo_existe'] ? 
            str_replace('../../', '../', $audio['ruta_archivo']) : null;
    }
    
    // Respuesta exitosa
    echo json_encode([
        'success' => true,
        'data' => [
            'audios' => $audios,
            'total' => count($audios),
            'informe_id' => $informeId
        ],
        'debug' => [
            'informe_id' => $informeId,
            'total_audios' => count($audios),
            'user_id' => 'no_auth'
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Error en get-audios-root.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor',
        'error' => $e->getMessage()
    ]);
}

/**
 * Formatear bytes a formato legible
 */
function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}
?>
