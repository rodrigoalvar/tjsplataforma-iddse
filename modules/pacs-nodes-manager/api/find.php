<?php
/**
 * API para búsqueda C-FIND / QIDO-RS en nodos PACS
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Endpoints:
 * GET  /api/pacs-nodes-manager/find.php?node_id={id}&PatientID=123&...
 * POST /api/pacs-nodes-manager/find.php (query avanzada)
 */

// Habilitar output buffering para evitar headers grandes
ob_start();

// Habilitar reporte de errores pero no mostrarlos en output
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Capturar errores fatales para devolver JSON válido
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo encodeApiJson([
            'success' => false,
            'error' => 'Error interno del servidor',
            'detail' => 'Error fatal: ' . $error['message']
        ]);
    }
});

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../PacsNodeClient.php';
require_once __DIR__ . '/../PacsNodeConfig.php';

function isPacsNodesDebugEnabled() {
    $raw = strtolower(trim((string)getenv('PACS_NODES_DEBUG')));
    return in_array($raw, ['1', 'true', 'yes', 'on'], true);
}

try {
    // Verificar autenticación
    $user = requirePacsNodesAuth('pacs_nodes_manager');
    
    // Obtener conexión a BD
    $db = getDBConnection();
    
    if (!$db) {
        sendErrorResponse('No se pudo conectar a la base de datos', 500);
    }
    
    $method = $_SERVER['REQUEST_METHOD'];
    
    $skipCache = false;
    if ($method === 'GET') {
        // Búsqueda rápida desde query string
        $nodeId = $_GET['node_id'] ?? null;
        
        if (!$nodeId) {
            sendErrorResponse('node_id es requerido', 400);
        }
        
        // Construir query desde parámetros GET
        $query = [
            'Level' => $_GET['Level'] ?? 'Study',
            'Query' => []
        ];
        
        if (isset($_GET['PatientID'])) {
            $query['Query']['PatientID'] = $_GET['PatientID'];
        }
        if (isset($_GET['PatientName'])) {
            $query['Query']['PatientName'] = $_GET['PatientName'];
        }
        if (isset($_GET['StudyDate'])) {
            $query['Query']['StudyDate'] = $_GET['StudyDate'];
        }
        if (isset($_GET['AccessionNumber'])) {
            $query['Query']['AccessionNumber'] = $_GET['AccessionNumber'];
        }
        if (isset($_GET['Modalities'])) {
            $query['Query']['ModalitiesInStudy'] = $_GET['Modalities'];
        }
        if (isset($_GET['limit'])) {
            $query['limit'] = (int)$_GET['limit'];
        }
        if (isset($_GET['no_cache'])) {
            $rawNoCache = strtolower(trim((string)$_GET['no_cache']));
            $skipCache = in_array($rawNoCache, ['1', 'true', 'yes', 'on'], true);
        }
        
    } else {
        // Búsqueda avanzada desde POST
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            sendErrorResponse('Datos JSON inválidos', 400);
        }
        
        $nodeId = $data['node_id'] ?? null;
        
        if (!$nodeId) {
            sendErrorResponse('node_id es requerido', 400);
        }
        
        $query = $data['query'] ?? [];
        $skipCache = !empty($data['no_cache']) || !empty($data['force_refresh']);
    }
    
    // Obtener nodo
    $stmt = $db->prepare("SELECT * FROM pacs_nodes WHERE id = ?");
    $stmt->execute([$nodeId]);
    $node = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$node) {
        sendErrorResponse('Nodo no encontrado', 404);
    }
    
    if (!$node['is_active']) {
        sendErrorResponse('Nodo inactivo', 400);
    }
    
    // Si es nodo DIMSE y no tiene orthanc_node_id, intentar registrarlo en Orthanc primero
    if (($node['node_type'] === 'dimse' || $node['node_type'] === 'hybrid') 
        && empty($node['orthanc_node_id'])
        && !empty($node['aet']) && !empty($node['host']) && !empty($node['port'])) {
        try {
            $nodeConfig = new PacsNodeConfig($db);
            $syncResult = $nodeConfig->syncNodeWithOrthanc($node);
            if ($syncResult['success'] && !empty($syncResult['orthanc_id'])) {
                $db->prepare("UPDATE pacs_nodes SET orthanc_node_id = ?, last_sync = NOW() WHERE id = ?")
                   ->execute([$syncResult['orthanc_id'], $nodeId]);
                $node['orthanc_node_id'] = $syncResult['orthanc_id'];
            }
        } catch (Exception $e) {
            error_log("[PACS_NODES] Auto-sync fallido para nodo $nodeId: " . $e->getMessage());
            // Continuar; se intentará con el AET directamente
        }
    }

    // Verificar cache (salteable con no_cache/force_refresh)
    $queryJson = json_encode($query, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($queryJson === false) {
        error_log('[FIND] No se pudo serializar query; usando hash de fallback');
        $queryJson = '{}';
    }
    $queryHash = md5($queryJson);
    if (!$skipCache) {
        $cacheStmt = $db->prepare("
            SELECT * FROM pacs_node_queries
            WHERE node_id = ? AND query_hash = ? AND expires_at > NOW()
        ");
        $cacheStmt->execute([$nodeId, $queryHash]);
        $cachedQuery = $cacheStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($cachedQuery && !empty($cachedQuery['results_data'])) {
            // Retornar resultados cacheados
            sendSuccessResponse([
                'data' => json_decode($cachedQuery['results_data'], true),
                'count' => $cachedQuery['results_count'],
                'cached' => true
            ]);
        }
    }
    
    // Ejecutar búsqueda
    $client = new PacsNodeClient($db);
    $results = $client->executeCFind($node, $query);
    
    // Log de diagnóstico opcional
    if (isPacsNodesDebugEnabled()) {
        error_log("[PACS_NODES] C-FIND results count: " . count($results));
    }
    if (isPacsNodesDebugEnabled() && !empty($results)) {
        $sample = json_encode($results[0], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($sample === false) {
            $sample = '[sample no serializable]';
        }
        // Limitar tamaño del log para evitar headers grandes
        if (strlen($sample) > 500) {
            $sample = substr($sample, 0, 500) . '... (truncated)';
        }
        error_log("[PACS_NODES] First result sample: " . $sample);
        
        // Si tenemos resultados, intentar detectar información de implementación del PACS
        // Esto se hace en segundo plano para no afectar la respuesta
        try {
            // Verificar si las columnas de detección existen
            $checkColumns = $db->query("SHOW COLUMNS FROM pacs_nodes LIKE 'detected_%'");
            $columnsExist = $checkColumns->rowCount() > 0;
            
            if ($columnsExist && ($node['node_type'] === 'dimse' || $node['node_type'] === 'hybrid')) {
                // Extraer información de implementación del primer resultado
                $firstResult = $results[0];
                $detectedInfo = [
                    'manufacturer' => $firstResult['Manufacturer'] ?? '',
                    'manufacturer_model' => $firstResult['ManufacturerModelName'] ?? '',
                    'software_version' => $firstResult['SoftwareVersion'] ?? '',
                    'station_name' => $firstResult['StationName'] ?? '',
                    'institution_name' => $firstResult['InstitutionName'] ?? '',
                    'institution_address' => $firstResult['InstitutionAddress'] ?? ''
                ];
                
                // Si tenemos al menos manufacturer, actualizar BD
                if (!empty($detectedInfo['manufacturer'])) {
                    $updateStmt = $db->prepare("
                        UPDATE pacs_nodes 
                        SET detected_manufacturer = ?,
                            detected_manufacturer_model = ?,
                            detected_software_version = ?,
                            detected_station_name = ?,
                            detected_institution_name = ?,
                            detected_institution_address = ?,
                            implementation_detected_at = NOW()
                        WHERE id = ?
                    ");
                    $updateStmt->execute([
                        !empty($detectedInfo['manufacturer']) ? $detectedInfo['manufacturer'] : null,
                        !empty($detectedInfo['manufacturer_model']) ? $detectedInfo['manufacturer_model'] : null,
                        !empty($detectedInfo['software_version']) ? $detectedInfo['software_version'] : null,
                        !empty($detectedInfo['station_name']) ? $detectedInfo['station_name'] : null,
                        !empty($detectedInfo['institution_name']) ? $detectedInfo['institution_name'] : null,
                        !empty($detectedInfo['institution_address']) ? $detectedInfo['institution_address'] : null,
                        $nodeId
                    ]);
                    error_log("[PACS_NODES] Información de implementación actualizada automáticamente desde búsqueda para nodo $nodeId");
                }
            }
        } catch (Exception $e) {
            // No fallar la búsqueda si la detección falla
            error_log("[PACS_NODES] Error actualizando info de implementación desde búsqueda: " . $e->getMessage());
        }
    }
    
    // Guardar en cache
    $cacheTTL = 5 * 60; // 5 minutos
    $insertCacheStmt = $db->prepare("
        INSERT INTO pacs_node_queries 
        (node_id, query_hash, query_params, results_count, results_data, expires_at)
        VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
        ON DUPLICATE KEY UPDATE
            results_count = VALUES(results_count),
            results_data = VALUES(results_data),
            expires_at = VALUES(expires_at),
            executed_at = NOW()
    ");
    
    $resultsJson = json_encode($results, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($resultsJson === false) {
        error_log('[FIND] No se pudo serializar results para cache; guardando []');
        $resultsJson = '[]';
    }

    $insertCacheStmt->execute([
        $nodeId,
        $queryHash,
        $queryJson,
        count($results),
        $resultsJson,
        $cacheTTL
    ]);
    
    // Actualizar estadísticas
    $statsStmt = $db->prepare("
        INSERT INTO pacs_node_statistics (node_id, date, queries_count)
        VALUES (?, CURDATE(), 1)
        ON DUPLICATE KEY UPDATE queries_count = queries_count + 1
    ");
    $statsStmt->execute([$nodeId]);
    
    // Limpiar cualquier output previo antes de enviar JSON
    ob_clean();
    
    sendSuccessResponse([
        'data' => $results,
        'count' => count($results),
        'cached' => false,
        'cache_skipped' => $skipCache
    ]);
    
} catch (Exception $e) {
    // Limpiar cualquier output previo antes de enviar JSON
    ob_clean();
    
    error_log("[FIND] Error: " . $e->getMessage());
    error_log("[FIND] Stack trace: " . $e->getTraceAsString());
    sendErrorResponse($e->getMessage(), 500);
} catch (Error $e) {
    // Limpiar cualquier output previo antes de enviar JSON
    ob_clean();
    
    error_log("[FIND] Fatal error: " . $e->getMessage());
    error_log("[FIND] Stack trace: " . $e->getTraceAsString());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo encodeApiJson([
        'success' => false,
        'error' => 'Error interno del servidor',
        'detail' => $e->getMessage()
    ]);
}
