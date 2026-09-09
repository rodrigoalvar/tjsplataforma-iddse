<?php
/**
 * API para obtener un informe específico - Versión raíz
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
    
    // Obtener parámetros
    $informeId = $_GET['informe_id'] ?? null;
    
    if (!$informeId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID de informe requerido']);
        exit();
    }
    
    // Obtener el informe
    $query = "SELECT i.*, u.nombre as usuario_nombre, u.apellido as usuario_apellido, u.email as usuario_email
              FROM informes i
              LEFT JOIN usuarios u ON i.usuario_id = u.id
              WHERE i.id = ?";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$informeId]);
    $informe = $stmt->fetch();
    
    if (!$informe) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Informe no encontrado']);
        exit();
    }
    
    // Formatear fechas
    if ($informe['fecha_creacion']) {
        $informe['fecha_creacion_iso'] = date('c', strtotime($informe['fecha_creacion']));
        $informe['fecha_creacion_formatted'] = date('d/m/Y H:i', strtotime($informe['fecha_creacion']));
    }
    if ($informe['fecha_modificacion']) {
        $informe['fecha_modificacion_iso'] = date('c', strtotime($informe['fecha_modificacion']));
        $informe['fecha_modificacion_formatted'] = date('d/m/Y H:i', strtotime($informe['fecha_modificacion']));
    }
    if ($informe['fecha_finalizacion']) {
        $informe['fecha_finalizacion_iso'] = date('c', strtotime($informe['fecha_finalizacion']));
        $informe['fecha_finalizacion_formatted'] = date('d/m/Y H:i', strtotime($informe['fecha_finalizacion']));
    }
    
    // Agregar información del usuario
    $informe['usuario_completo'] = trim($informe['usuario_nombre'] . ' ' . $informe['usuario_apellido']);
    
    // Agregar badge de estado
    $estadoBadges = [
        'borrador' => 'secondary',
        'revision' => 'warning',
        'revisado' => 'warning',
        'finalizado' => 'success',
        'firmado' => 'primary'
    ];
    $informe['estado_badge'] = $estadoBadges[$informe['estado']] ?? 'secondary';
    
    // Agregar estadísticas
    $informe['estadisticas'] = [
        'caracteres_html' => strlen($informe['contenido_html'] ?? ''),
        'caracteres_texto' => strlen($informe['contenido_texto'] ?? ''),
        'palabras_aproximadas' => str_word_count($informe['contenido_texto'] ?? '')
    ];
    
    // Respuesta exitosa
    echo json_encode([
        'success' => true,
        'data' => [
            'informe' => $informe
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Error en get-informe-root.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor',
        'error' => $e->getMessage()
    ]);
}
?>