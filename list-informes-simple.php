<?php
/**
 * API para listar informes médicos - Versión simplificada
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

// Configurar headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Simular variables de servidor si no están disponibles (para pruebas CLI)
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
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
    
    // Obtener parámetros de consulta
    $page = (int)($_GET['page'] ?? 1);
    $limit = (int)($_GET['limit'] ?? 10);
    $offset = ($page - 1) * $limit;
    
    $estado = $_GET['estado'] ?? null;
    $search = $_GET['search'] ?? null;
    $sortBy = $_GET['sort_by'] ?? 'fecha_creacion';
    $sortOrder = $_GET['sort_order'] ?? 'DESC';
    
    // Construir consulta SQL
    $whereConditions = [];
    $params = [];
    
    // Filtrar por estado
    if ($estado) {
        $whereConditions[] = "i.estado = ?";
        $params[] = $estado;
    }
    
    // Filtrar por búsqueda
    if ($search) {
        $whereConditions[] = "(i.titulo LIKE ? OR i.patient_name LIKE ? OR i.contenido_texto LIKE ?)";
        $searchTerm = "%{$search}%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // Consulta para obtener total de registros
    $countQuery = "SELECT COUNT(*) as total 
                   FROM informes i 
                   LEFT JOIN usuarios u ON i.usuario_id = u.id 
                   {$whereClause}";
    
    $countStmt = $db->prepare($countQuery);
    $countStmt->execute($params);
    $total = $countStmt->fetch()['total'];
    
    // Consulta principal
    $query = "SELECT i.*, u.nombre as usuario_nombre, u.email as usuario_email
              FROM informes i
              LEFT JOIN usuarios u ON i.usuario_id = u.id
              {$whereClause}
              ORDER BY i.{$sortBy} {$sortOrder}
              LIMIT {$limit} OFFSET {$offset}";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $informes = $stmt->fetchAll();
    
    // Cargar audios para cada informe
    if (!empty($informes)) {
        $informeIds = array_column($informes, 'id');
        $placeholders = str_repeat('?,', count($informeIds) - 1) . '?';
        
        $audioQuery = "SELECT id, informe_id, estudio_id, ruta_archivo, duracion_segundos, tamano_bytes, tipo_mime, calidad_audio, nombre_archivo, datos_sincronizacion, fecha_creacion, fecha_modificacion
                       FROM audios_informe 
                       WHERE informe_id IN ({$placeholders})
                       ORDER BY fecha_creacion ASC, id ASC";
        $audioStmt = $db->prepare($audioQuery);
        $audioStmt->execute($informeIds);
        $audios = $audioStmt->fetchAll();
        
        // Agrupar audios por informe_id
        $audiosPorInforme = [];
        foreach ($audios as $audio) {
            $audiosPorInforme[$audio['informe_id']][] = $audio;
        }
    }
    
    // Formatear datos
    foreach ($informes as &$informe) {
        // Convertir fechas a formato ISO
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
        }
        
        // Agregar audios al informe
        $informe['audios'] = $audiosPorInforme[$informe['id']] ?? [];
        $informe['total_audios'] = count($informe['audios']);
        $informe['total_versiones'] = $informe['version'] ?? 1;
        
        // Agregar badge de estado
        $estadoBadges = [
            'borrador' => 'secondary',
            'revision' => 'warning',
            'revisado' => 'warning',
            'finalizado' => 'success',
            'firmado' => 'primary'
        ];
        $informe['estado_badge'] = $estadoBadges[$informe['estado']] ?? 'secondary';
        
        // Agregar estadísticas básicas
        $informe['estadisticas'] = [
            'caracteres_html' => strlen($informe['contenido_html'] ?? ''),
            'caracteres_texto' => strlen($informe['contenido_texto'] ?? ''),
            'palabras_aproximadas' => str_word_count($informe['contenido_texto'] ?? ''),
            'tiene_audios' => count($informe['audios']) > 0,
            'cantidad_audios' => count($informe['audios'])
        ];
    }
    
    // Calcular paginación
    $totalPages = ceil($total / $limit);
    
    // Respuesta exitosa
    echo json_encode([
        'success' => true,
        'data' => [
            'informes' => $informes,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total' => $total,
                'total_results' => $total,
                'total_pages' => $totalPages,
                'has_next' => $page < $totalPages,
                'has_prev' => $page > 1
            ],
            'filters' => [
                'estado' => $estado,
                'search' => $search,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder
            ]
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Error en list-informes-simple.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor',
        'error' => $e->getMessage()
    ]);
}
?>
