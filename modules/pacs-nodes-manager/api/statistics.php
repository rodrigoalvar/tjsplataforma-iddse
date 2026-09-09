<?php
/**
 * Estadísticas agregadas para el dashboard de PACS Nodes Manager
 *
 * GET /api/pacs-nodes-manager/statistics.php
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../../config/database.php';

try {
    requirePacsNodesAuth('pacs_nodes_manager');

    $db = getDBConnection();
    if (!$db) {
        sendErrorResponse('No se pudo conectar a la base de datos', 500);
    }

    $nodesTotal = 0;
    $nodesActive = 0;
    $queriesToday = 0;
    $retrievesToday = 0;
    $studiesRetrievedToday = 0;
    $byNode = [];

    try {
        $nodesTotal = (int)$db->query("SELECT COUNT(*) FROM pacs_nodes")->fetchColumn();
        $nodesActive = (int)$db->query("SELECT COUNT(*) FROM pacs_nodes WHERE is_active = 1")->fetchColumn();
    } catch (Exception $e) {
        error_log('[PACS_NODES][statistics] nodos: ' . $e->getMessage());
    }

    try {
        $stmt = $db->query("
            SELECT COALESCE(SUM(queries_count), 0) AS q,
                   COALESCE(SUM(retrieves_count), 0) AS r,
                   COALESCE(SUM(studies_retrieved), 0) AS s
            FROM pacs_node_statistics
            WHERE date = CURDATE()
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $queriesToday = (int)$row['q'];
            $retrievesToday = (int)$row['r'];
            $studiesRetrievedToday = (int)$row['s'];
        }
    } catch (Exception $e) {
        error_log('[PACS_NODES][statistics] agregados hoy: ' . $e->getMessage());
    }

    try {
        $sql = "
            SELECT
                n.id,
                n.name,
                n.node_type,
                COALESCE(s.queries_count, 0) AS queries_today,
                COALESCE(s.retrieves_count, 0) AS retrieves_today,
                COALESCE(s.studies_retrieved, 0) AS studies_retrieved_today
            FROM pacs_nodes n
            LEFT JOIN pacs_node_statistics s ON s.node_id = n.id AND s.date = CURDATE()
            ORDER BY n.name ASC
        ";
        $stmt = $db->query($sql);
        $byNode = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('[PACS_NODES][statistics] por nodo: ' . $e->getMessage());
        $byNode = [];
    }

    sendSuccessResponse([
        'nodes_total' => $nodesTotal,
        'nodes_active' => $nodesActive,
        'queries_today' => $queriesToday,
        'retrieves_today' => $retrievesToday,
        'studies_retrieved_today' => $studiesRetrievedToday,
        'by_node' => $byNode,
        'date' => date('Y-m-d'),
    ]);
} catch (Exception $e) {
    error_log('[PACS_NODES][statistics] ' . $e->getMessage());
    sendErrorResponse($e->getMessage(), 500);
}
