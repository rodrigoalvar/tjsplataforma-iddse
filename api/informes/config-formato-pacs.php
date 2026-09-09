<?php
/**
 * API para Configurar Formato Por Defecto de Envío a PACS
 * 
 * Permite guardar y obtener el formato preferido (PDF o PNG/JPG)
 * que se usará por defecto al enviar informes a PACS.
 * 
 * La configuración se guarda en base de datos en tabla 'configuracion'
 * con clave 'pacs_formato_defecto'
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../../classes/User.php';
require_once '../../vendor/autoload.php';

try {
    // Validar sesión
    $sessionToken = null;
    
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        $sessionToken = $headers['Authorization'] ?? null;
    }
    
    if (!$sessionToken) {
        $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
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
    
    // Conectar a BD
    $db = getDBConnection();
    
    // GET: Obtener configuración actual
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        try {
            // Intentar obtener de tabla configuracion
            $stmt = $db->prepare("SELECT valor FROM configuracion WHERE clave = 'pacs_formato_defecto' LIMIT 1");
            $stmt->execute();
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $formatoDefecto = $config ? $config['valor'] : 'pdf';
            
            // Validar que sea un formato válido
            if (!in_array($formatoDefecto, ['pdf', 'jpg'])) {
                $formatoDefecto = 'pdf';
            }
            
            echo json_encode([
                'success' => true,
                'formato' => $formatoDefecto,
                'message' => 'Configuración obtenida exitosamente'
            ]);
            
        } catch (PDOException $e) {
            // Si la tabla no existe, devolver PDF por defecto
            if (strpos($e->getMessage(), 'configuracion') !== false || 
                strpos($e->getMessage(), "doesn't exist") !== false) {
                
                echo json_encode([
                    'success' => true,
                    'formato' => 'pdf',
                    'message' => 'Usando formato por defecto (tabla configuracion no existe)',
                    'warning' => 'Tabla configuracion no existe. Ejecutar: CREATE TABLE configuracion (clave VARCHAR(100) PRIMARY KEY, valor TEXT);'
                ]);
            } else {
                throw $e;
            }
        }
    }
    
    // POST: Guardar configuración
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            $input = $_POST;
        }
        
        $formato = strtolower($input['formato'] ?? '');
        
        if (!in_array($formato, ['pdf', 'jpg'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Formato inválido. Use "pdf" o "jpg"'
            ]);
            exit();
        }
        
        try {
            // Verificar si ya existe
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM configuracion WHERE clave = 'pacs_formato_defecto'");
            $stmt->execute();
            $exists = $stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;
            
            if ($exists) {
                // Actualizar
                $stmt = $db->prepare("UPDATE configuracion SET valor = ? WHERE clave = 'pacs_formato_defecto'");
                $stmt->execute([$formato]);
            } else {
                // Insertar
                $stmt = $db->prepare("INSERT INTO configuracion (clave, valor) VALUES ('pacs_formato_defecto', ?)");
                $stmt->execute([$formato]);
            }
            
            echo json_encode([
                'success' => true,
                'formato' => $formato,
                'message' => "Formato por defecto configurado como " . strtoupper($formato)
            ]);
            
        } catch (PDOException $e) {
            // Si la tabla no existe, intentar crearla
            if (strpos($e->getMessage(), 'configuracion') !== false || 
                strpos($e->getMessage(), "doesn't exist") !== false) {
                
                try {
                    // Crear tabla configuracion
                    $db->exec("
                        CREATE TABLE IF NOT EXISTS configuracion (
                            clave VARCHAR(100) PRIMARY KEY,
                            valor TEXT,
                            descripcion TEXT,
                            fecha_modificacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                        )
                    ");
                    
                    // Insertar configuración
                    $stmt = $db->prepare("INSERT INTO configuracion (clave, valor, descripcion) VALUES ('pacs_formato_defecto', ?, 'Formato por defecto para envío a PACS: pdf o jpg')");
                    $stmt->execute([$formato]);
                    
                    echo json_encode([
                        'success' => true,
                        'formato' => $formato,
                        'message' => "Formato por defecto configurado como " . strtoupper($formato) . " (tabla creada automáticamente)"
                    ]);
                    
                } catch (PDOException $createError) {
                    throw new Exception("No se pudo crear la tabla configuracion: " . $createError->getMessage());
                }
            } else {
                throw $e;
            }
        }
    }
    
    else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    }
    
} catch (Exception $e) {
    error_log("Error en config-formato-pacs.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor',
        'error' => $e->getMessage()
    ]);
}
?>

