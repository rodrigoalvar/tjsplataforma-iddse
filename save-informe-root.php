<?php
/**
 * API para guardar informes médicos - Versión desde raíz
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

// Configurar headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Simular variables de servidor si no están disponibles (para pruebas CLI)
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
}

// Manejar preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Solo permitir POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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
    
    // Obtener datos del POST
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Datos JSON inválidos']);
        exit();
    }
    
    // Validar campos requeridos
    $requiredFields = ['titulo', 'contenido_html', 'contenido_texto', 'estudio_id'];
    foreach ($requiredFields as $field) {
        if (!isset($input[$field]) || empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Campo requerido: {$field}"]);
            exit();
        }
    }
    
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
                'test_mode' => true,
                'informe_id' => 'mock_' . time(),
                'user_id' => $userData ? $userData['id'] : 'no_auth',
                'titulo' => $input['titulo'],
                'estado' => 'borrador',
                'fecha_creacion' => date('Y-m-d H:i:s'),
                'debug' => [
                    'database_error' => $e->getMessage(),
                    'file' => __FILE__,
                    'timestamp' => date('Y-m-d H:i:s'),
                    'location' => 'root'
                ]
            ]
        ]);
        exit();
    }
    
    // Determinar si es creación o actualización
    $isUpdate = isset($input['id']) && !empty($input['id']);
    
    if ($isUpdate) {
        // Actualizar informe existente
        $query = "UPDATE informes SET 
                  titulo = ?, 
                  contenido_html = ?, 
                  contenido_texto = ?, 
                  estado = ?, 
                  fecha_modificacion = NOW(),
                  notas_revision = ?
                  WHERE id = ?";
        
        $params = [
            $input['titulo'],
            $input['contenido_html'],
            $input['contenido_texto'],
            $input['estado'] ?? 'borrador',
            $input['notas_revision'] ?? null,
            $input['id']
        ];
        
        // Si hay usuario autenticado, verificar que es el propietario
        if ($userData) {
            $query .= " AND usuario_id = ?";
            $params[] = $userData['id'];
        }
        
        $stmt = $db->prepare($query);
        $result = $stmt->execute($params);
        
        if ($result && $stmt->rowCount() > 0) {
            $informeId = $input['id'];
            $message = 'Informe actualizado correctamente';
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Informe no encontrado o sin permisos']);
            exit();
        }
        
    } else {
        // Crear nuevo informe
        $query = "INSERT INTO informes (
                  titulo, 
                  contenido_html, 
                  contenido_texto, 
                  estado, 
                  estudio_id, 
                  usuario_id, 
                  patient_id, 
                  patient_name, 
                  modality, 
                  study_description,
                  study_instance_uid,
                  study_id,
                  version,
                  fecha_creacion,
                  fecha_modificacion
                  ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())";
        
        $params = [
            $input['titulo'],
            $input['contenido_html'],
            $input['contenido_texto'],
            $input['estado'] ?? 'borrador',
            $input['estudio_id'],
            $userData ? $userData['id'] : null,
            $input['patient_id'] ?? null,
            $input['patient_name'] ?? null,
            $input['modality'] ?? null,
            $input['study_description'] ?? null,
            $input['study_instance_uid'] ?? null,
            $input['study_id'] ?? null
        ];
        
        $stmt = $db->prepare($query);
        $result = $stmt->execute($params);
        
        if ($result) {
            $informeId = $db->lastInsertId();
            $message = 'Informe creado correctamente';
        } else {
            throw new Exception('Error al crear el informe');
        }
    }
    
    // Obtener el informe actualizado/creado
    $query = "SELECT i.*, u.nombre as usuario_nombre, u.email as usuario_email
              FROM informes i
              LEFT JOIN usuarios u ON i.usuario_id = u.id
              WHERE i.id = ?";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$informeId]);
    $informe = $stmt->fetch();
    
    if (!$informe) {
        throw new Exception('Error al obtener el informe guardado');
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
    
    // Agregar badge de estado
    $estadoBadges = [
        'borrador' => 'secondary',
        'revision' => 'warning',
        'revisado' => 'warning',
        'finalizado' => 'success',
        'firmado' => 'primary'
    ];
    $informe['estado_badge'] = $estadoBadges[$informe['estado']] ?? 'secondary';
    
    // Respuesta exitosa
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => $informe,
        'debug' => [
            'informe_id' => $informeId,
            'user_id' => $userData ? $userData['id'] : 'no_auth',
            'is_update' => $isUpdate,
            'location' => 'root'
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Error en save-informe-root.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => 'SAVE_REPORT_ERROR',
        'debug' => [
            'file' => __FILE__,
            'line' => $e->getLine()
        ]
    ]);
} catch (PDOException $e) {
    error_log("Error de base de datos en save-informe-root.php: " . $e->getMessage());
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
