<?php
/**
 * API para obtener subasignaciones de estudios
 * Devuelve información sobre derivaciones realizadas desde cuentas principales
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

try {
    $pdo = getDBConnection();
    
    // Parámetros opcionales
    $studyId = $_GET['study_id'] ?? null;
    $mainUserId = $_GET['main_user_id'] ?? null;
    $subassignedToUserId = $_GET['subassigned_to_user_id'] ?? null;
    $status = $_GET['status'] ?? 'active';
    
    // Construir consulta base
    $sql = "SELECT 
                ss.id,
                ss.study_id,
                ss.main_user_id,
                ss.subassigned_to_user_id,
                ss.assigned_by_user_id,
                ss.subassigned_at,
                ss.status,
                -- Información del usuario principal
                u_main.nombre as main_user_nombre,
                u_main.apellido as main_user_apellido,
                u_main.email as main_user_email,
                u_main.matricula_profesional as main_user_matricula,
                -- Información del usuario hijo
                u_hijo.nombre as subassigned_user_nombre,
                u_hijo.apellido as subassigned_user_apellido,
                u_hijo.email as subassigned_user_email,
                u_hijo.matricula_profesional as subassigned_user_matricula,
                -- Información del asignador
                u_asignador.nombre as asignador_nombre,
                u_asignador.apellido as asignador_apellido,
                u_asignador.email as asignador_email
            FROM study_subassignments ss
            LEFT JOIN usuarios u_main ON ss.main_user_id = u_main.id
            LEFT JOIN usuarios u_hijo ON ss.subassigned_to_user_id = u_hijo.id
            LEFT JOIN usuarios u_asignador ON ss.assigned_by_user_id = u_asignador.id
            WHERE 1=1";
    
    $params = [];
    
    if ($studyId) {
        $sql .= " AND ss.study_id = ?";
        $params[] = $studyId;
    }
    
    if ($mainUserId) {
        $sql .= " AND ss.main_user_id = ?";
        $params[] = $mainUserId;
    }
    
    if ($subassignedToUserId) {
        $sql .= " AND ss.subassigned_to_user_id = ?";
        $params[] = $subassignedToUserId;
    }
    
    if ($status) {
        $sql .= " AND ss.status = ?";
        $params[] = $status;
    }
    
    $sql .= " ORDER BY ss.subassigned_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $subassignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatear respuestas
    foreach ($subassignments as &$sub) {
        $sub['subassigned_at_formatted'] = date('d/m/Y H:i', strtotime($sub['subassigned_at']));
    }
    
    $response = [
        'success' => true,
        'data' => [
            'subassignments' => $subassignments,
            'total' => count($subassignments)
        ]
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>

