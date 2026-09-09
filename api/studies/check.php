<?php
/**
 * API Endpoint para verificar si un estudio existe
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

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

require_once '../../classes/User.php';
require_once '../../config/database.php';

try {
    // Validar sesión
    $sessionToken = $_SERVER['HTTP_AUTHORIZATION'] ?? $_GET['token'] ?? null;
    if ($sessionToken && strpos($sessionToken, 'Bearer ') === 0) {
        $sessionToken = substr($sessionToken, 7);
    }
    
    if (!$sessionToken) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Token de sesión requerido']);
        exit();
    }
    
    $user = new User();
    $userData = $user->validateSession($sessionToken);
    
    if (!$userData) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sesión inválida']);
        exit();
    }
    
    // Obtener identificador del estudio desde query string
    $studyId = $_GET['studyId'] ?? null;
    
    if (!$studyId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Se requiere studyId']);
        exit();
    }
    
    // Conectar a la base de datos
    $db = getDBConnection();

    // id es INT autoincrement — no comparar UUID de Orthanc contra id (provoca error SQL).
    $isNumericId = is_numeric($studyId) && strpos((string)$studyId, '-') === false;

    if ($isNumericId) {
        $query = "
            SELECT id, orthanc_study_id, study_instance_uid, patient_id_pacs, patient_name_pacs,
                   modality, study_description, study_date
            FROM estudios
            WHERE id = ?
               OR orthanc_study_id = ?
               OR study_instance_uid = ?
            LIMIT 1
        ";
        $params = [(int)$studyId, $studyId, $studyId];
    } else {
        $query = "
            SELECT id, orthanc_study_id, study_instance_uid, patient_id_pacs, patient_name_pacs,
                   modality, study_description, study_date
            FROM estudios
            WHERE orthanc_study_id = ?
               OR study_instance_uid = ?
            LIMIT 1
        ";
        $params = [$studyId, $studyId];
    }

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $study = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($study) {
        echo json_encode([
            'success' => true,
            'exists' => true,
            'message' => 'Estudio encontrado',
            'studyData' => [
                'id' => $study['id'],
                'orthanc_study_id' => $study['orthanc_study_id'],
                'study_instance_uid' => $study['study_instance_uid'],
                'patient_id' => $study['patient_id_pacs'],
                'patient_name' => $study['patient_name_pacs'],
                'modality' => $study['modality'],
                'study_description' => $study['study_description'],
                'study_date' => $study['study_date']
            ]
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'exists' => false,
            'message' => 'Estudio no encontrado',
            'studyData' => null
        ]);
    }
    
} catch (Exception $e) {
    error_log('Error en api/studies/check.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error al verificar el estudio: ' . $e->getMessage()
    ]);
}
?>
