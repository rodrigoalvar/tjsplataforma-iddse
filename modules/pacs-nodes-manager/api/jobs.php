<?php
/**
 * API para monitoreo de jobs asincrónicos
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Endpoints:
 * GET    /api/pacs-nodes-manager/jobs.php              # Listar jobs
 * GET    /api/pacs-nodes-manager/jobs.php?id={id}      # Estado de job
 * DELETE /api/pacs-nodes-manager/jobs.php?id={id}      # Cancelar job
 */

// Configurar manejo de errores antes de cualquier salida
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Registrar función para capturar errores fatales
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log('[PACS_NODES][JOBS] Error fatal: ' . $error['message'] . ' en ' . $error['file'] . ':' . $error['line']);
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
    require_once __DIR__ . '/../PacsNodeClient.php';
    require_once __DIR__ . '/../lib/pacs_node_jobs_reconcile.php';
    require_once __DIR__ . '/../lib/cloner_order_helpers.php';
    
    // Verificar autenticación
    $user = requirePacsNodesAuth('pacs_nodes_manager');
    
    // Obtener conexión a BD
    $db = getDBConnection();
    
    if (!$db) {
        sendErrorResponse('No se pudo conectar a la base de datos', 500);
    }
    
    $method = $_SERVER['REQUEST_METHOD'];
    $jobId = $_GET['id'] ?? null;
    
    switch ($method) {
        case 'GET':
            if ($jobId) {
                handleGetJob($db, $jobId);
            } else {
                handleListJobs($db);
            }
            break;
            
        case 'DELETE':
            if (!$jobId) {
                sendErrorResponse('ID de job requerido', 400);
            }
            handleCancelJob($db, $jobId);
            break;
            
        default:
            sendErrorResponse('Método no permitido', 405);
    }
    
} catch (Exception $e) {
    error_log("Error en jobs.php: " . $e->getMessage());
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
    error_log("Fatal error en jobs.php: " . $e->getMessage());
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
 * Origen del C-MOVE para la UI: worker, cloner UI o retrieve directo (Cross Sync, etc.).
 * Usa la orden cloner enlazada por pacs_cloner_orders.pacs_node_job_id.
 */
function pacs_nodes_job_attach_retrieve_source(array &$job) {
    $oid = isset($job['cloner_order_id']) ? (int) $job['cloner_order_id'] : 0;
    $tt = isset($job['cloner_trigger_type']) ? strtolower(trim((string) $job['cloner_trigger_type'])) : '';
    if ($oid < 1) {
        $job['retrieve_source'] = 'direct';
        $job['retrieve_source_label'] = 'Directo';
        $job['retrieve_source_hint'] = 'Sin orden PACS Cloner: retrieve directo (Cross Sync, búsqueda por nodo, estudios, u otra API).';

        return;
    }
    if ($tt === 'worker') {
        $job['retrieve_source'] = 'worker';
        $job['retrieve_source_label'] = 'Worker';
        $job['retrieve_source_hint'] = 'PACS Cloner automático (política en modo automatic + cron).';

        return;
    }
    if ($tt === 'ui') {
        $job['retrieve_source'] = 'cloner_ui';
        $job['retrieve_source_label'] = 'Cloner UI';
        $job['retrieve_source_hint'] = 'Orden creada desde esta consola (Iniciar clonado / dispatch).';

        return;
    }
    if ($tt === 'scheduled') {
        $job['retrieve_source'] = 'scheduled';
        $job['retrieve_source_label'] = 'Programado';
        $job['retrieve_source_hint'] = 'Orden con disparador programado (reservado / futuro).';

        return;
    }
    $job['retrieve_source'] = 'cloner_other';
    $job['retrieve_source_label'] = 'Cloner';
    $job['retrieve_source_hint'] = 'Orden cloner (trigger: ' . ($tt !== '' ? $tt : '?') . ').';
}

/**
 * Listar todos los jobs
 */
function handleListJobs($db) {
    // Verificar si la tabla existe
    try {
        $checkTable = $db->query("SHOW TABLES LIKE 'pacs_node_jobs'");
        if ($checkTable->rowCount() === 0) {
            // Si no existe la tabla, retornar array vacío en lugar de error
            sendSuccessResponse([]);
            return;
        }
    } catch (Exception $e) {
        error_log("Error verificando tabla pacs_node_jobs: " . $e->getMessage());
        sendSuccessResponse([]);
        return;
    }
    
    $status = $_GET['status'] ?? null;
    $nodeId = $_GET['node_id'] ?? null;
    $limit = (int)($_GET['limit'] ?? 50);
    
    try {
        $sql = "
            SELECT 
                j.*,
                n.name as node_name,
                (SELECT o.id FROM pacs_cloner_orders o WHERE o.pacs_node_job_id = j.id ORDER BY o.id DESC LIMIT 1) AS cloner_order_id,
                (SELECT o.trigger_type FROM pacs_cloner_orders o WHERE o.pacs_node_job_id = j.id ORDER BY o.id DESC LIMIT 1) AS cloner_trigger_type
            FROM pacs_node_jobs j
            LEFT JOIN pacs_nodes n ON j.node_id = n.id
            WHERE 1=1
        ";
        
        $params = [];
        
        if ($status) {
            $sql .= " AND j.status = ?";
            $params[] = $status;
        }
        
        if ($nodeId) {
            $sql .= " AND j.node_id = ?";
            $params[] = $nodeId;
        }
        
        $sql .= " ORDER BY j.created_at DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error en handleListJobs: " . $e->getMessage());
        sendSuccessResponse([]);
        return;
    }
    
    // Decodificar JSON fields + enriquecer resumen de estudio para UI Jobs
    $client = null;
    $summaryCache = [];
    foreach ($jobs as &$job) {
        pacs_nodes_job_attach_retrieve_source($job);
        $job['study_instance_uids'] = json_decode($job['study_instance_uids'], true);
        $firstUid = (is_array($job['study_instance_uids'] ?? null) && !empty($job['study_instance_uids']))
            ? (string)$job['study_instance_uids'][0]
            : '';
        $job['study_context'] = [
            'study_uid' => $firstUid,
            'patient_name' => '',
            'patient_id' => '',
            'study_date' => '',
            'found' => false
        ];
        
        if ($firstUid === '') {
            continue;
        }
        
        if (!array_key_exists($firstUid, $summaryCache)) {
            try {
                if ($client === null) {
                    $client = new PacsNodeClient($db);
                }
                $summaryCache[$firstUid] = $client->getLocalStudySummary($firstUid);
            } catch (Exception $e) {
                error_log("[JOBS] Error obteniendo resumen de estudio $firstUid: " . $e->getMessage());
                $summaryCache[$firstUid] = ['found' => false, 'patient_name' => '', 'patient_id' => '', 'study_date' => ''];
            }
        }
        
        $sum = $summaryCache[$firstUid];
        if (is_array($sum)) {
            $job['study_context']['found'] = !empty($sum['found']);
            $job['study_context']['patient_name'] = (string)($sum['patient_name'] ?? '');
            $job['study_context']['patient_id'] = (string)($sum['patient_id'] ?? '');
            $job['study_context']['study_date'] = (string)($sum['study_date'] ?? '');
        }
    }
    
    sendSuccessResponse($jobs);
}

/**
 * Obtener estado de un job específico
 */
function handleGetJob($db, $jobId) {
    $stmt = $db->prepare("
        SELECT 
            j.*,
            n.name as node_name,
            n.node_type,
            (SELECT o.id FROM pacs_cloner_orders o WHERE o.pacs_node_job_id = j.id ORDER BY o.id DESC LIMIT 1) AS cloner_order_id,
            (SELECT o.trigger_type FROM pacs_cloner_orders o WHERE o.pacs_node_job_id = j.id ORDER BY o.id DESC LIMIT 1) AS cloner_trigger_type
        FROM pacs_node_jobs j
        LEFT JOIN pacs_nodes n ON j.node_id = n.id
        WHERE j.id = ?
    ");
    
    $stmt->execute([$jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$job) {
        sendErrorResponse('Job no encontrado', 404);
    }
    
    // Decodificar JSON
    $job['study_instance_uids'] = json_decode($job['study_instance_uids'], true);
    if (!is_array($job['study_instance_uids'])) {
        $job['study_instance_uids'] = [];
    }

    pacs_nodes_reconcile_job_state($db, $job);

    pacsClonerSyncOrdersFromJobs($db);

    // Tras sincronizar órdenes, el enlace orden↔job puede actualizarse
    $stmt2 = $db->prepare("
        SELECT
            (SELECT o.id FROM pacs_cloner_orders o WHERE o.pacs_node_job_id = j.id ORDER BY o.id DESC LIMIT 1) AS cloner_order_id,
            (SELECT o.trigger_type FROM pacs_cloner_orders o WHERE o.pacs_node_job_id = j.id ORDER BY o.id DESC LIMIT 1) AS cloner_trigger_type
        FROM pacs_node_jobs j WHERE j.id = ?
    ");
    $stmt2->execute([$jobId]);
    $extra = $stmt2->fetch(PDO::FETCH_ASSOC);
    if (is_array($extra)) {
        $job['cloner_order_id'] = $extra['cloner_order_id'];
        $job['cloner_trigger_type'] = $extra['cloner_trigger_type'];
    }

    pacs_nodes_job_attach_retrieve_source($job);

    sendSuccessResponse($job);
}

/**
 * Cancelar un job
 */
function handleCancelJob($db, $jobId) {
    $stmt = $db->prepare("SELECT * FROM pacs_node_jobs WHERE id = ?");
    $stmt->execute([$jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$job) {
        sendErrorResponse('Job no encontrado', 404);
    }
    
    if ($job['status'] === 'success' || $job['status'] === 'failed' || $job['status'] === 'cancelled') {
        sendErrorResponse('Job ya está finalizado', 400);
    }
    
    // Intentar cancelar en Orthanc si tiene orthanc_job_id
    if (!empty($job['orthanc_job_id'])) {
        try {
            $client = new PacsNodeClient($db);
            // Orthanc no tiene endpoint directo para cancelar, pero podemos marcarlo como cancelled
        } catch (Exception $e) {
            error_log("Error cancelando job en Orthanc: " . $e->getMessage());
        }
    }
    
    // Marcar como cancelado en BD
    $updateStmt = $db->prepare("
        UPDATE pacs_node_jobs 
        SET status = 'cancelled',
            completed_at = NOW()
        WHERE id = ?
    ");
    
    $updateStmt->execute([$jobId]);

    pacsClonerSyncOrdersFromJobs($db);

    sendSuccessResponse(null, 'Job cancelado exitosamente');
}
