<?php
/**
 * API para test de conectividad con nodos PACS
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Endpoint: POST /api/pacs-nodes-manager/ping.php?id={nodeId}
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
require_once __DIR__ . '/../PacsNodeConfig.php';
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
    
    $result = null;
    
    // Si es nodo DIMSE, usar test de Orthanc (C-ECHO)
    if ($node['node_type'] === 'dimse' || $node['node_type'] === 'hybrid') {
        $config = new PacsNodeConfig($db);
        $orthancNodeId = $node['orthanc_node_id'] ?? ('NODE_' . $node['id']);
        $testResult = $config->testNodeConnection($orthancNodeId);
        
        $result = [
            'success' => $testResult['success'],
            'message' => $testResult['message'],
            'latency_ms' => $testResult['latency_ms'] ?? null,
            'status' => $testResult['success'] ? 'success' : 'failed'
        ];
        
        // Actualizar estado en BD
        $updateStmt = $db->prepare("
            UPDATE pacs_nodes 
            SET last_ping = NOW(),
                last_ping_status = ?,
                last_ping_latency_ms = ?
            WHERE id = ?
        ");
        $updateStmt->execute([
            $result['status'],
            $result['latency_ms'],
            $nodeId
        ]);
    } else {
        // Para nodos DICOMweb, usar test HTTP
        $client = new PacsNodeClient($db);
        $testResult = $client->testConnection($node);
        
        $result = [
            'success' => $testResult['success'] ?? false,
            'message' => $testResult['message'] ?? 'Test completado',
            'latency_ms' => $testResult['response_time_ms'] ?? null,
            'status' => $testResult['status'] ?? 'unknown'
        ];
        
        // Actualizar estado en BD
        $updateStmt = $db->prepare("
            UPDATE pacs_nodes 
            SET last_ping = NOW(),
                last_ping_status = ?,
                last_ping_latency_ms = ?
            WHERE id = ?
        ");
        
        $updateStmt->execute([
            $result['status'],
            $result['latency_ms'],
            $nodeId
        ]);
    }
    
    sendSuccessResponse($result);
    
} catch (Exception $e) {
    error_log("Error en ping.php: " . $e->getMessage());
    sendErrorResponse($e->getMessage(), 500);
}
