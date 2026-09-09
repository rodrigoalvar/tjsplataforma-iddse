<?php
/**
 * API Endpoint para listar informes PDF recibidos
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../../../config/database.php';

try {
    $db = getDBConnection();
    
    // Parámetros de paginación y filtros
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $offset = ($page - 1) * $limit;
    
    $estado = $_GET['estado'] ?? null;
    $accessionNumber = $_GET['accession_number'] ?? null;
    $search = $_GET['search'] ?? null;
    
    // Construir query con filtros
    $where = [];
    $params = [];
    
    if ($estado) {
        $where[] = "ir.estado = ?";
        $params[] = $estado;
    }
    
    if ($accessionNumber) {
        $where[] = "ir.accession_number LIKE ?";
        $params[] = '%' . $accessionNumber . '%';
    }
    
    if ($search) {
        $where[] = "(ir.patient_name LIKE ? OR ir.patient_id LIKE ? OR ir.accession_number LIKE ?)";
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }
    
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Query para obtener total
    $countQuery = "SELECT COUNT(*) as total FROM informes_recibidos ir $whereClause";
    $countStmt = $db->prepare($countQuery);
    $countStmt->execute($params);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Detectar columnas opcionales para compatibilidad entre instalaciones
    $columnsStmt = $db->query("SHOW COLUMNS FROM informes_recibidos");
    $columns = $columnsStmt->fetchAll(PDO::FETCH_COLUMN);
    $hasInformeId = in_array('informe_id', $columns, true);
    $hasMetodoVinculacion = in_array('metodo_vinculacion', $columns, true);
    $hasVinculadoPorUsuario = in_array('vinculado_por_usuario_id', $columns, true);
    $hasMotivoDescarte = in_array('motivo_descarte', $columns, true);
    $hasDescartadoPorUsuario = in_array('descartado_por_usuario_id', $columns, true);
    $hasFechaDescarte = in_array('fecha_descarte', $columns, true);
    $hasAutoPacsEstado = in_array('auto_pacs_estado', $columns, true);

    $joinInforme = '';
    $selectInformePacs = ', NULL AS pacs_instance_id, NULL AS pacs_study_id, NULL AS pacs_series_id, NULL AS fecha_enviado_pacs';
    if ($hasInformeId) {
        $infColsStmt = $db->query('SHOW COLUMNS FROM informes');
        $infColumns = $infColsStmt ? $infColsStmt->fetchAll(PDO::FETCH_COLUMN) : [];
        $pacsInformeFields = ['pacs_instance_id', 'pacs_study_id', 'pacs_series_id', 'fecha_enviado_pacs'];
        $pacsSelectParts = [];
        foreach ($pacsInformeFields as $col) {
            if (in_array($col, $infColumns, true)) {
                $pacsSelectParts[] = 'ir_inf.' . $col;
            } else {
                $pacsSelectParts[] = 'NULL AS ' . $col;
            }
        }
        $selectInformePacs = ', ' . implode(', ', $pacsSelectParts);
        $joinInforme = ' LEFT JOIN informes ir_inf ON ir_inf.id = ir.informe_id ';
    }

    // Query para obtener datos
    $query = "SELECT 
                ir.id,
                ir.accession_number,
                ir.pdf_path,
                ir.txt_path,
                ir.estudio_id,
                " . ($hasInformeId ? "ir.informe_id" : "NULL AS informe_id") . ",
                ir.patient_name,
                ir.patient_id,
                ir.patient_birth_date,
                ir.patient_sex,
                ir.modality,
                ir.referring_physician,
                ir.equipment_name,
                ir.procedure_date,
                ir.procedure_time,
                ir.procedure_description,
                ir.reason_for_study,
                ir.estado,
                " . ($hasMetodoVinculacion ? "ir.metodo_vinculacion" : "NULL AS metodo_vinculacion") . ",
                " . ($hasVinculadoPorUsuario ? "ir.vinculado_por_usuario_id" : "NULL AS vinculado_por_usuario_id") . ",
                " . ($hasMotivoDescarte ? "ir.motivo_descarte" : "NULL AS motivo_descarte") . ",
                " . ($hasDescartadoPorUsuario ? "ir.descartado_por_usuario_id" : "NULL AS descartado_por_usuario_id") . ",
                " . ($hasFechaDescarte ? "ir.fecha_descarte" : "NULL AS fecha_descarte") . ",
                " . ($hasAutoPacsEstado ? "ir.auto_pacs_estado" : "NULL AS auto_pacs_estado") . ",
                " . ($hasAutoPacsEstado ? "ir.auto_pacs_error" : "NULL AS auto_pacs_error") . ",
                " . ($hasAutoPacsEstado ? "ir.auto_pacs_last_try_at" : "NULL AS auto_pacs_last_try_at") . ",
                " . ($hasAutoPacsEstado ? "ir.auto_pacs_try_count" : "NULL AS auto_pacs_try_count") . ",
                ir.error_message,
                ir.fecha_recepcion,
                ir.fecha_procesamiento,
                ir.fecha_vinculacion,
                e.study_description as estudio_descripcion,
                e.patient_name_pacs as estudio_patient_name
                $selectInformePacs
              FROM informes_recibidos ir
              $joinInforme
              LEFT JOIN estudios e ON ir.estudio_id = e.id
              $whereClause
              ORDER BY ir.fecha_recepcion DESC
              LIMIT ? OFFSET ?";
    
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $informes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatear fechas
    foreach ($informes as &$informe) {
        if ($informe['fecha_recepcion']) {
            $informe['fecha_recepcion_formatted'] = date('d/m/Y H:i:s', strtotime($informe['fecha_recepcion']));
        }
        if ($informe['fecha_vinculacion']) {
            $informe['fecha_vinculacion_formatted'] = date('d/m/Y H:i:s', strtotime($informe['fecha_vinculacion']));
        }
        if ($informe['procedure_date']) {
            $informe['procedure_date_formatted'] = date('d/m/Y', strtotime($informe['procedure_date']));
        }
        if (!empty($informe['fecha_descarte'])) {
            $informe['fecha_descarte_formatted'] = date('d/m/Y H:i:s', strtotime($informe['fecha_descarte']));
        }

        // Estado auto-PACS efectivo: si el informe vinculado ya tiene referencias PACS,
        // normalizar a "enviado" para evitar desincronías de tracking en informes_recibidos.
        $hasPacsEvidence = false;
        foreach (['pacs_instance_id', 'pacs_series_id', 'pacs_study_id', 'fecha_enviado_pacs'] as $pacsField) {
            if (isset($informe[$pacsField]) && trim((string)$informe[$pacsField]) !== '') {
                $hasPacsEvidence = true;
                break;
            }
        }
        if ($hasPacsEvidence) {
            $informe['auto_pacs_estado'] = 'enviado';
            $informe['auto_pacs_error'] = null;
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => $informes,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => (int)$total,
            'pages' => ceil($total / $limit)
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
