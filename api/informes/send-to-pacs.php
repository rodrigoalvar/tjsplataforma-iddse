<?php
/**
 * API Endpoint para enviar informes médicos como PDF a Orthanc PACS
 * 
 * Este endpoint:
 * 1. Recibe un informe_id
 * 2. Carga el informe desde la base de datos
 * 3. Genera un PDF desde el contenido HTML
 * 4. Envía el PDF como objeto DICOM Encapsulated PDF a Orthanc
 * 5. Opcionalmente verifica duplicados antes de enviar
 * 
 * @package    PORTAL_ESTUDIOS
 * @subpackage API/Informes
 * @version    1.0.0
 */

// Configurar manejo de errores ANTES de cualquier salida
error_reporting(E_ALL);
ini_set('display_errors', 0); // No mostrar errores en pantalla
ini_set('log_errors', 1); // Registrar errores en log
ini_set('error_log', __DIR__ . '/../../logs/php_errors.log'); // Archivo de log específico

// Buffer de salida para capturar errores
ob_start();

// Capturar errores fatales y garantizar respuesta JSON
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
        // Evitar duplicar salida si ya se envió
        if (function_exists('headers_sent') && !headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        // Limpiar cualquier salida previa
        if (function_exists('ob_get_level')) {
            while (ob_get_level() > 0) { ob_end_clean(); }
        }
        echo json_encode([
            'success' => false,
            'error_code' => 'FATAL_ERROR',
            'message' => 'Error interno del servidor (fatal)',
            'error_details' => $error
        ]);
    }
});

// Headers JSON siempre primero
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    ob_end_clean();
    exit();
}

// Solo permitir POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

// Cargar autoload de Composer PRIMERO (incluye database.php automáticamente)
if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
}

// Luego cargar las demás clases necesarias
require_once '../../classes/User.php';
// NO cargar database.php manualmente - Composer ya lo carga automáticamente
require_once '../OrthancPacsSender.php';
require_once __DIR__ . '/send_to_pacs_service.php';

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
    
    if (!$sessionToken) {
        $sessionToken = $_POST['token'] ?? $_POST['session_token'] ?? null;
    }
    
    if (!$sessionToken) {
        $sessionToken = $_COOKIE['session_token'] ?? null;
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
    
    // Obtener informe_id del request
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $informeId = $input['informe_id'] ?? null;
    if (!$informeId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'informe_id es requerido']);
        exit();
    }
    
    // Obtener formato de envío (pdf o jpg)
    // Log detallado del input recibido
    error_log('[SEND_TO_PACS][INPUT] Input completo recibido: ' . json_encode($input, JSON_PRETTY_PRINT));
    error_log('[SEND_TO_PACS][INPUT] Campo format recibido: ' . ($input['format'] ?? 'NO RECIBIDO'));
    
    $format = strtolower($input['format'] ?? 'pdf');
    error_log('[SEND_TO_PACS][FORMAT] Formato después de strtolower: ' . $format);
    
    if (!in_array($format, ['pdf', 'jpg'])) {
        error_log('[SEND_TO_PACS][FORMAT] ❌ Formato inválido: ' . $format);
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Formato inválido. Use "pdf" o "jpg"']);
        exit();
    }
    
    error_log('[SEND_TO_PACS][FORMAT] ✅ Formato válido: ' . $format . ' (tipo: ' . ($format === 'jpg' ? 'PNG/Imagen' : 'PDF') . ')');
    
    // Verificar si se debe verificar duplicados
    $checkDuplicates = isset($input['check_duplicates']) ? (bool)$input['check_duplicates'] : true;
    
    // Conectar a la base de datos
    $db = getDBConnection();
    
    // Verificar permisos y obtener informe
    $userPermissions = json_decode($userData['permisos'] ?? '[]', true);
    
    // Verificar permiso específico para enviar a PACS
    $canSendToPacs = in_array('all', $userPermissions) || in_array('enviar_pacs', $userPermissions);
    
    if (!$canSendToPacs) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'No tienes permiso para enviar informes a PACS']);
        exit();
    }
    
    $canManageAllReports = in_array('all', $userPermissions) || in_array('gestionInformes', $userPermissions);
    
    if ($canManageAllReports) {
        $query = "SELECT i.*, u.nombre as usuario_nombre, u.apellido as usuario_apellido, 
                         u.email as usuario_email, u.matricula_profesional
                  FROM informes i
                  LEFT JOIN usuarios u ON i.usuario_id = u.id
                  WHERE i.id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$informeId]);
    } else {
        $query = "SELECT i.*, u.nombre as usuario_nombre, u.apellido as usuario_apellido, 
                         u.email as usuario_email, u.matricula_profesional
                  FROM informes i
                  LEFT JOIN usuarios u ON i.usuario_id = u.id
                  WHERE i.id = ? AND i.usuario_id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$informeId, $userData['id']]);
    }
    
    $informe = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$informe) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Informe no encontrado']);
        exit();
    }
    
    $responseData = stps_sendInformeToPacsInternal($db, $informe, $input);
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($responseData);
    exit();

} catch (PDOException $e) {
    // Limpiar buffer de salida antes de enviar JSON
    ob_end_clean();
    error_log("Error de base de datos en send-to-pacs.php: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor',
        'error_code' => 'DATABASE_ERROR',
        'error_details' => error_reporting() & E_ALL ? $e->getMessage() : null
    ]);
    exit();
} catch (Exception $e) {
    // Limpiar buffer de salida antes de enviar JSON
    ob_end_clean();
    error_log("[SEND_TO_PACS][ERROR] " . $e->getMessage());
    error_log("[SEND_TO_PACS][TRACE] " . $e->getTraceAsString());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => 'SEND_TO_PACS_ERROR',
        'error_class' => get_class($e),
        'error_file' => $e->getFile(),
        'error_line' => $e->getLine(),
        'error_details' => error_reporting() & E_ALL ? $e->getTraceAsString() : null
    ]);
    exit();
}
