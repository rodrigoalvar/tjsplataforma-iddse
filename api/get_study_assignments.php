<?php
/**
 * API para obtener asignaciones de estudios
 * Devuelve información sobre qué usuarios tienen asignados qué estudios
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Incluir configuración de base de datos
require_once '../config/database.php';

try {
    // Crear conexión a la base de datos usando la clase Database
    $pdo = getDBConnection();
    
    // Verificar si la tabla existe, si no, crearla
    $createTableSQL = "
        CREATE TABLE IF NOT EXISTS study_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            study_id VARCHAR(255) NOT NULL,
            user_id INT NOT NULL,
            assigned_by INT NOT NULL,
            assigned_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            status ENUM('active', 'inactive') DEFAULT 'active',
            INDEX idx_study_user (study_id, user_id),
            INDEX idx_user_id (user_id),
            INDEX idx_assigned_by (assigned_by),
            FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            FOREIGN KEY (assigned_by) REFERENCES usuarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    $pdo->exec($createTableSQL);
    
    // Obtener parámetros opcionales
    $studyIds = $_GET['study_ids'] ?? null;
    $userId = $_GET['user_id'] ?? null;
    
    // Construir consulta base
    $sql = "SELECT 
                sa.study_id,
                sa.user_id,
                sa.assigned_by,
                sa.assigned_date,
                sa.status,
                u.nombre as usuario_nombre,
                u.apellido as usuario_apellido,
                u.matricula_profesional,
                asignador.nombre as asignador_nombre,
                asignador.apellido as asignador_apellido
            FROM study_assignments sa
            INNER JOIN usuarios u ON sa.user_id = u.id
            INNER JOIN usuarios asignador ON sa.assigned_by = asignador.id
            WHERE sa.status = 'active'";
    
    $params = [];
    
    // Agregar filtros si se proporcionan
    if ($studyIds) {
        $studyIdArray = explode(',', $studyIds);
        $placeholders = str_repeat('?,', count($studyIdArray) - 1) . '?';
        $sql .= " AND sa.study_id IN ($placeholders)";
        $params = array_merge($params, $studyIdArray);
    }
    
    if ($userId) {
        $sql .= " AND sa.user_id = ?";
        $params[] = $userId;
    }
    
    $sql .= " ORDER BY sa.assigned_date DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Agrupar asignaciones por study_id para facilitar el uso
    $groupedAssignments = [];
    foreach ($assignments as $assignment) {
        $studyId = $assignment['study_id'];
        if (!isset($groupedAssignments[$studyId])) {
            $groupedAssignments[$studyId] = [];
        }
        $groupedAssignments[$studyId][] = $assignment;
    }
    
    // Formatear respuesta
    $response = [
        'success' => true,
        'data' => $assignments,
        'grouped_data' => $groupedAssignments,
        'count' => count($assignments),
        'message' => 'Asignaciones obtenidas exitosamente'
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    // Error de base de datos
    $response = [
        'success' => false,
        'error' => 'Error de base de datos: ' . $e->getMessage(),
        'data' => []
    ];
    
    http_response_code(500);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // Error general
    $response = [
        'success' => false,
        'error' => 'Error interno del servidor: ' . $e->getMessage(),
        'data' => []
    ];
    
    http_response_code(500);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
}
?>
