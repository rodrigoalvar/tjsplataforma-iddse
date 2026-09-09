<?php
/**
 * API para obtener información completa de asignaciones y derivaciones de un estudio
 * Devuelve todas las asignaciones principales y subasignaciones de un estudio
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

try {
    $pdo = getDBConnection();
    
    // Parámetro requerido
    $studyId = $_GET['study_id'] ?? null;
    
    if (!$studyId) {
        throw new Exception('study_id es requerido');
    }
    
    $response = [
        'success' => true,
        'data' => [
            'study_id' => $studyId,
            'main_assignments' => [],
            'subassignments' => []
        ]
    ];
    
    // Obtener asignaciones principales
    $mainSQL = "SELECT 
                    sa.id,
                    sa.user_id as assigned_to,
                    sa.assigned_by,
                    sa.assigned_date,
                    sa.status,
                    u.nombre as user_nombre,
                    u.apellido as user_apellido,
                    u.email as user_email,
                    u.matricula_profesional as user_matricula,
                    asignador.nombre as assigned_by_nombre,
                    asignador.apellido as assigned_by_apellido,
                    asignador.email as assigned_by_email
                FROM study_assignments sa
                LEFT JOIN usuarios u ON sa.user_id = u.id
                LEFT JOIN usuarios asignador ON sa.assigned_by = asignador.id
                WHERE sa.study_id = ? AND sa.status = 'active'
                ORDER BY sa.assigned_date DESC";
    
    $mainStmt = $pdo->prepare($mainSQL);
    $mainStmt->execute([$studyId]);
    $mainAssignments = $mainStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($mainAssignments as &$assignment) {
        $assignment['assigned_date_formatted'] = date('d/m/Y H:i', strtotime($assignment['assigned_date']));
    }
    
    $response['data']['main_assignments'] = $mainAssignments;
    
    // Obtener subasignaciones para cada asignación principal
    foreach ($mainAssignments as $mainAssignment) {
        $subSQL = "SELECT 
                        ss.id,
                        ss.subassigned_to_user_id,
                        ss.assigned_by_user_id,
                        ss.subassigned_at,
                        ss.status,
                        u.nombre as user_nombre,
                        u.apellido as user_apellido,
                        u.email as user_email,
                        u.matricula_profesional as user_matricula,
                        asignador.nombre as assigned_by_nombre,
                        asignador.apellido as assigned_by_apellido,
                        asignador.email as assigned_by_email
                    FROM study_subassignments ss
                    LEFT JOIN usuarios u ON ss.subassigned_to_user_id = u.id
                    LEFT JOIN usuarios asignador ON ss.assigned_by_user_id = asignador.id
                    WHERE ss.study_id = ? AND ss.main_user_id = ? AND ss.status = 'active'
                    ORDER BY ss.subassigned_at DESC";
        
        $subStmt = $pdo->prepare($subSQL);
        $subStmt->execute([$studyId, $mainAssignment['assigned_to']]);
        $subassignments = $subStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($subassignments as &$sub) {
            $sub['subassigned_at_formatted'] = date('d/m/Y H:i', strtotime($sub['subassigned_at']));
        }
        
        $response['data']['subassignments'][] = [
            'main_assignment_id' => $mainAssignment['id'],
            'main_user' => [
                'id' => $mainAssignment['assigned_to'],
                'nombre' => $mainAssignment['user_nombre'],
                'apellido' => $mainAssignment['user_apellido'],
                'email' => $mainAssignment['user_email'],
                'matricula' => $mainAssignment['user_matricula']
            ],
            'derivations' => $subassignments
        ];
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>

