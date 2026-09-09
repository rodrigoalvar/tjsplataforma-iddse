<?php
/**
 * API para Configurar Tipo de Búsqueda por Defecto en paciente.html
 * 
 * Permite guardar y obtener el tipo de búsqueda preferido (idpaciente/documento o id_interno)
 * que se usará por defecto al buscar estudios en paciente.html
 * 
 * La configuración se guarda en base de datos en tabla 'configuracion'
 * con clave 'paciente_search_type'
 * 
 * Valores posibles:
 * - 'idpaciente' o 'documento': Busca por PatientID en PACS (por defecto)
 * - 'id_interno': Busca por ID interno en tabla pacientes, luego usa idpaciente
 */

// Configurar manejo de errores ANTES de cualquier salida
error_reporting(E_ALL);
ini_set('display_errors', 0); // No mostrar errores en pantalla
ini_set('log_errors', 1); // Registrar errores en log

// Headers JSON siempre primero
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Buffer de salida para capturar errores
ob_start();

// Manejar preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    ob_end_flush();
    exit();
}

try {
    require_once __DIR__ . '/../../config/database.php';
    $db = getDBConnection();
    
    if (!$db) {
        throw new Exception('No se pudo conectar a la base de datos');
    }
    
    // GET: Obtener configuración actual
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        try {
            // Verificar si existe la tabla configuracion
            $checkTable = $db->query("SHOW TABLES LIKE 'configuracion'");
            
            if ($checkTable->rowCount() === 0) {
                // Crear tabla si no existe
                $db->exec("
                    CREATE TABLE IF NOT EXISTS configuracion (
                        clave VARCHAR(100) PRIMARY KEY,
                        valor TEXT,
                        descripcion TEXT,
                        fecha_modificacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
                
                // Insertar valor por defecto
                $stmt = $db->prepare("INSERT INTO configuracion (clave, valor, descripcion) VALUES ('paciente_search_type', 'idpaciente', 'Tipo de búsqueda por defecto en paciente.html: idpaciente o id_interno')");
                $stmt->execute();
                
                // Limpiar cualquier salida previa antes de enviar JSON
                ob_clean();
                echo json_encode([
                    'success' => true,
                    'search_type' => 'idpaciente',
                    'message' => 'Configuración obtenida (valor por defecto)'
                ]);
                ob_end_flush();
                exit();
            }
            
            // Intentar obtener de tabla configuracion
            $stmt = $db->prepare("SELECT valor FROM configuracion WHERE clave = 'paciente_search_type' LIMIT 1");
            $stmt->execute();
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $searchType = $config ? $config['valor'] : 'idpaciente';
            
            // Validar que sea un tipo válido
            if (!in_array($searchType, ['idpaciente', 'documento', 'id_interno'])) {
                $searchType = 'idpaciente';
            }
            
            // Limpiar cualquier salida previa antes de enviar JSON
            ob_clean();
            echo json_encode([
                'success' => true,
                'search_type' => $searchType,
                'message' => 'Configuración obtenida exitosamente'
            ]);
            
        } catch (PDOException $e) {
            // Si hay error pero es por tabla no existe, usar valor por defecto
            if (strpos($e->getMessage(), 'configuracion') !== false || 
                strpos($e->getMessage(), "doesn't exist") !== false) {
                
                ob_clean();
                echo json_encode([
                    'success' => true,
                    'search_type' => 'idpaciente',
                    'message' => 'Usando tipo de búsqueda por defecto (tabla configuracion no existe)',
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
        
        if (!isset($input['search_type'])) {
            ob_clean();
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Parámetro search_type requerido']);
            ob_end_flush();
            exit();
        }
        
        $searchType = trim($input['search_type']);
        
        // Validar que sea un tipo válido
        if (!in_array($searchType, ['idpaciente', 'documento', 'id_interno'])) {
            ob_clean();
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Tipo de búsqueda inválido. Debe ser: idpaciente, documento o id_interno']);
            ob_end_flush();
            exit();
        }
        
        try {
            // Verificar si existe la tabla configuracion
            $checkTable = $db->query("SHOW TABLES LIKE 'configuracion'");
            
            if ($checkTable->rowCount() === 0) {
                // Crear tabla si no existe
                $db->exec("
                    CREATE TABLE IF NOT EXISTS configuracion (
                        clave VARCHAR(100) PRIMARY KEY,
                        valor TEXT,
                        descripcion TEXT,
                        fecha_modificacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }
            
            // Verificar si ya existe la configuración
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM configuracion WHERE clave = 'paciente_search_type'");
            $stmt->execute();
            $exists = $stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;
            
            if ($exists) {
                // Actualizar
                $stmt = $db->prepare("UPDATE configuracion SET valor = ?, descripcion = ? WHERE clave = 'paciente_search_type'");
                $stmt->execute([$searchType, 'Tipo de búsqueda por defecto en paciente.html: idpaciente o id_interno']);
            } else {
                // Insertar
                $stmt = $db->prepare("INSERT INTO configuracion (clave, valor, descripcion) VALUES ('paciente_search_type', ?, 'Tipo de búsqueda por defecto en paciente.html: idpaciente o id_interno')");
                $stmt->execute([$searchType]);
            }
            
            ob_clean();
            echo json_encode([
                'success' => true,
                'search_type' => $searchType,
                'message' => "Tipo de búsqueda configurado como " . ($searchType === 'id_interno' ? 'ID Interno' : 'ID PACS/Documento')
            ]);
            
        } catch (PDOException $e) {
            // Si hay error pero es por tabla no existe, intentar crear
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
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                    
                    // Insertar configuración
                    $stmt = $db->prepare("INSERT INTO configuracion (clave, valor, descripcion) VALUES ('paciente_search_type', ?, 'Tipo de búsqueda por defecto en paciente.html: idpaciente o id_interno')");
                    $stmt->execute([$searchType]);
                    
                    ob_clean();
                    echo json_encode([
                        'success' => true,
                        'search_type' => $searchType,
                        'message' => "Tipo de búsqueda configurado como " . ($searchType === 'id_interno' ? 'ID Interno' : 'ID PACS/Documento') . " (tabla creada automáticamente)"
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
        ob_clean();
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    }
    
} catch (Exception $e) {
    // Limpiar cualquier salida previa
    ob_clean();
    error_log("Error en config-search-type.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor',
        'error' => $e->getMessage()
    ]);
}

// Limpiar buffer y enviar respuesta
ob_end_flush();
?>
