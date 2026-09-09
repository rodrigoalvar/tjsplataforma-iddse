<?php
/**
 * API REST para gestión de Nodos PACS
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Endpoints:
 * GET    /api/pacs-nodes-manager/nodes.php              # Listar nodos
 * POST   /api/pacs-nodes-manager/nodes.php              # Crear nodo
 * GET    /api/pacs-nodes-manager/nodes.php?id={id}       # Obtener nodo
 * PUT    /api/pacs-nodes-manager/nodes.php?id={id}      # Actualizar nodo
 * DELETE /api/pacs-nodes-manager/nodes.php?id={id}      # Eliminar nodo
 */

// Configurar manejo de errores antes de cualquier salida
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Registrar función para capturar errores fatales
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log('[PACS_NODES][NODES] Error fatal: ' . $error['message'] . ' en ' . $error['file'] . ':' . $error['line']);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Error fatal del servidor',
                'details' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line']
            ], JSON_UNESCAPED_UNICODE);
        }
    }
});

try {
    require_once __DIR__ . '/_auth.php';
    require_once __DIR__ . '/../../../config/database.php';
    
    // Verificar autenticación y permisos
    $user = requirePacsNodesAuth('pacs_nodes_manager');
    
    // Obtener conexión a BD
    $db = getDBConnection();
    
    if (!$db) {
        sendErrorResponse('No se pudo conectar a la base de datos', 500);
    }
    
    // Cargar PacsNodeConfig solo cuando sea necesario (para evitar errores si Orthanc no está disponible)
    // Intentar cargar, pero no fallar si no está disponible
    if (file_exists(__DIR__ . '/../PacsNodeConfig.php')) {
        try {
            require_once __DIR__ . '/../PacsNodeConfig.php';
        } catch (Exception $e) {
            error_log("Warning: No se pudo cargar PacsNodeConfig: " . $e->getMessage());
        }
    }
    
    $method = $_SERVER['REQUEST_METHOD'];
    $nodeId = $_GET['id'] ?? null;
    
    switch ($method) {
        case 'GET':
            if ($nodeId) {
                handleGetNode($db, $nodeId);
            } else {
                handleListNodes($db);
            }
            break;
            
        case 'POST':
            handleCreateNode($db, $user);
            break;
            
        case 'PUT':
            if (!$nodeId) {
                sendErrorResponse('ID de nodo requerido', 400);
            }
            handleUpdateNode($db, $nodeId, $user);
            break;
            
        case 'DELETE':
            if (!$nodeId) {
                sendErrorResponse('ID de nodo requerido', 400);
            }
            handleDeleteNode($db, $nodeId);
            break;
            
        default:
            sendErrorResponse('Método no permitido', 405);
    }
    
} catch (Exception $e) {
    error_log("Error en nodes.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    error_log("Fatal error en nodes.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error fatal: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Listar todos los nodos
 */
function handleListNodes($db) {
    // Verificar si la tabla existe
    $checkTable = $db->query("SHOW TABLES LIKE 'pacs_nodes'");
    if ($checkTable->rowCount() === 0) {
        sendErrorResponse('La tabla pacs_nodes no existe. Ejecute el script de instalación: modules/pacs-nodes-manager/database/install.sql', 500);
    }
    
    // Verificar si los campos nuevos existen
    $columns = [];
    $stmt = $db->query("SHOW COLUMNS FROM pacs_nodes");
    $existingColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $selectFields = [
        'id', 'name', 'node_type', 'aet', 'host', 'port',
        'dicomweb_url', 'dicomweb_auth_type',
        'description', 'is_active',
        'last_ping', 'last_ping_status', 'last_ping_latency_ms',
        'cache_size_mb', 'cache_studies_count', 'last_sync',
        'created_at', 'updated_at', 'created_by'
    ];
    
    // Agregar campos nuevos solo si existen
    $newFields = ['local_aet', 'allow_find', 'allow_move', 'allow_get', 'allow_store', 'allow_transcoding',
                  'manufacturer', 'timeout', 'use_dicom_tls', 'orthanc_node_id',
                  'find_query_mode',
                  'detected_manufacturer', 'detected_manufacturer_model', 'detected_software_version',
                  'detected_station_name', 'detected_institution_name', 'detected_institution_address',
                  'implementation_detected_at', 'implementation_class_uid', 'implementation_version_name',
                  'implementation_detected_from'];
    foreach ($newFields as $field) {
        if (in_array($field, $existingColumns)) {
            $selectFields[] = $field;
        }
    }
    
    try {
        $sql = "SELECT " . implode(', ', $selectFields) . " FROM pacs_nodes ORDER BY name ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // No retornar contraseñas
        foreach ($nodes as &$node) {
            unset($node['password']);
            unset($node['dicomweb_password']);
            // Mantener username para mostrar, pero no contraseñas
        }
        
        sendSuccessResponse($nodes);
    } catch (Exception $e) {
        error_log("Error en handleListNodes: " . $e->getMessage());
        error_log("SQL: " . ($sql ?? 'N/A'));
        sendErrorResponse('Error al listar nodos: ' . $e->getMessage(), 500);
    }
}

/**
 * Obtener un nodo específico
 */
function handleGetNode($db, $nodeId) {
    // Verificar si local_aet existe en la tabla
    $checkLocalAet = $db->query("SHOW COLUMNS FROM pacs_nodes LIKE 'local_aet'");
    $hasLocalAet = $checkLocalAet->rowCount() > 0;
    
    $selectFields = 'id, name, node_type, aet, host, port, dicomweb_url, dicomweb_auth_type, username, password, dicomweb_username, dicomweb_password, description, is_active, last_ping, last_ping_status, last_ping_latency_ms, cache_size_mb, cache_studies_count, last_sync, created_at, updated_at, created_by';
    if ($hasLocalAet) {
        $selectFields = str_replace('aet, host', 'aet, local_aet, host', $selectFields);
    }
    $checkFindMode = $db->query("SHOW COLUMNS FROM pacs_nodes LIKE 'find_query_mode'");
    if ($checkFindMode->rowCount() > 0) {
        $selectFields = str_replace('node_type, aet', 'node_type, find_query_mode, aet', $selectFields);
    }
    
    $stmt = $db->prepare("
        SELECT $selectFields
        FROM pacs_nodes
        WHERE id = ?
    ");
    
    $stmt->execute([$nodeId]);
    $node = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$node) {
        sendErrorResponse('Nodo no encontrado', 404);
    }
    
    // No retornar contraseñas
    unset($node['password']);
    unset($node['dicomweb_password']);
    unset($node['username']);
    unset($node['dicomweb_username']);
    
    sendSuccessResponse($node);
}

/**
 * Crear un nuevo nodo
 */
function handleCreateNode($db, $user) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        sendErrorResponse('Datos JSON inválidos', 400);
    }
    
    // Validar datos requeridos
    if (empty($data['name'])) {
        sendErrorResponse('Nombre del nodo es requerido', 400);
    }
    
    if (empty($data['node_type'])) {
        sendErrorResponse('Tipo de nodo es requerido', 400);
    }
    
    // Validación avanzada con PacsNodeConfig si está disponible
    if (class_exists('PacsNodeConfig')) {
        try {
            $config = new PacsNodeConfig($db);
            $validation = $config->validateNodeConfig($data);
            
            if (!$validation['valid']) {
                sendErrorResponse('Configuración inválida: ' . implode(', ', $validation['errors']), 400);
            }
        } catch (Exception $e) {
            error_log("Error en validación avanzada de nodo: " . $e->getMessage());
            // Continuar con validación básica ya realizada
        }
    }
    
    // Encriptar contraseñas si existen
    if (!empty($data['password'])) {
        $data['password'] = password_hash($data['password'], PASSWORD_BCRYPT);
    }
    
    if (!empty($data['dicomweb_password'])) {
        $data['dicomweb_password'] = password_hash($data['dicomweb_password'], PASSWORD_BCRYPT);
    }
    
    // Verificar qué campos existen en la tabla
    $stmt = $db->query("SHOW COLUMNS FROM pacs_nodes");
    $existingColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Construir query dinámicamente según campos existentes
    $insertFields = ['name', 'node_type', 'aet', 'host', 'port',
                     'dicomweb_url', 'dicomweb_username', 'dicomweb_password', 'dicomweb_auth_type',
                     'username', 'password',
                     'description', 'is_active', 'created_by'];
    
    $insertValues = [];
    $params = [
        ':name' => $data['name'],
        ':node_type' => $data['node_type'],
        ':aet' => $data['aet'] ?? null,
        ':host' => $data['host'] ?? null,
        ':port' => $data['port'] ?? null,
        ':dicomweb_url' => $data['dicomweb_url'] ?? null,
        ':dicomweb_username' => $data['dicomweb_username'] ?? null,
        ':dicomweb_password' => $data['dicomweb_password'] ?? null,
        ':dicomweb_auth_type' => $data['dicomweb_auth_type'] ?? 'none',
        ':username' => $data['username'] ?? null,
        ':password' => $data['password'] ?? null,
        ':description' => $data['description'] ?? null,
        ':is_active' => $data['is_active'] ?? 1,
        ':created_by' => $user['id']
    ];
    
    // Agregar campos nuevos solo si existen
    $newFieldsMap = [
        'local_aet' => $data['local_aet'] ?? null,
        'find_query_mode' => isset($data['find_query_mode']) && $data['find_query_mode'] !== ''
            ? $data['find_query_mode'] : null,
        'allow_find' => $data['allow_find'] ?? 1,
        'allow_move' => $data['allow_move'] ?? 1,
        'allow_get' => $data['allow_get'] ?? 1,
        'allow_store' => $data['allow_store'] ?? 0,
        'allow_transcoding' => $data['allow_transcoding'] ?? 0,
        'manufacturer' => $data['manufacturer'] ?? 'Generic',
        'timeout' => $data['timeout'] ?? 30,
        'use_dicom_tls' => $data['use_dicom_tls'] ?? 0
    ];
    
    foreach ($newFieldsMap as $field => $value) {
        if (in_array($field, $existingColumns)) {
            $insertFields[] = $field;
            $params[':' . $field] = $value;
        }
    }
    
    $fieldsStr = implode(', ', $insertFields);
    $valuesStr = ':' . implode(', :', $insertFields);
    
    $sql = "INSERT INTO pacs_nodes ($fieldsStr) VALUES ($valuesStr)";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    
    $nodeId = $db->lastInsertId();
    
    // Sincronizar con Orthanc si es nodo DIMSE
    $node = $data;
    $node['id'] = $nodeId;
    
    // Obtener nodo creado antes de sincronizar
    $stmt = $db->prepare("SELECT * FROM pacs_nodes WHERE id = ?");
    $stmt->execute([$nodeId]);
    $createdNode = $stmt->fetch(PDO::FETCH_ASSOC);
    $node = array_merge($createdNode, $data);
    
    // Sincronizar con Orthanc si es nodo DIMSE (opcional, no crítico)
    // Solo intentar si tenemos la clase configurada y los datos necesarios
    if (class_exists('PacsNodeConfig')) {
        try {
            if (($node['node_type'] === 'dimse' || $node['node_type'] === 'hybrid') && 
                !empty($node['aet']) && !empty($node['host']) && !empty($node['port'])) {
                $config = new PacsNodeConfig($db);
                $syncResult = $config->syncNodeWithOrthanc($node);
                if ($syncResult['success'] && isset($syncResult['orthanc_id'])) {
                    // Guardar el ID de Orthanc
                    $updateStmt = $db->prepare("UPDATE pacs_nodes SET orthanc_node_id = ?, last_sync = NOW() WHERE id = ?");
                    $updateStmt->execute([$syncResult['orthanc_id'], $nodeId]);
                    $createdNode['orthanc_node_id'] = $syncResult['orthanc_id'];
                } else {
                    error_log("Warning: No se pudo sincronizar nodo con Orthanc: " . ($syncResult['message'] ?? ''));
                }
            }
        } catch (Exception $e) {
            error_log("Error sincronizando nodo con Orthanc: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            // No fallar la creación del nodo si falla la sincronización
        }
    }
    
    // No retornar contraseñas
    unset($createdNode['password']);
    unset($createdNode['dicomweb_password']);
    
    sendSuccessResponse($createdNode, 'Nodo creado exitosamente');
}

/**
 * Actualizar un nodo existente
 */
function handleUpdateNode($db, $nodeId, $user) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        sendErrorResponse('Datos JSON inválidos', 400);
    }
    
    // Verificar que el nodo existe
    $stmt = $db->prepare("SELECT * FROM pacs_nodes WHERE id = ?");
    $stmt->execute([$nodeId]);
    $existingNode = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$existingNode) {
        sendErrorResponse('Nodo no encontrado', 404);
    }
    
    // Validar configuración
    $config = new PacsNodeConfig($db);
    $validation = $config->validateNodeConfig(array_merge($existingNode, $data));
    
    if (!$validation['valid']) {
        sendErrorResponse('Configuración inválida: ' . implode(', ', $validation['errors']), 400);
    }
    
    // Encriptar contraseñas si se proporcionan nuevas
    if (!empty($data['password']) && $data['password'] !== $existingNode['password']) {
        $data['password'] = password_hash($data['password'], PASSWORD_BCRYPT);
    } else {
        unset($data['password']); // Mantener la existente
    }
    
    if (!empty($data['dicomweb_password']) && $data['dicomweb_password'] !== $existingNode['dicomweb_password']) {
        $data['dicomweb_password'] = password_hash($data['dicomweb_password'], PASSWORD_BCRYPT);
    } else {
        unset($data['dicomweb_password']); // Mantener la existente
    }
    
    // Construir query de actualización dinámicamente
    $fields = [];
    $values = [':id' => $nodeId];
    
    // Verificar qué campos existen en la tabla
    $stmt = $db->query("SHOW COLUMNS FROM pacs_nodes");
    $existingColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $allowedFields = [
        'name', 'node_type', 'aet', 'host', 'port',
        'dicomweb_url', 'dicomweb_username', 'dicomweb_password', 'dicomweb_auth_type',
        'username', 'password',
        'description', 'is_active',
        'local_aet', 'find_query_mode', 'allow_find', 'allow_move', 'allow_get', 'allow_store', 'allow_transcoding',
        'manufacturer', 'timeout', 'use_dicom_tls'
    ];
    
    foreach ($allowedFields as $field) {
        if (!in_array($field, $existingColumns)) {
            continue;
        }
        if ($field === 'find_query_mode') {
            if (!array_key_exists('find_query_mode', $data)) {
                continue;
            }
            $fq = $data['find_query_mode'];
            $fields[] = 'find_query_mode = :find_query_mode';
            $values[':find_query_mode'] = ($fq === '' || $fq === null) ? null : $fq;
            continue;
        }
        if (isset($data[$field])) {
            $fields[] = "$field = :$field";
            $values[":$field"] = $data[$field];
        }
    }
    
    if (empty($fields)) {
        sendErrorResponse('No hay campos para actualizar', 400);
    }
    
    $sql = "UPDATE pacs_nodes SET " . implode(', ', $fields) . " WHERE id = :id";
    $stmt = $db->prepare($sql);
    $stmt->execute($values);
    
    // Obtener nodo actualizado antes de sincronizar
    $stmt = $db->prepare("SELECT * FROM pacs_nodes WHERE id = ?");
    $stmt->execute([$nodeId]);
    $updatedNode = $stmt->fetch(PDO::FETCH_ASSOC);
    $updatedNode = array_merge($updatedNode, $data);
    
    // Sincronizar con Orthanc si es nodo DIMSE
    try {
        if ($updatedNode['node_type'] === 'dimse' || $updatedNode['node_type'] === 'hybrid') {
            $syncResult = $config->syncNodeWithOrthanc($updatedNode);
            if ($syncResult['success'] && isset($syncResult['orthanc_id'])) {
                // Actualizar el ID de Orthanc
                $updateStmt = $db->prepare("UPDATE pacs_nodes SET orthanc_node_id = ?, last_sync = NOW() WHERE id = ?");
                $updateStmt->execute([$syncResult['orthanc_id'], $nodeId]);
                $updatedNode['orthanc_node_id'] = $syncResult['orthanc_id'];
            } else {
                error_log("Warning: No se pudo sincronizar nodo con Orthanc: " . ($syncResult['message'] ?? ''));
            }
        }
    } catch (Exception $e) {
        error_log("Error sincronizando nodo con Orthanc: " . $e->getMessage());
    }
    
    // Obtener nodo actualizado final
    $stmt = $db->prepare("SELECT * FROM pacs_nodes WHERE id = ?");
    $stmt->execute([$nodeId]);
    $updatedNode = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // No retornar contraseñas
    unset($updatedNode['password']);
    unset($updatedNode['dicomweb_password']);
    
    sendSuccessResponse($updatedNode, 'Nodo actualizado exitosamente');
}

/**
 * Eliminar un nodo
 */
function handleDeleteNode($db, $nodeId) {
    // Verificar que el nodo existe
    $stmt = $db->prepare("SELECT * FROM pacs_nodes WHERE id = ?");
    $stmt->execute([$nodeId]);
    $node = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$node) {
        sendErrorResponse('Nodo no encontrado', 404);
    }
    
    // Eliminar de Orthanc si es nodo DIMSE
    if ($node['node_type'] === 'dimse' || $node['node_type'] === 'hybrid') {
        $orthancNodeId = $node['orthanc_node_id'] ?? ('NODE_' . $nodeId);
        try {
            $config = new PacsNodeConfig($db);
            $config->removeNodeFromOrthanc($orthancNodeId);
        } catch (Exception $e) {
            error_log("Error eliminando nodo de Orthanc: " . $e->getMessage());
            // Continuar con la eliminación aunque falle Orthanc
        }
    }
    
    // Eliminar nodo (las foreign keys eliminarán registros relacionados)
    $stmt = $db->prepare("DELETE FROM pacs_nodes WHERE id = ?");
    $stmt->execute([$nodeId]);
    
    sendSuccessResponse(null, 'Nodo eliminado exitosamente');
}
