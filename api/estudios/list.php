<?php
/**
 * API para listar estudios desde la base de datos
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * Usado por AI Informes
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../config/database.php';

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
        $sessionToken = $_COOKIE['session_token'] ?? null;
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
    
    $db = getDBConnection();
    
    if (!$db) {
        throw new Exception('No se pudo conectar a la base de datos');
    }
    
    // Verificar si la tabla estudios existe
    $tableCheck = $db->query("SHOW TABLES LIKE 'estudios'");
    if ($tableCheck->rowCount() === 0) {
        // Tabla no existe, retornar array vacío
        echo json_encode([
            'success' => true,
            'data' => [],
            'count' => 0,
            'message' => 'La tabla estudios no existe aún'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    // Obtener estudios de la base de datos
    // Limitar a los últimos 1000 estudios para evitar sobrecarga
    // Usar los nombres de columnas correctos de la tabla estudios
    try {
        $sql = "SELECT 
                    id,
                    patient_name_pacs as paciente_nombre,
                    patient_id_pacs as paciente_id,
                    modality as modalidad,
                    study_description as descripcion,
                    study_date as fecha_estudio,
                    orthanc_study_id,
                    study_instance_uid,
                    accession_number
                FROM estudios 
                ORDER BY study_date DESC, id DESC 
                LIMIT 1000";
        
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $estudios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Si hay error en la consulta (por ejemplo, columnas no existen), intentar con columnas alternativas
        error_log("Error en consulta principal, intentando alternativa: " . $e->getMessage());
        try {
            $sql = "SELECT 
                        id,
                        patient_name_pacs,
                        patient_id_pacs,
                        modality,
                        study_description,
                        study_date,
                        orthanc_study_id,
                        study_instance_uid,
                        accession_number
                    FROM estudios 
                    ORDER BY study_date DESC, id DESC 
                    LIMIT 1000";
            
            $stmt = $db->prepare($sql);
            $stmt->execute();
            $estudios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e2) {
            // Si aún falla, retornar array vacío para que la interfaz se muestre
            error_log("Error en consulta alternativa: " . $e2->getMessage());
            $estudios = [];
        }
    }
    
    // Formatear datos para el frontend
    $formattedStudies = [];
    foreach ($estudios as $estudio) {
        $formattedStudies[] = [
            'id' => $estudio['id'] ?? 0,
            'paciente_nombre' => $estudio['paciente_nombre'] ?? $estudio['patient_name_pacs'] ?? 'Sin nombre',
            'paciente_id' => $estudio['paciente_id'] ?? $estudio['patient_id_pacs'] ?? '',
            'modalidad' => $estudio['modalidad'] ?? $estudio['modality'] ?? '',
            'descripcion' => $estudio['descripcion'] ?? $estudio['study_description'] ?? '',
            'fecha_estudio' => $estudio['fecha_estudio'] ?? $estudio['study_date'] ?? null
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $formattedStudies,
        'count' => count($formattedStudies)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    error_log("Error PDO en estudios/list.php: " . $e->getMessage() . " | SQL: " . ($sql ?? 'N/A'));
    
    // Retornar success: true con array vacío para que la interfaz se muestre
    // El usuario podrá ver los modales y la estructura aunque no haya datos
    echo json_encode([
        'success' => true,
        'data' => [],
        'count' => 0,
        'message' => 'No se pudieron cargar los estudios. La interfaz está disponible para probar.',
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("Error en estudios/list.php: " . $e->getMessage());
    
    // Si es error de autenticación, retornar error 401
    if (strpos($e->getMessage(), 'autorización') !== false || 
        strpos($e->getMessage(), 'sesión') !== false ||
        strpos($e->getMessage(), 'Token') !== false) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    } else {
        // Para otros errores, retornar success: true con array vacío para mostrar interfaz
        echo json_encode([
            'success' => true,
            'data' => [],
            'count' => 0,
            'message' => 'No se pudieron cargar los estudios. La interfaz está disponible para probar.',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}
