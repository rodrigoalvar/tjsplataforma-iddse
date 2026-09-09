<?php
/**
 * API para detectar información de implementación del PACS
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Endpoint: POST /api/pacs-nodes-manager/detect-implementation.php?id={nodeId}
 * 
 * Estrategia combinada:
 * 1. C-ECHO para verificar conectividad
 * 2. C-FIND para obtener información de implementación desde objetos DICOM
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../PacsNodeClient.php';

try {
    // Verificar autenticación
    $user = requirePacsNodesAuth('pacs_nodes_manager');
    
    // Obtener ID del nodo
    $nodeId = $_GET['id'] ?? $_POST['id'] ?? null;
    
    if (!$nodeId) {
        sendErrorResponse('ID de nodo requerido', 400);
    }
    
    // Obtener conexión a BD
    $db = getDBConnection();
    
    if (!$db) {
        sendErrorResponse('No se pudo conectar a la base de datos', 500);
    }
    
    // Obtener nodo
    $stmt = $db->prepare("SELECT * FROM pacs_nodes WHERE id = ?");
    $stmt->execute([$nodeId]);
    $node = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$node) {
        sendErrorResponse('Nodo no encontrado', 404);
    }
    
    // Verificar que el nodo esté activo
    if (!$node['is_active']) {
        sendErrorResponse('El nodo está inactivo', 400);
    }
    
    // Obtener información de implementación
    $client = new PacsNodeClient($db);
    $info = $client->getNodeImplementationInfo($node);
    
    // Actualizar BD con información detectada (si se detectó algo o hay info de implementación)
    $hasInfo = $info['detected'] || !empty($info['implementation_class_uid']) || !empty($info['implementation_version_name']);
    
    if ($hasInfo) {
        // Verificar si las columnas existen antes de actualizar
        $checkColumns = $db->query("SHOW COLUMNS FROM pacs_nodes LIKE 'detected_%'");
        $columnsExist = $checkColumns->rowCount() > 0;
        
        // Verificar si existen columnas de Implementation UID/Version
        $checkImplColumns = $db->query("SHOW COLUMNS FROM pacs_nodes LIKE 'implementation_%'");
        $implColumnsExist = $checkImplColumns->rowCount() > 0;
        
        if ($columnsExist) {
            // Construir query dinámicamente según columnas disponibles
            $updateFields = [
                'detected_manufacturer = ?',
                'detected_manufacturer_model = ?',
                'detected_software_version = ?',
                'detected_station_name = ?',
                'detected_institution_name = ?',
                'detected_institution_address = ?',
                'implementation_detected_at = NOW()'
            ];
            $updateValues = [
                !empty($info['manufacturer']) ? $info['manufacturer'] : null,
                !empty($info['manufacturer_model']) ? $info['manufacturer_model'] : null,
                !empty($info['software_version']) ? $info['software_version'] : null,
                !empty($info['station_name']) ? $info['station_name'] : null,
                !empty($info['institution_name']) ? $info['institution_name'] : null,
                !empty($info['institution_address']) ? $info['institution_address'] : null
            ];
            
            // Agregar campos de Implementation UID/Version si existen
            if ($implColumnsExist) {
                $updateFields[] = 'implementation_class_uid = ?';
                $updateFields[] = 'implementation_version_name = ?';
                $updateFields[] = 'implementation_detected_from = ?';
                $updateValues[] = !empty($info['implementation_class_uid']) ? $info['implementation_class_uid'] : null;
                $updateValues[] = !empty($info['implementation_version_name']) ? $info['implementation_version_name'] : null;
                $updateValues[] = !empty($info['detected_from']) ? $info['detected_from'] : null;
            }
            
            $updateValues[] = $nodeId; // Para el WHERE
            
            $updateStmt = $db->prepare("
                UPDATE pacs_nodes 
                SET " . implode(', ', $updateFields) . "
                WHERE id = ?
            ");
            $updateStmt->execute($updateValues);
        }
    }
    
    // Preparar respuesta
    $response = [
        'detected' => $info['detected'] ?? false,
        'echo_success' => $info['echo_success'] ?? false,
        'manufacturer' => $info['manufacturer'] ?? '',
        'manufacturer_model' => $info['manufacturer_model'] ?? '',
        'software_version' => $info['software_version'] ?? '',
        'station_name' => $info['station_name'] ?? '',
        'institution_name' => $info['institution_name'] ?? '',
        'institution_address' => $info['institution_address'] ?? '',
        'implementation_class_uid' => $info['implementation_class_uid'] ?? '',
        'implementation_version_name' => $info['implementation_version_name'] ?? '',
        'detected_from' => $info['detected_from'] ?? 'dicom_objects'
    ];
    
    if (isset($info['error'])) {
        $response['error'] = $info['error'];
    }
    
    sendSuccessResponse($response);
    
} catch (Exception $e) {
    error_log("Error en detect-implementation.php: " . $e->getMessage());
    sendErrorResponse($e->getMessage(), 500);
}
