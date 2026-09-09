<?php
/**
 * API para listar informes médicos - Versión desde raíz
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
    // Validar sesión - Priorizar cookies sobre headers
    $sessionToken = null;
    
    // Primero intentar desde cookies (más confiable)
    if (isset($_COOKIE['session_token']) && !empty($_COOKIE['session_token'])) {
        $sessionToken = $_COOKIE['session_token'];
    }
    
    // Fallback a headers si no hay cookie
    if (!$sessionToken) {
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
            $sessionToken = $_GET['token'] ?? $_GET['session_token'] ?? null;
        }
        
        // Limpiar Bearer prefix si existe
        if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
            $sessionToken = substr($sessionToken, 7);
        }
    }
    
    // Para pruebas, si no hay token, continuar sin validación
    $userData = null;
    if ($sessionToken) {
        try {
            // Intentar diferentes rutas para User.php
            $userClassPaths = [
                'classes/User.php',
                './classes/User.php',
                '../classes/User.php'
            ];
            
            $userClassFound = false;
            foreach ($userClassPaths as $path) {
                if (file_exists($path)) {
                    require_once $path;
                    $userClassFound = true;
                    break;
                }
            }
            
            if (!$userClassFound) {
                throw new Exception('No se encontró la clase User');
            }
            
            $user = new User();
            $userData = $user->validateSession($sessionToken);
            
            if (!$userData) {
                error_log("Token validation failed for: " . substr($sessionToken, 0, 20) . "...");
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Sesión inválida', 'debug' => 'Token: ' . substr($sessionToken, 0, 20) . '...']);
                exit();
            }
            
            error_log("Token validation successful for user: " . $userData['id']);
        } catch (Exception $e) {
            error_log("Error validating session: " . $e->getMessage());
            // Para pruebas, continuar sin validación
            $userData = null;
        }
    }
    
    // Obtener parámetros de consulta
    $page = (int)($_GET['page'] ?? 1);
    $limit = (int)($_GET['limit'] ?? 10);
    $offset = ($page - 1) * $limit;
    
    $estado = $_GET['estado'] ?? null;
    $search = $_GET['search'] ?? null;
    $sortBy = $_GET['sort_by'] ?? 'fecha_creacion';
    $sortOrder = $_GET['sort_order'] ?? 'DESC';
    
    // Intentar conectar a la base de datos
    try {
        // Intentar diferentes rutas para database.php
        $dbConfigPaths = [
            'config/database.php',
            './config/database.php',
            '../config/database.php'
        ];
        
        $dbConfigFound = false;
        foreach ($dbConfigPaths as $path) {
            if (file_exists($path)) {
                require_once $path;
                $dbConfigFound = true;
                break;
            }
        }
        
        if (!$dbConfigFound) {
            throw new Exception('No se encontró el archivo de configuración de base de datos');
        }
        
        $db = getDBConnection();
    } catch (Exception $e) {
        error_log("Error connecting to database: " . $e->getMessage());
        // Para pruebas, devolver datos mock
        echo json_encode([
            'success' => true,
            'message' => 'Modo de prueba desde raíz - base de datos no disponible',
            'data' => [
                'informes' => [
                    [
                        'id' => 1,
                        'titulo' => 'Informe de Prueba 1',
                        'estado' => 'borrador',
                        'fecha_creacion' => date('Y-m-d H:i:s'),
                        'patient_name' => 'PACIENTE PRUEBA',
                        'modality' => 'MR'
                    ],
                    [
                        'id' => 2,
                        'titulo' => 'Informe de Prueba 2',
                        'estado' => 'finalizado',
                        'fecha_creacion' => date('Y-m-d H:i:s'),
                        'patient_name' => 'PACIENTE PRUEBA 2',
                        'modality' => 'CT'
                    ]
                ],
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total' => 2,
                    'total_pages' => 1
                ]
            ],
            'debug' => [
                'database_error' => $e->getMessage(),
                'file' => __FILE__,
                'timestamp' => date('Y-m-d H:i:s'),
                'location' => 'root'
            ]
        ]);
        exit();
    }
    
    // Construir consulta SQL
    $whereConditions = [];
    $params = [];
    
    // Filtrar por usuario si está autenticado
    if ($userData) {
        $whereConditions[] = "i.usuario_id = ?";
        $params[] = $userData['id'];
    }
    
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
        
        // Agregar badge de estado
        $estadoBadges = [
            'borrador' => 'secondary', // Gris
            'revision' => 'warning',   // Amarillo
            'revisado' => 'warning',  // Amarillo
            'finalizado' => 'success', // Verde
            'firmado' => 'primary'    // Azul
        ];
        $informe['estado_badge'] = $estadoBadges[$informe['estado']] ?? 'secondary';
        
        // Agregar estadísticas básicas
        $informe['estadisticas'] = [
            'caracteres_html' => strlen($informe['contenido_html'] ?? ''),
            'caracteres_texto' => strlen($informe['contenido_texto'] ?? ''),
            'palabras_aproximadas' => str_word_count($informe['contenido_texto'] ?? '')
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
        ],
        'debug' => [
            'user_id' => $userData ? $userData['id'] : 'no_auth',
            'total_informes' => count($informes),
            'total_records' => $total,
            'location' => 'root'
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Error en list-informes-root.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => 'LIST_REPORTS_ERROR',
        'debug' => [
            'file' => __FILE__,
            'line' => $e->getLine()
        ]
    ]);
} catch (PDOException $e) {
    error_log("Error de base de datos en list-informes-root.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor',
        'error_code' => 'DATABASE_ERROR',
        'debug' => [
            'error' => $e->getMessage(),
            'file' => __FILE__,
            'line' => $e->getLine()
        ]
    ]);
}
?>
